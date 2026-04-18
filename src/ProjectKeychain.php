<?php

declare(strict_types=1);

namespace PhilipAnnis\LaravelAgentGuard;

use RuntimeException;

/**
 * Store and read a project's environment values from the macOS login keychain.
 */
final class ProjectKeychain
{
    private const ACCOUNT_NAME = 'laravel-agent-guard';
    private const ENVIRONMENT_ITEM_KIND = 'application password';
    private const GENERIC_PASSWORD_ITEM_CLASS = 'genp';
    private const KEYCHAIN_ACCESS_APPLICATION = 'keychain access';
    private const LOGIN_KEYCHAIN_FILENAME = 'login.keychain-db';
    private const SECURITY_APPLICATION_PATH = '/usr/bin/security';

    /**
     * Create a new project keychain manager.
     *
     * @param string $projectRootDirectory The consuming project root directory.
     *
     * @return void
     */
    public function __construct(private readonly string $projectRootDirectory) {}

    /**
     * Return the project slug used for keychain item names.
     *
     * @return string
     * @throws RuntimeException If the project name cannot be slugged.
     */
    public function projectSlug(): string
    {
        // Build the slug from the project directory name.
        return $this->slugProjectName(basename($this->projectRootDirectory));
    }

    /**
     * Return the prefix used by this project's login keychain items.
     *
     * @return string
     * @throws RuntimeException If the project slug cannot be resolved.
     */
    public function projectItemPrefix(): string
    {
        // Convert the project slug into the managed item prefix.
        return strtoupper(str_replace('-', '_', $this->projectSlug()));
    }

    /**
     * Return the current user's login keychain path.
     *
     * @return string
     * @throws RuntimeException If the home directory cannot be resolved.
     */
    public function loginKeychainPath(): string
    {
        // Build the login keychain path from the user home directory.
        return $this->userHomeDirectory() . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'Keychains' . DIRECTORY_SEPARATOR . self::LOGIN_KEYCHAIN_FILENAME;
    }

    /**
     * Return whether this project already has managed environment values.
     *
     * @return bool
     * @throws RuntimeException If the login keychain cannot be listed.
     */
    public function hasManagedEnvironmentValues(): bool
    {
        // Detect whether any managed keys already exist.
        return $this->listEnvironmentKeys() !== [];
    }

    /**
     * Read the current managed environment values from the login keychain.
     *
     * @return array<string, string>
     * @throws RuntimeException If the login keychain cannot be read.
     */
    public function readEnvironmentValues(): array
    {
        // Prepare the environment value map.
        $environmentValues = [];

        // Load each discovered key from the login keychain.
        foreach ($this->listEnvironmentKeys() as $environmentKey) {

            // Read the current managed value for the discovered key.
            $environmentValue = $this->environmentValue($environmentKey);

            // Skip the key when the managed value was not found.
            if ($environmentValue === null) {

                continue;
            }

            // Store the discovered managed value in the result map.
            $environmentValues[$environmentKey] = $environmentValue;
        }

        // Return the discovered environment values.
        return $environmentValues;
    }

