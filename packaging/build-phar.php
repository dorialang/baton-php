<?php

declare(strict_types=1);

$repositoryRoot = dirname(__DIR__);
$buildDirectory = "{$repositoryRoot}/build";
$stageDirectory = "{$buildDirectory}/phar-stage";
$pharPath = "{$buildDirectory}/baton.phar";
$inventoryPath = "{$buildDirectory}/baton-dependencies.json";
$licenceDirectory = "{$buildDirectory}/LICENSES/composer";

if ((int) ini_get('phar.readonly') !== 0) {
    fail('PHAR creation is disabled; run this script with phar.readonly=0.');
}

removeDirectory($stageDirectory);
removeDirectory($licenceDirectory);
makeDirectory($stageDirectory);

foreach (['composer.json', 'composer.lock'] as $file) {
    copyFile("{$repositoryRoot}/{$file}", "{$stageDirectory}/{$file}");
}
copyDirectory("{$repositoryRoot}/src", "{$stageDirectory}/src");
copyDirectory("{$repositoryRoot}/templates", "{$stageDirectory}/templates");
if (!is_file("{$repositoryRoot}/vendor/autoload.php")) {
    fail('Composer dependencies are not installed; run composer install first.');
}
copyDirectory("{$repositoryRoot}/vendor", "{$stageDirectory}/vendor");

$composer = getenv('COMPOSER_BINARY');
$composer = is_string($composer) && $composer !== '' ? $composer : 'composer';
$composerCommand = is_file($composer)
    ? [PHP_BINARY, $composer]
    : [$composer];
run([
    ...$composerCommand,
    'install',
    '--working-dir=' . $stageDirectory,
    '--no-dev',
    '--classmap-authoritative',
    '--no-scripts',
    '--no-interaction',
    '--no-progress',
    '--prefer-dist',
], ['COMPOSER_DISABLE_NETWORK' => '1']);

if (is_file($pharPath) && !unlink($pharPath)) {
    fail("Could not replace {$pharPath}");
}

$phar = new \Phar($pharPath, 0, 'baton.phar');
$phar->startBuffering();
$phar->addFile("{$repositoryRoot}/LICENSE", 'LICENSE');
foreach (['src', 'templates', 'vendor'] as $directory) {
    addDirectory($phar, "{$stageDirectory}/{$directory}", $directory);
}
$phar->setStub(<<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

Phar::mapPhar('baton.phar');

require 'phar://baton.phar/vendor/autoload.php';

exit(Doria\Baton\Application::create()->run(new Doria\Baton\Console\BatonArgvInput()));

__HALT_COMPILER();
PHP);
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->stopBuffering();
if (PHP_OS_FAMILY !== 'Windows' && !chmod($pharPath, 0o644)) {
    fail("Could not set permissions on {$pharPath}");
}

$inventory = installComposerLicences(
    "{$repositoryRoot}/composer.lock",
    "{$stageDirectory}/vendor",
    $licenceDirectory,
);
$inventoryJson = json_encode(
    [
        'schema' => 1,
        'source' => 'composer.lock',
        'packages' => $inventory,
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . PHP_EOL;
if (file_put_contents($inventoryPath, $inventoryJson) === false) {
    fail("Could not write dependency inventory: {$inventoryPath}");
}

$checksumPath = "{$pharPath}.sha256";
$checksum = hash_file('sha256', $pharPath) . '  ' . basename($pharPath) . PHP_EOL;
if (file_put_contents($checksumPath, $checksum) === false) {
    fail("Could not write PHAR checksum: {$checksumPath}");
}

removeDirectory($stageDirectory);

fwrite(STDOUT, "Baton PHAR: {$pharPath}" . PHP_EOL);
fwrite(STDOUT, "Dependency inventory: {$inventoryPath}" . PHP_EOL);

function makeDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        fail("Could not create directory: {$directory}");
    }
}

function copyFile(string $source, string $destination): void
{
    makeDirectory(dirname($destination));
    if (!copy($source, $destination)) {
        fail("Could not copy {$source} to {$destination}");
    }
}

function copyDirectory(string $source, string $destination): void
{
    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($entries as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($entry->isDir()) {
            makeDirectory($target);
        } else {
            copyFile($entry->getPathname(), $target);
        }
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            if (!rmdir($entry->getPathname())) {
                fail("Could not remove directory: {$entry->getPathname()}");
            }
        } elseif (!unlink($entry->getPathname())) {
            fail("Could not remove file: {$entry->getPathname()}");
        }
    }
    if (!rmdir($directory)) {
        fail("Could not remove directory: {$directory}");
    }
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 */
function run(array $command, array $environment = []): void
{
    fwrite(STDOUT, '> ' . implode(' ', array_map(
        static fn (string $argument): string => escapeshellarg($argument),
        $command,
    )) . PHP_EOL);
    $currentEnvironment = getenv();
    $process = proc_open(
        $command,
        [STDIN, STDOUT, STDERR],
        $pipes,
        env_vars: array_merge($currentEnvironment, $environment),
    );
    if (!is_resource($process)) {
        fail("Could not start process: {$command[0]}");
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("Process exited with status {$exitCode}: {$command[0]}");
    }
}

function addDirectory(\Phar $phar, string $source, string $prefix): void
{
    /** @var iterable<SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($entries as $entry) {
        if ($entry->isFile()) {
            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($entry->getPathname(), strlen($source) + 1),
            );
            $phar->addFile($entry->getPathname(), "{$prefix}/{$relative}");
        }
    }
}

/**
 * @return list<array{name: string, version: string, licence: list<string>, notices: list<string>}>
 */
function installComposerLicences(
    string $lockPath,
    string $vendorDirectory,
    string $destination,
): array {
    $lock = json_decode((string) file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
    $packages = $lock['packages'] ?? [];
    if (!is_array($packages)) {
        fail('composer.lock does not contain a production package list.');
    }
    makeDirectory($destination);

    $inventory = [];
    foreach ($packages as $package) {
        $name = $package['name'];
        $packageDirectory = "{$vendorDirectory}/{$name}";
        $notices = [];
        foreach (glob("{$packageDirectory}/{LICENSE,LICENSE.*,LICENCE,LICENCE.*,COPYING,COPYING.*}", GLOB_BRACE) ?: [] as $licence) {
            if (!is_file($licence)) {
                continue;
            }
            $noticeName = str_replace('/', '--', $name) . '--' . basename($licence);
            copyFile($licence, "{$destination}/{$noticeName}");
            $notices[] = "LICENSES/composer/{$noticeName}";
        }
        if ($notices === []) {
            fail("No licence file found for Composer package {$name}");
        }
        sort($notices);
        $inventory[] = [
            'name' => $name,
            'version' => $package['version'],
            'licence' => array_values($package['license'] ?? []),
            'notices' => $notices,
        ];
    }
    usort(
        $inventory,
        static fn (array $left, array $right): int => $left['name'] <=> $right['name'],
    );

    return $inventory;
}

function fail(string $message): never
{
    fwrite(STDERR, "PHAR build: {$message}" . PHP_EOL);
    exit(70);
}
