<?php

declare(strict_types=1);

/**
 * Builds Baton's private, statically linked PHP CLI runtime.
 *
 * This is a contributor/release script. The resulting runtime is self-contained;
 * Composer and this script are not included in the public toolchain.
 */

const EXIT_USAGE = 64;
const EXIT_SOFTWARE = 70;

$repositoryRoot = dirname(__DIR__, 2);
$specPath = __DIR__ . '/spec.json';
$spec = json_decode((string) file_get_contents($specPath), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($spec)) {
    fail('Runtime specification is not a JSON object.');
}

$options = parseOptions(array_slice($argv, 1));
$hostTarget = hostTarget();
$target = $options['target'] ?? $hostTarget;
if (!isset($spec['builder']['assets'][$target])) {
    fail("Unsupported runtime target: {$target}", EXIT_USAGE);
}

$extensions = $spec['extensions']['common'];
if (!str_starts_with($target, 'windows-')) {
    $extensions = [...$extensions, ...$spec['extensions']['unix']];
}
$sources = sourcesForTarget($spec['sources'], $target);
$asset = $spec['builder']['assets'][$target];

$outputDirectory = absolutePath(
    $options['output'] ?? "{$repositoryRoot}/build/php-runtime/{$target}",
    getcwd() ?: $repositoryRoot,
);
$workDirectory = absolutePath(
    $options['work'] ?? "{$repositoryRoot}/build/php-runtime-work/{$target}",
    getcwd() ?: $repositoryRoot,
);

$plan = [
    'target' => $target,
    'phpVersion' => $spec['php']['version'],
    'builder' => $spec['builder']['name'] . ' ' . $spec['builder']['version'],
    'builderTarget' => $asset['spcTarget'],
    'extensions' => $extensions,
    'sources' => array_keys($sources),
    'output' => $outputDirectory,
    'work' => $workDirectory,
];