    /**
     * Return the managed environment keys stored in the login keychain.
     *
     * @return array<int, string>
     * @throws RuntimeException If the login keychain cannot be listed.
     */
    public function listEnvironmentKeys(): array
    {
        // Prepare the variables used to dump the login keychain.
        $dumpOutputLines = [];
        $dumpExitCode = 0;

        // Dump the login keychain contents for parsing.
        exec(sprintf('security dump-keychain %s 2>/dev/null', escapeshellarg($this->loginKeychainPath())), $dumpOutputLines, $dumpExitCode);

        // Stop when the login keychain dump fails.
        if ($dumpExitCode !== 0) {

            // Surface the keychain dump failure.
            throw new RuntimeException('Failed listing the login keychain contents.');
        }

        // Prepare the item parser state.
        $environmentKeys = [];
        $currentItemClass = null;
        $currentLabel = null;
        $currentService = null;
        $currentAccount = null;

        // Parse each dumped item and keep only this project's managed item names.
        foreach ($dumpOutputLines as $dumpOutputLine) {

            // Skip the dump line when it is not a string.
            if (!is_string($dumpOutputLine)) {

                continue;
            }

            // Detect the start of a new dumped keychain item.
            if (preg_match('/^class:\s+"([^"]+)"/', $dumpOutputLine, $classMatches) === 1) {

                // Save the previous item before tracking the next one.
                $this->appendManagedEnvironmentKey($environmentKeys, $currentItemClass, $currentAccount, $currentLabel, $currentService);
                $currentItemClass = $classMatches[1];
                $currentLabel = null;
                $currentService = null;
                $currentAccount = null;

                // Continue scanning the next dumped item.
                continue;
            }

            // Detect the account field in the current dumped item.
            if (preg_match('/"acct"<[^>]+>="([^"]*)"/', $dumpOutputLine, $accountMatches) === 1) {

                // Capture the current item account.
                $currentAccount = stripcslashes($accountMatches[1]);

                // Continue scanning the current dumped item.
                continue;
            }

            // Detect the service field in the current dumped item.
            if (preg_match('/"svce"<[^>]+>="([^"]*)"/', $dumpOutputLine, $serviceMatches) === 1) {

                // Capture the current item service name.
                $currentService = stripcslashes($serviceMatches[1]);

                // Continue scanning the current dumped item.
                continue;
            }

            // Detect the label field in the current dumped item.
            if (preg_match('/"labl"<[^>]+>="([^"]*)"/', $dumpOutputLine, $labelMatches) === 1) {

                // Capture the current item label.
                $currentLabel = stripcslashes($labelMatches[1]);
            }
        }

        // Normalize the discovered key list before returning it.
        $this->appendManagedEnvironmentKey($environmentKeys, $currentItemClass, $currentAccount, $currentLabel, $currentService);
        $environmentKeys = array_values(array_unique($environmentKeys));
        sort($environmentKeys);

        // Return the discovered managed keys.
        return $environmentKeys;
    }

    /**
     * Open the login keychain in keychain access.
     *
     * @return void
     * @throws RuntimeException If keychain access cannot be opened.
     */
    public function openInKeychainAccess(): void
    {
        // Open the login keychain in keychain access.
        $this->runCommand(
            sprintf('open -a %s %s >/dev/null 2>&1', escapeshellarg(self::KEYCHAIN_ACCESS_APPLICATION), escapeshellarg($this->loginKeychainPath())),
            'Failed opening the keychain.',
        );
    }

    /**
     * Persist a single environment value in the login keychain.
     *
     * @param string $environmentKey The environment variable name.
     * @param string $environmentValue The environment variable value.
     *
     * @return void
     * @throws RuntimeException If the value cannot be normalized or stored.
     */
    public function storeEnvironmentValue(string $environmentKey, string $environmentValue): void
    {
        // Normalize the key and prepare the managed item label.
        $environmentKey = $this->normalizeEnvironmentKey($environmentKey);
        $environmentValue = rtrim($environmentValue, "\r\n");
        $itemLabel = $this->environmentItemLabel($environmentKey);

        // Delete any previous managed value for this key.
        $this->deleteEnvironmentValue($environmentKey, false);

        // Store the current environment value in the login keychain.
        $this->runCommand(
            sprintf(
                'security add-generic-password -a %s -s %s -l %s -D %s -T %s -w %s %s >/dev/null 2>&1',
                escapeshellarg(self::ACCOUNT_NAME),
                escapeshellarg($itemLabel),
                escapeshellarg($itemLabel),
                escapeshellarg(self::ENVIRONMENT_ITEM_KIND),
                escapeshellarg(self::SECURITY_APPLICATION_PATH),
                escapeshellarg($environmentValue),
                escapeshellarg($this->loginKeychainPath()),
            ),
            sprintf('Failed storing "%s" in the login keychain.', $environmentKey),
        );
    }

    /**
     * Rewrite every discovered managed value using the current item settings.
     *
     * @return void
     * @throws RuntimeException If a managed value cannot be read or stored.
     */
    public function rewriteManagedEnvironmentValues(): void
    {
        // Read every discovered managed value from the login keychain.
        foreach ($this->readEnvironmentValues() as $environmentKey => $environmentValue) {

            // Store the discovered managed value with the current item settings.
            $this->storeEnvironmentValue($environmentKey, $environmentValue);
        }
    }

