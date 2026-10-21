<?php

declare(strict_types=1);

/*
 * Update the generated PIE package block in install-php-extensions.
 *
 * Usage:
 *   php scripts/update-pie-packages.php
 *   php scripts/update-pie-packages.php --check
 *
 * The --check mode exits with a non-zero code if install-php-extensions doesn't contain the generated block.
 */

const ROOT_DIR = __DIR__ . '/..';

const PIE_PACKAGES_PATH = ROOT_DIR . '/data/pie-packages';

const SUPPORTED_EXTENSIONS_PATH = ROOT_DIR . '/data/supported-extensions';

const INSTALLER_PATH = ROOT_DIR . '/install-php-extensions';

const START_MARKER = "\t\t# START OF PIE PACKAGES (generated from data/pie-packages)";

const END_MARKER = "\t\t# END OF PIE PACKAGES (generated from data/pie-packages)";

/**
 * @return array<string, string> extension name => PIE package name
 */
function readPiePackages(): array
{
    $contents = file_get_contents(PIE_PACKAGES_PATH);
    if ($contents === false) {
        throw new RuntimeException('Failed to read ' . PIE_PACKAGES_PATH);
    }
    $supportedExtensions = [];
    $supportedContents = file_get_contents(SUPPORTED_EXTENSIONS_PATH);
    if ($supportedContents === false) {
        throw new RuntimeException('Failed to read ' . SUPPORTED_EXTENSIONS_PATH);
    }
    foreach (preg_split('/\R/', $supportedContents, -1, PREG_SPLIT_NO_EMPTY) as $line) {
        $words = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
        if ($words !== []) {
            $supportedExtensions[$words[0]] = true;
        }
    }
    $result = [];
    $packages = [];
    foreach (preg_split('/\R/', $contents, -1, PREG_SPLIT_NO_EMPTY) as $lineNumber => $line) {
        $words = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) !== 3) {
            throw new RuntimeException('Line ' . ($lineNumber + 1) . ' of ' . PIE_PACKAGES_PATH . ' must contain exactly three words: the extension name, the minimum PHP version, and the PIE package name');
        }
        [$extension, $minimumPhpVersion, $package] = $words;
        if (!preg_match('/^[a-z0-9_]+$/', $extension)) {
            throw new RuntimeException('Invalid extension name in ' . PIE_PACKAGES_PATH . ': ' . $extension);
        }
        if (!isset($supportedExtensions[$extension])) {
            throw new RuntimeException('The extension ' . $extension . ' is not listed in ' . SUPPORTED_EXTENSIONS_PATH);
        }
        if (!preg_match('/^[0-9]+\.[0-9]+$/', $minimumPhpVersion) || !version_compare($minimumPhpVersion, '8.1', '>=')) {
            throw new RuntimeException('Invalid minimum PHP version in ' . PIE_PACKAGES_PATH . ': ' . $minimumPhpVersion);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/', $package)) {
            throw new RuntimeException('Invalid PIE package name in ' . PIE_PACKAGES_PATH . ': ' . $package);
        }
        if (isset($result[$extension])) {
            throw new RuntimeException('Duplicated extension in ' . PIE_PACKAGES_PATH . ': ' . $extension);
        }
        if (isset($packages[$package])) {
            throw new RuntimeException('Duplicated PIE package in ' . PIE_PACKAGES_PATH . ': ' . $package);
        }
        $result[$extension] = $package;
        $packages[$package] = true;
    }
    if ($result === []) {
        throw new RuntimeException(PIE_PACKAGES_PATH . ' is empty');
    }
    ksort($result, SORT_STRING);

    return $result;
}

/**
 * @param array<string, string> $packages
 */
function generateBlock(array $packages): string
{
    $lines = [];
    foreach ($packages as $extension => $package) {
        $lines[] = "\t\t{$extension})";
        $lines[] = "\t\t\tprintf '%s' '{$package}'";
        $lines[] = "\t\t\t;;";
    }

    return implode("\n", $lines);
}

function replaceBlock(string $installer, string $block): string
{
    $start = strpos($installer, START_MARKER);
    if ($start === false) {
        throw new RuntimeException('Unable to find the PIE packages start marker in ' . INSTALLER_PATH);
    }
    $end = strpos($installer, END_MARKER, $start);
    if ($end === false) {
        throw new RuntimeException('Unable to find the PIE packages end marker in ' . INSTALLER_PATH);
    }
    $prefix = substr($installer, 0, $start);

    return $prefix . START_MARKER . "\n" . $block . "\n" . substr($installer, $end);
}

function main(): int
{
    try {
        $packages = readPiePackages();
        $installer = file_get_contents(INSTALLER_PATH);
        if ($installer === false) {
            throw new RuntimeException('Failed to read ' . INSTALLER_PATH);
        }
        $updated = replaceBlock($installer, generateBlock($packages));
        if (in_array('--check', $_SERVER['argv'] ?? [], true)) {
            if ($updated !== $installer) {
                fwrite(STDERR, "The generated PIE packages block in install-php-extensions is out of date.\nRun: php scripts/update-pie-packages.php\n");

                return 1;
            }

            return 0;
        }
        if (file_put_contents(INSTALLER_PATH, $updated) === false) {
            throw new RuntimeException('Failed to write ' . INSTALLER_PATH);
        }
    } catch (RuntimeException $x) {
        fwrite(STDERR, $x->getMessage() . "\n");

        return 1;
    }

    return 0;
}

exit(main());