if (isset($options['print-plan'])) {
    fwrite(STDOUT, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

if ($target !== $hostTarget) {
    fail(
        "The {$target} runtime must be built on a {$target} host; this host is {$hostTarget}.",
        EXIT_USAGE,
    );
}

makeDirectory($workDirectory);
guardWorkDirectory($workDirectory);
prepareOutputDirectory($outputDirectory);
if (!putenv("SPC_TARGET={$asset['spcTarget']}")) {
    fail('Could not configure the runtime builder target.');
}

$assetName = basename(parse_url($asset['url'], PHP_URL_PATH));
$assetPath = "{$workDirectory}/{$assetName}";
downloadVerified($asset['url'], $assetPath, $asset['sha256']);

$builderPath = installBuilder($assetPath, $workDirectory, str_starts_with($target, 'windows-'));
$extensionArgument = implode(',', $extensions);
$sourceArguments = [];
foreach ($sources as $name => $source) {
    $sourceArguments[] = "--custom-url={$name}:{$source['url']}";
}
if (isset($options['prepare'])) {
    run([
        $builderPath,
        'doctor',
        '--auto-fix',
        '--no-interaction',
    ], $workDirectory);
}

run([
    $builderPath,
    'download',
    "--for-extensions={$extensionArgument}",
    "--with-php={$spec['php']['version']}",
    '--without-suggestions',
    ...$sourceArguments,
    '--no-interaction',
], $workDirectory);

foreach ($sources as $name => $source) {
    verifyDownloadedSource($workDirectory . '/downloads', $name, $source['sha256']);
}

run([
    $builderPath,
    'build',
    $extensionArgument,
    '--build-cli',
    '--with-config-file-path=/__doria_private_runtime__',
    '--with-config-file-scan-dir=/__doria_private_runtime__',
    '--no-interaction',
], $workDirectory);

$windows = str_starts_with($target, 'windows-');
$builtBinary = "{$workDirectory}/buildroot/bin/php" . ($windows ? '.exe' : '');
$runtimeBinDirectory = "{$outputDirectory}/bin";
makeDirectory($runtimeBinDirectory);
$runtimeBinary = "{$runtimeBinDirectory}/php" . ($windows ? '.exe' : '');
copyFile($builtBinary, $runtimeBinary);
if (!$windows && !chmod($runtimeBinary, 0o755)) {
    fail("Could not make runtime executable: {$runtimeBinary}");
}

$licenceDirectory = "{$outputDirectory}/LICENSES/runtime";
run([
    $builderPath,
    'dump-license',
    "--for-extensions={$extensionArgument}",
    "--dump-dir={$licenceDirectory}",
    '--no-interaction',
], $workDirectory);
$licences = listFiles($licenceDirectory, $outputDirectory);
if ($licences === []) {
    fail('The runtime builder did not produce third-party licence notices.');
}
verifyRuntime($runtimeBinary, $spec['php']['version'], !$windows);

$runtimeManifest = [
    'schema' => 1,
    'target' => $target,
    'phpVersion' => $spec['php']['version'],
    'binary' => [
        'path' => 'bin/' . basename($runtimeBinary),
        'sha256' => hash_file('sha256', $runtimeBinary),
    ],
    'builder' => [
        'name' => $spec['builder']['name'],
        'version' => $spec['builder']['version'],
        'target' => $asset['spcTarget'],
        'assetSha256' => $asset['sha256'],
    ],
    'sources' => $sources,
    'extensions' => $extensions,
    'capabilities' => $spec['capabilities'],
    'licences' => $licences,
];
$manifestPath = "{$outputDirectory}/runtime.json";
$manifest = json_encode(
    $runtimeManifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . PHP_EOL;
if (file_put_contents($manifestPath, $manifest) === false) {
    fail("Could not write runtime manifest: {$manifestPath}");
}

if (!isset($options['keep-work'])) {
    foreach (['buildroot', 'source', 'pkgroot'] as $transientDirectory) {
        removeDirectory("{$workDirectory}/{$transientDirectory}");
    }
}

fwrite(STDOUT, "Private PHP runtime: {$runtimeBinary}" . PHP_EOL);
fwrite(STDOUT, "Runtime manifest: {$manifestPath}" . PHP_EOL);

/**
 * @param array<string, array{url: string, sha256: string, runtime: bool, targets?: list<string>}> $sources
 * @return array<string, array{url: string, sha256: string, runtime: bool, targets?: list<string>}>
 */
function sourcesForTarget(array $sources, string $target): array
{
    return array_filter(
        $sources,
        static fn (array $source): bool => !isset($source['targets'])
            || in_array($target, $source['targets'], true),
    );
}

/** @param list<string> $arguments
 *  @return array<string, string|true>
 */
function parseOptions(array $arguments): array
{
    $options = [];
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($argument === '--print-plan') {
            $options['print-plan'] = true;
            continue;
        }
        if ($argument === '--prepare') {
            $options['prepare'] = true;
            continue;
        }
        if ($argument === '--keep-work') {
            $options['keep-work'] = true;
            continue;
        }

        if (!in_array($argument, ['--target', '--output', '--work'], true)) {
            fail("Unknown option: {$argument}", EXIT_USAGE);
        }
        $value = array_shift($arguments);
        if ($value === null || $value === '') {
            fail("Missing value for {$argument}.", EXIT_USAGE);
        }
        $options[substr($argument, 2)] = $value;
    }

    return $options;
}

function hostTarget(): string
{
    $platform = match (PHP_OS_FAMILY) {
        'Linux' => 'linux',
        'Darwin' => 'macos',
        'Windows' => 'windows',
        default => fail('Unsupported build host: ' . PHP_OS_FAMILY, EXIT_USAGE),
    };
    $machine = strtolower(php_uname('m'));
    $architecture = match ($machine) {
        'x86_64', 'amd64' => 'x86_64',
        'aarch64', 'arm64' => 'aarch64',
        default => fail("Unsupported build architecture: {$machine}", EXIT_USAGE),
    };

    if ($platform === 'windows' && $architecture !== 'x86_64') {
        fail('Only windows-x86_64 is supported.', EXIT_USAGE);
    }

    return "{$platform}-{$architecture}";
}

function absolutePath(string $path, string $base): string
{
    if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1) {
        return rtrim($path, '/\\');
    }

    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . rtrim($path, '/\\');
}

function makeDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        fail("Could not create directory: {$directory}");
    }
}

function guardWorkDirectory(string $directory): void
{
    $marker = "{$directory}/.baton-runtime-work";
    if (is_file($marker)) {
        return;
    }
    $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    if ($entries !== []) {
        fail(
            "Refusing to use a nonempty unmarked work directory: {$directory}\n"
                . 'Choose an empty --work directory.',
            EXIT_USAGE,
        );
    }
    if (file_put_contents($marker, "Baton private runtime build workspace\n") === false) {
        fail("Could not mark runtime work directory: {$directory}");
    }
}

function prepareOutputDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        makeDirectory($directory);
        return;
    }
    $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    if ($entries === []) {
        return;
    }
    $manifestPath = "{$directory}/runtime.json";
    $manifest = is_file($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true)
        : null;
    if (
        !is_array($manifest)
        || ($manifest['schema'] ?? null) !== 1
        || ($manifest['builder']['name'] ?? null) !== 'static-php-cli'
    ) {
        fail(
            "Refusing to replace an unrecognized nonempty output directory: {$directory}\n"
                . 'Choose an empty --output directory.',
            EXIT_USAGE,
        );
    }
    removeDirectory($directory);
    makeDirectory($directory);
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
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