    /**
     * Append a managed environment key when the tracked item matches.
     *
     * @param array<int, string> $environmentKeys The tracked environment keys.
     * @param string|null $itemClass The tracked keychain item class.
     * @param string|null $account The tracked keychain account.
     * @param string|null $label The tracked keychain label.
     * @param string|null $service The tracked keychain service name.
     *
     * @return void
     * @throws RuntimeException If the environment key cannot be normalized.
     */
    private function appendManagedEnvironmentKey(array &$environmentKeys, ?string $itemClass, ?string $account, ?string $label, ?string $service): void
    {
        // Stop when the tracked item is not a managed environment entry.
        if ($itemClass !== self::GENERIC_PASSWORD_ITEM_CLASS || $account !== self::ACCOUNT_NAME) {

            // Stop because the tracked item is not managed by this package.
            return;
        }

        // Extract the managed environment key from the item label.
        $environmentKey = $this->environmentKeyFromItemName($label);

        // Read the managed environment key from the item service name.
        if ($environmentKey === null) {

            // Extract the managed environment key from the item service name.
            $environmentKey = $this->environmentKeyFromItemName($service);
        }

        // Stop when the tracked item name is not a managed environment key.
        if ($environmentKey === null) {

            // Stop because the item name does not contain a managed key.
            return;
        }

        // Append the managed environment key to the result list.
        $environmentKeys[] = $environmentKey;
    }

    /**
     * Return a single environment value from the login keychain.
     *
     * @param string $environmentKey The environment variable name.
     *
     * @return string|null
     * @throws RuntimeException If the environment key cannot be normalized.
     */
    private function environmentValue(string $environmentKey): ?string
    {
        // Build the normalized keychain lookup values.
        $environmentKey = $this->normalizeEnvironmentKey($environmentKey);
        $itemLabel = $this->environmentItemLabel($environmentKey);

        // Read the managed value from the login keychain label.
        $environmentValue = $this->findEnvironmentValue($itemLabel, '-l');

        // Read the managed value from the login keychain service name.
        if ($environmentValue === null) {

            // Read the managed value from the login keychain service name.
            $environmentValue = $this->findEnvironmentValue($itemLabel, '-s');
        }

        // Stop when the managed value cannot be read.
        if ($environmentValue === null) {

            return null;
        }

        // Return the managed value.
        return $environmentValue;
    }

    /**
     * Remove a managed environment value when it already exists.
     *
     * @param string $environmentKey The environment variable name.
     * @param bool $shouldThrow Whether to throw when the delete fails.
     *
     * @return bool
     * @throws RuntimeException If the environment key cannot be normalized.
     */
    private function deleteEnvironmentValue(string $environmentKey, bool $shouldThrow = true): bool
    {
        // Normalize the key before deleting its managed item.
        $environmentKey = $this->normalizeEnvironmentKey($environmentKey);

        // Delete the managed item from the login keychain label.
        $wasDeleted = $this->runCommand(sprintf(
            'security delete-generic-password -a %s -l %s %s >/dev/null 2>&1',
            escapeshellarg(self::ACCOUNT_NAME),
            escapeshellarg($this->environmentItemLabel($environmentKey)),
            escapeshellarg($this->loginKeychainPath()),
        ), sprintf('Failed deleting "%s" from the login keychain.', $environmentKey), false);

        // Return when the managed item was deleted by label.
        if ($wasDeleted) {

            return true;
        }

        // Delete the managed item from the login keychain service name.
        return $this->runCommand(sprintf(
            'security delete-generic-password -a %s -s %s %s >/dev/null 2>&1',
            escapeshellarg(self::ACCOUNT_NAME),
            escapeshellarg($this->environmentItemLabel($environmentKey)),
            escapeshellarg($this->loginKeychainPath()),
        ), sprintf('Failed deleting "%s" from the login keychain.', $environmentKey), $shouldThrow);
    }

    /**
     * Build the full login keychain item label for an environment key.
     *
     * @param string $environmentKey The normalized environment variable name.
     *
     * @return string
     * @throws RuntimeException If the environment key cannot be normalized.
     */
    private function environmentItemLabel(string $environmentKey): string
    {
        // Build the managed item label for the environment key.
        return $this->projectItemPrefix() . '_' . $this->normalizeEnvironmentKey($environmentKey);
    }

