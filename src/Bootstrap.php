<?php

declare(strict_types=1);

use PhilipAnnis\LaravelAgentGuard\ProjectKeychain;

// Stop on unsupported platforms.
if (PHP_OS_FAMILY !== 'Darwin') {

    return;
}

try {

    // Resolve the current project root and environment file path.
    $projectRootDirectory = dirname(__DIR__, 4);
    $environmentFilePath = $projectRootDirectory . DIRECTORY_SEPARATOR . '.env';

    // Build the project keychain manager.
    $projectKeychain = new ProjectKeychain($projectRootDirectory);

    // Stop when the project has no managed keychain values.
    if (!$projectKeychain->hasManagedEnvironmentValues()) {

        return;
    }

    // Recreate a blank environment file when the source file is missing.
    if (!is_file($environmentFilePath)) {

        // Create the blank environment file placeholder.
        file_put_contents($environmentFilePath, '');
    }

    // Read each managed environment value from the login keychain.
    foreach ($projectKeychain->readEnvironmentValues() as $environmentKey => $environmentValue) {

        // Populate every runtime environment source with the managed value.
        putenv($environmentKey . '=' . $environmentValue);
        $_ENV[$environmentKey] = $environmentValue;
        $_SERVER[$environmentKey] = $environmentValue;
    }

} catch (\Throwable) {

    return;
}