function downloadVerified(string $url, string $destination, string $sha256): void
{
    if (is_file($destination) && hash_file('sha256', $destination) === $sha256) {
        return;
    }

    fwrite(STDOUT, "Downloading {$url}" . PHP_EOL);
    $input = @fopen($url, 'rb');
    if ($input === false) {
        fail("Could not download: {$url}");
    }
    $output = @fopen($destination . '.part', 'wb');
    if ($output === false) {
        fclose($input);
        fail("Could not write download: {$destination}.part");
    }
    $copied = stream_copy_to_stream($input, $output);
    fclose($input);
    fclose($output);
    if ($copied === false) {
        fail("Download failed: {$url}");
    }
    verifyFile($destination . '.part', $sha256);
    if (!rename($destination . '.part', $destination)) {
        fail("Could not finalize download: {$destination}");
    }
}

function verifyFile(string $path, string $sha256): void
{
    if (!is_file($path)) {
        fail("Expected file is missing: {$path}");
    }
    $actual = hash_file('sha256', $path);
    if (!hash_equals($sha256, $actual)) {
        fail("SHA-256 mismatch for {$path}: expected {$sha256}, got {$actual}");
    }
}

function verifyDownloadedSource(string $directory, string $name, string $sha256): void
{
    foreach (new DirectoryIterator($directory) as $entry) {
        if (!$entry->isFile() || $entry->getFilename() === '.lock.json') {
            continue;
        }
        if (hash_file('sha256', $entry->getPathname()) === $sha256) {
            return;
        }
    }

    fail("Pinned source {$name} was not downloaded with SHA-256 {$sha256}");
}

function installBuilder(string $assetPath, string $workDirectory, bool $windows): string
{
    $builderPath = "{$workDirectory}/spc" . ($windows ? '.exe' : '');
    if ($windows) {
        copyFile($assetPath, $builderPath);
        return $builderPath;
    }

    $archive = new PharData($assetPath);
    $archive->extractTo($workDirectory, null, true);
    if (!is_file($builderPath)) {
        fail("Builder archive did not contain spc: {$assetPath}");
    }
    if (!chmod($builderPath, 0o755)) {
        fail("Could not make builder executable: {$builderPath}");
    }

    return $builderPath;
}

/** @param list<string> $command */
function run(array $command, string $workingDirectory): void
{
    fwrite(STDOUT, '> ' . implode(' ', array_map(
        static fn (string $argument): string => escapeshellarg($argument),
        $command,
    )) . PHP_EOL);
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $workingDirectory);
    if (!is_resource($process)) {
        fail("Could not start process: {$command[0]}");
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("Process exited with status {$exitCode}: {$command[0]}");
    }
}

function copyFile(string $source, string $destination): void
{
    if (!is_file($source)) {
        fail("Expected file is missing: {$source}");
    }
    makeDirectory(dirname($destination));
    if (!copy($source, $destination)) {
        fail("Could not copy {$source} to {$destination}");
    }
}

/** @return list<string> */
function listFiles(string $directory, string $relativeTo): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $files = [];
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($entries as $entry) {
        if ($entry->isFile()) {
            $files[] = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($entry->getPathname(), strlen($relativeTo) + 1),
            );
        }
    }
    sort($files);

    return $files;
}

function verifyRuntime(string $runtime, string $expectedVersion, bool $unix): void
{
    $requiredExtensions = $unix
        ? ['Phar', 'iconv', 'filter', 'zlib', 'pcntl', 'posix']
        : ['Phar', 'iconv', 'filter', 'zlib'];
    $probe = <<<'PHP'
$required = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$missing = array_values(array_filter(
    $required,
    static fn (string $extension): bool => !extension_loaded($extension),
));
$capabilities = [
    'json' => function_exists('json_encode'),
    'hash' => function_exists('hash_file'),
    'filesystem' => function_exists('file_get_contents'),
    'filter' => function_exists('filter_var'),
    'process' => function_exists('proc_open'),
];
$failed = array_keys(array_filter(
    $capabilities,
    static fn (bool $available): bool => !$available,
));
echo json_encode([
    'version' => PHP_VERSION,
    'ini' => php_ini_loaded_file(),
    'scannedIni' => php_ini_scanned_files(),
    'missingExtensions' => $missing,
    'missingCapabilities' => $failed,
], JSON_THROW_ON_ERROR);
PHP;
    $process = proc_open(
        [$runtime, '-n', '-r', $probe, json_encode($requiredExtensions, JSON_THROW_ON_ERROR)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        fail("Could not start private runtime: {$runtime}");
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail("Private runtime probe failed ({$exitCode}): {$stderr}");
    }
    $result = json_decode((string) $stdout, true, flags: JSON_THROW_ON_ERROR);
    if (
        $result['version'] !== $expectedVersion
        || $result['ini'] !== false
        || $result['scannedIni'] !== false
        || $result['missingExtensions'] !== []
        || $result['missingCapabilities'] !== []
    ) {
        fail('Private runtime isolation probe failed: ' . json_encode($result));
    }
}

function fail(string $message, int $exitCode = EXIT_SOFTWARE): never
{
    fwrite(STDERR, "runtime build: {$message}" . PHP_EOL);
    exit($exitCode);
}