    /**
     * Return the environment variable name from a managed keychain item name.
     *
     * @param string|null $itemName The login keychain item name.
     *
     * @return string|null
     * @throws RuntimeException If the extracted environment key is invalid.
     */
    private function environmentKeyFromItemName(?string $itemName): ?string
    {
        // Build the managed item prefix for this project.
        $itemPrefix = $this->projectItemPrefix() . '_';

        // Stop when the item name is missing.
        if (!is_string($itemName) || $itemName === '') {

            return null;
        }

        // Stop when the item name does not belong to this project.
        if (!str_starts_with($itemName, $itemPrefix)) {

            return null;
        }

        // Extract the environment key from the managed item prefix.
        $environmentKey = substr($itemName, strlen($itemPrefix));

        // Stop when the extracted environment key is empty.
        if (!is_string($environmentKey) || $environmentKey === '') {

            return null;
        }

        // Return the normalized environment key.
        return $this->normalizeEnvironmentKey($environmentKey);
    }

    /**
     * Normalize a managed environment key for storage and lookup.
     *
     * @param string $environmentKey The environment variable name.
     *
     * @return string
     * @throws RuntimeException If the key does not contain an alphanumeric character.
     */
    private function normalizeEnvironmentKey(string $environmentKey): string
    {
        // Convert the environment key into the managed label format.
        $environmentKey = strtoupper(trim($environmentKey));
        $environmentKey = (string) preg_replace('/[^A-Z0-9]+/', '_', $environmentKey);
        $environmentKey = trim($environmentKey, '_');

        // Stop when the normalized key is empty.
        if ($environmentKey === '') {

            // Surface the invalid environment key.
            throw new RuntimeException('The environment key must contain at least one alphanumeric character.');
        }

        // Return the normalized environment key.
        return $environmentKey;
    }

    /**
     * Return the current user home directory.
     *
     * @return string
     * @throws RuntimeException If the home directory cannot be resolved.
     */
    private function userHomeDirectory(): string
    {
        // Read the current user home directory from the environment.
        $homeDirectory = getenv('HOME');

        // Stop when the home directory cannot be resolved.
        if (!is_string($homeDirectory) || $homeDirectory === '') {

            // Surface the missing home directory.
            throw new RuntimeException('Failed resolving the current user home directory.');
        }

        // Return the resolved home directory.
        return $homeDirectory;
    }

    /**
     * Slug the project name for the keychain item prefix.
     *
     * @param string $projectName The project name.
     *
     * @return string
     * @throws RuntimeException If the project name cannot be slugged.
     */
    private function slugProjectName(string $projectName): string
    {
        // Normalize the project name into a stable slug.
        $projectSlug = strtolower($projectName);
        $projectSlug = (string) preg_replace('/[^a-z0-9]+/', '-', $projectSlug);
        $projectSlug = trim($projectSlug, '-');

        // Stop when the normalized slug is empty.
        if ($projectSlug === '') {

            // Surface the invalid project name.
            throw new RuntimeException('The project name must contain at least one alphanumeric character.');
        }

        // Return the normalized project slug.
        return $projectSlug;
    }

    /**
     * Read a managed environment value by a keychain item attribute.
     *
     * @param string $itemName The managed keychain item name.
     * @param string $lookupOption The keychain attribute option.
     *
     * @return string|null
     */
    private function findEnvironmentValue(string $itemName, string $lookupOption): ?string
    {
        // Read the managed value from the requested keychain attribute.
        $environmentValue = shell_exec(sprintf(
            'security find-generic-password -a %s %s %s -w %s 2>/dev/null',
            escapeshellarg(self::ACCOUNT_NAME),
            $lookupOption,
            escapeshellarg($itemName),
            escapeshellarg($this->loginKeychainPath()),
        ));

        // Stop when the requested keychain attribute cannot be read.
        if (!is_string($environmentValue)) {

            return null;
        }

        // Return the trimmed managed value.
        return rtrim($environmentValue, "\r\n");
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