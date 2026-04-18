<?php

declare(strict_types=1);

namespace PhilipAnnis\LaravelAgentGuard;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Dotenv\Dotenv;
use RuntimeException;
use Throwable;

/**
 * Import environment values into the macOS login keychain after composer events.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const HEADER = <<<'HEADER'
     ▗▄▖  ▗▄▄▖▗▄▄▄▖▗▖  ▗▖▗▄▄▄▖     ▗▄▄▖▗▖ ▗▖ ▗▄▖ ▗▄▄▖ ▗▄▄▄ 
    ▐▌ ▐▌▐▌   ▐▌   ▐▛▚▖▐▌  █      ▐▌   ▐▌ ▐▌▐▌ ▐▌▐▌ ▐▌▐▌  █
    ▐▛▀▜▌▐▌▝▜▌▐▛▀▀▘▐▌ ▝▜▌  █      ▐▌▝▜▌▐▌ ▐▌▐▛▀▜▌▐▛▀▚▖▐▌  █
    ▐▌ ▐▌▝▚▄▞▘▐▙▄▄▖▐▌  ▐▌  █      ▝▚▄▞▘▝▚▄▞▘▐▌ ▐▌▐▌ ▐▌▐▙▄▄▀
    HEADER;

    private static bool $hasImported = false;

    /**
     * Activate the composer plugin.
     *
     * @param Composer $composer The active composer instance.
     * @param IOInterface $inputOutput The composer input/output handler.
     *
     * @return void
     */
    public function activate(Composer $composer, IOInterface $inputOutput): void {}

    /**
     * Deactivate the composer plugin.
     *
     * @param Composer $composer The active composer instance.
     * @param IOInterface $inputOutput The composer input/output handler.
     *
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $inputOutput): void {}

    /**
     * Uninstall the composer plugin.
     *
     * @param Composer $composer The active composer instance.
     * @param IOInterface $inputOutput The composer input/output handler.
     *
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $inputOutput): void {}

    /**
     * Return the composer script event subscriptions.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'import',
            ScriptEvents::POST_UPDATE_CMD => 'import',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'import',
        ];
    }

    /**
     * Import project environment values into the macOS login keychain.
     *
     * @param Event $scriptEvent The composer script event.
     *
     * @return void
     * @throws RuntimeException If the environment file cannot be parsed or the keychain cannot be updated.
     */
    public function import(Event $scriptEvent): void
    {
        // Stop when this process already finished an import.
        if (self::$hasImported) {

            return;
        }

        // Record that the import already ran in this process.
        self::$hasImported = true;

        // Stop on unsupported platforms.
        if (!$this->isSupportedPlatform()) {

            return;
        }

        // Resolve the current project keychain manager and environment file path.
        $projectRootDirectory = $this->resolveProjectRootDirectory($scriptEvent);
        $projectKeychain = new ProjectKeychain($projectRootDirectory);
        $environmentFilePath = $projectRootDirectory . DIRECTORY_SEPARATOR . '.env';
        $environmentFileContents = $this->readEnvironmentFileContents($environmentFilePath);
        $hasManagedEnvironmentValues = $projectKeychain->hasManagedEnvironmentValues();

        // Recreate a blank environment file when managed values already exist.
        if ($environmentFileContents === null && $hasManagedEnvironmentValues) {

            // Ensure the project still has a placeholder environment file.
            $this->ensurePlaceholderEnvironmentFileExists($environmentFilePath);
        }

        // Stop when the environment file is missing and no managed values exist.
        if ($environmentFileContents === null && !$hasManagedEnvironmentValues) {

            return;
        }

        // Track whether this run is creating the first managed values.
        $isFirstImport = !$hasManagedEnvironmentValues;

        // Show the package header before other output.
        $this->notifyUserHeader($scriptEvent);

        // Persist the current environment file values when the file is not empty.
        if ($environmentFileContents !== null) {

            // Parse the environment file into key-value pairs.
            $environmentVariables = $this->parseEnvironmentVariables($environmentFileContents);

            // Persist each environment value in the login keychain.
            foreach ($environmentVariables as $environmentKey => $environmentValue) {

                // Store the current environment value in the keychain.
                $projectKeychain->storeEnvironmentValue($environmentKey, $environmentValue);
            }
        }

        // Rewrite every managed value with the current item settings.
        $projectKeychain->rewriteManagedEnvironmentValues();

        // Clear cached laravel config values after import.
        $this->clearConfigCache($scriptEvent, $projectRootDirectory);

        // Show the user where the imported values were stored.
        $this->notifyUserOfKeychain($scriptEvent, $projectKeychain->projectItemPrefix(), $projectKeychain->loginKeychainPath());

        // Open keychain access on the first successful import from the environment file.
        if ($isFirstImport && $environmentFileContents !== null) {

            try {

                // Call keychain access for the imported values.
                $projectKeychain->openInKeychainAccess();

            } catch (Throwable) {

                // Ignore the failure to open keychain access.
            }
        }

        // Tell the user what to do with the source environment file when it exists.
        if ($environmentFileContents !== null) {

            // Tell the user what to do with the source environment file.
            $this->notifyUserToReviewEnvironmentFile($scriptEvent, $environmentFilePath);
        }
    }

    /**
     * Return whether the current platform is supported.
     *
     * @return bool
     */
    private function isSupportedPlatform(): bool
    {
        // Detect whether the current platform is supported.
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * Resolve the consuming laravel project root directory.
     *
     * @param Event $scriptEvent The composer script event.
     *
     * @return string
     */
    private function resolveProjectRootDirectory(Event $scriptEvent): string
    {
        // Resolve the laravel project root from composer.
        return dirname((string) $scriptEvent->getComposer()->getConfig()->get('vendor-dir'));
    }

    /**
     * Read the environment file contents.
     *
     * @param string $environmentFilePath The environment file path.
     *
     * @return string|null
     */
    private function readEnvironmentFileContents(string $environmentFilePath): ?string
    {
        // Stop when the environment file does not exist.
        if (!is_file($environmentFilePath)) {

            return null;
        }

        // Read the environment file from disk.
        $environmentFileContents = file_get_contents($environmentFilePath);

        // Stop when the environment file is unreadable.
        if (!is_string($environmentFileContents)) {

            return null;
        }

        // Stop when the environment file is empty.
        if (trim($environmentFileContents) === '') {

            return null;
        }

        // Return the loaded environment file contents.
        return $environmentFileContents;
    }

    /**
     * Ensure the project has a blank placeholder environment file.
     *
     * @param string $environmentFilePath The environment file path.
     *
     * @return void
     * @throws RuntimeException If the placeholder environment file cannot be created.
     */
    private function ensurePlaceholderEnvironmentFileExists(string $environmentFilePath): void
    {
        // Stop when the placeholder environment file already exists.
        if (is_file($environmentFilePath)) {

            return;
        }

        // Create the blank placeholder environment file.
        $bytesWritten = file_put_contents($environmentFilePath, '');

        // Stop when the placeholder environment file could not be created.
        if ($bytesWritten === false) {

            // Surface the placeholder environment file failure.
            throw new RuntimeException('Failed creating the placeholder environment file.');
        }
    }

    /**
     * Parse the environment file into a key-value map.
     *
     * @param string $environmentFileContents The environment file contents.
     *
     * @return array<string, string>
     * @throws RuntimeException If the environment file cannot be parsed.
     */
    private function parseEnvironmentVariables(string $environmentFileContents): array
    {
        // Parse the environment file contents into key-value pairs.
        try {

            // Call dotenv to parse the environment file contents.
            $parsedEnvironmentVariables = Dotenv::parse($environmentFileContents);

        } catch (Throwable $throwable) {

            // Surface the parse failure as a runtime exception.
            throw new RuntimeException(PHP_EOL . $this->formatError('Failed parsing the environment file.') . PHP_EOL, 0, $throwable);
        }

        // Prepare the normalized environment map.
        $normalizedEnvironmentVariables = [];

        // Cast every parsed key and value into strings.
        foreach ($parsedEnvironmentVariables as $environmentKey => $environmentValue) {

            // Store the normalized key-value pair.
            $normalizedEnvironmentVariables[(string) $environmentKey] = (string) $environmentValue;
        }

        // Return the normalized environment map.
        return $normalizedEnvironmentVariables;
    }

    /**
     * Write the package header to the composer output.
     *
     * @param Event $scriptEvent The composer script event.
     *
     * @return void
     */
    private function notifyUserHeader(Event $scriptEvent): void
    {
        // Write the package header to composer output.
        $scriptEvent->getIO()->write(PHP_EOL . '<info>' . self::HEADER . '</info>' . PHP_EOL);
    }

    /**
     * Tell the user to review the environment file manually.
     *
     * @param Event $scriptEvent The composer script event.
     * @param string $environmentFilePath The environment file path.
     *
     * @return void
     */
    private function notifyUserToReviewEnvironmentFile(Event $scriptEvent, string $environmentFilePath): void
    {
        // Build the follow-up message for the source environment file.
        $manualReviewMessage = sprintf(
            $this->formatInfo('Your environment values were successfully imported into the login keychain.') . PHP_EOL
            . $this->formatWarning('Backup and delete %s manually when you are ready.') . PHP_EOL
            . $this->formatInfo('Your local project will securely load project-prefixed values from the login keychain.') . PHP_EOL,
            $environmentFilePath,
        );

        // Write the follow-up message to composer output.
        $scriptEvent->getIO()->write($manualReviewMessage . PHP_EOL);
    }

    /**
     * Tell the user which keychain stores the environment values.
     *
     * @param Event $scriptEvent The composer script event.
     * @param string $projectItemPrefix The project item prefix.
     * @param string $keychainPath The login keychain path.
     *
     * @return void
     */
    private function notifyUserOfKeychain(Event $scriptEvent, string $projectItemPrefix, string $keychainPath): void
    {
        // Build the keychain location message.
        $message = '<info>laravel-agent-guard</info>: The login keychain at <options=bold>%s</> stores this project under the <options=bold>%s_*</> prefix.';

        // Write the keychain location message to composer output.
        $scriptEvent->getIO()->write(sprintf($message, $keychainPath, $projectItemPrefix));
    }

    /**
     * Format a user-facing error line.
     *
     * @param string $message The message body.
     *
     * @return string
     */
    private function formatError(string $message): string
    {
        // Prefix the message as an error line.
        return '<error>laravel-agent-guard: ' . $message . '</error>';
    }

    /**
     * Format a user-facing warning line.
     *
     * @param string $message The message body.
     *
     * @return string
     */
    private function formatWarning(string $message): string
    {
        // Prefix the message as a warning line.
        return '<warning>laravel-agent-guard: ' . $message . '</warning>';
    }

    /**
     * Format a user-facing info line.
     *
     * @param string $message The message body.
     *
     * @return string
     */
    private function formatInfo(string $message): string
    {
        // Prefix the message as an info line.
        return '<info>laravel-agent-guard</info>: ' . $message;
    }

    /**
     * Try to clear the laravel config cache.
     *
     * @param Event $scriptEvent The composer script event.
     * @param string $projectRootDirectory The project root directory.
     *
     * @return void
     */
    private function clearConfigCache(Event $scriptEvent, string $projectRootDirectory): void
    {
        // Resolve the artisan path for the current project.
        $artisanPath = $projectRootDirectory . DIRECTORY_SEPARATOR . 'artisan';

        // Stop when artisan is unavailable.
        if (!is_file($artisanPath)) {

            return;
        }

        // Build the config clear command for the current project.
        $command = sprintf('cd %s && %s artisan config:clear >/dev/null 2>&1', escapeshellarg($projectRootDirectory), $this->resolvePHPBinary());

        // Warn when the config cache could not be cleared.
        if (!$this->runCommand($command, '', false)) {

            // Report that the config cache could not be cleared.
            $scriptEvent->getIO()->write($this->formatWarning('Failed to clear the previously cached environment values.'));

            // Stop after reporting the config cache failure.
            return;
        }

        // Confirm that the config cache was cleared.
        $scriptEvent->getIO()->write($this->formatInfo('Cleared the previously cached environment values to prevent leaking secrets.'));
    }

    /**
     * Resolve the current PHP binary.
     *
     * @return string
     */
    private function resolvePHPBinary(): string
    {
        // Return the current PHP binary when it is available.
        if (PHP_BINARY !== '') {

            return escapeshellarg(PHP_BINARY);
        }

        // Fall back to the default PHP binary name.
        return 'php';
    }

    /**
     * Run a shell command and surface failures.
     *
     * @param string $command The shell command to execute.
     * @param string $failureMessage The exception message for command failures.
     * @param bool $shouldThrow Whether to throw when the command fails.
     *
     * @return bool
     * @throws RuntimeException If the command fails and throwing is enabled.
     */
    private function runCommand(string $command, string $failureMessage, bool $shouldThrow = true): bool
    {
        // Execute the shell command and capture its exit code.
        $commandOutputLines = [];
        $exitCode = 0;

        // Run the shell command.
        exec($command, $commandOutputLines, $exitCode);

        // Handle failed commands using the requested error mode.
        if ($exitCode !== 0) {

            // Return a failure flag when throwing is disabled.
            if (!$shouldThrow) {

                return false;
            }

            // Surface the shell command failure.
            throw new RuntimeException($failureMessage);
        }

        // Report that the command completed successfully.
        return true;
    }
}