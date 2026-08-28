<?php

declare(strict_types=1);

$phar = realpath($argv[1] ?? dirname(__DIR__) . '/build/baton.phar');
if (!is_string($phar) || !is_file($phar)) {
    fail('Pass the path to an assembled Baton PHAR.');
}

$workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baton-phar-slice2-' . bin2hex(random_bytes(6));
if (!mkdir($workspace, 0o755, true)) {
    fail("Could not create temporary directory: {$workspace}");
}

try {
    $compiler = createCompiler($workspace);
    $support = createPackage($workspace, 'support', 'acme/support', false);
    file_put_contents($support . '/src/Support.doria', "class Support {}\n");
    $application = createPackage($workspace, 'app', 'acme/app', true);

    expectSuccess(run($phar, ['install', '--offline'], $application), 'empty install');
    $emptyLock = lockDocument($application);
    if (($emptyLock['packages'] ?? null) !== []) {
        fail('dependency-free install did not produce a canonical empty lock');
    }

    expectSuccess(run(
        $phar,
        ['add', 'acme/support', '--path', '../support', '--version', '^1.0', '--offline'],
        $application,
    ), 'path dependency add');
    expectSuccess(run($phar, ['fetch', 'acme/support', '--offline'], $application), 'locked fetch');
    expectSuccess(run(
        $phar,
        ['check', '--binary', 'app', '--compiler', $compiler, '--offline'],
        $application,
    ), 'multi-package check');

    $plans = glob($application . '/build/*/development/app/build-plan.json') ?: [];
    if (count($plans) !== 1) {
        fail('dependency PHAR smoke did not write one build plan');
    }
    $plan = json_decode((string) file_get_contents($plans[0]), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($plan) || !is_array($plan['packages'] ?? null)) {
        fail('dependency PHAR smoke wrote an invalid build plan');
    }
    if (array_column($plan['packages'], 'identity') !== ['acme/app', 'acme/support']) {
        fail('dependency PHAR smoke omitted the resolved package graph');
    }

    $tree = run($phar, ['tree'], $application);
    if ($tree['status'] === 0 || !str_contains($tree['stderr'], 'Stage 33 Slice 3')) {
        fail('tree was not kept behind the Stage 33 Slice 3 boundary');
    }

    $offline = createPackage($workspace, 'offline', 'acme/offline', true);
    $manifestBefore = (string) file_get_contents($offline . '/Baton.toml');
    $cacheHome = $workspace . '/empty-cache-home';
    mkdir($cacheHome, 0o755, true);
    $missing = run(
        $phar,
        ['add', 'acme/remote', '--git', 'https://example.invalid/acme/remote.git', '--tag', 'v1.0.0', '--offline'],
        $offline,
        ['HOME' => $cacheHome, 'LOCALAPPDATA' => $cacheHome, 'XDG_CACHE_HOME' => $cacheHome],
    );
    if ($missing['status'] === 0 || !str_contains($missing['stderr'], 'Offline Dependency Content Is Missing')) {
        fail('offline cache miss did not produce the dependency diagnostic');
    }
    if (file_get_contents($offline . '/Baton.toml') !== $manifestBefore || is_file($offline . '/Baton.lock')) {
        fail('failed offline add changed project files');
    }

    fwrite(STDOUT, "Stage 33 Slice 2 PHAR smoke passed\n");
} finally {
    removeDirectory($workspace);
}

function createPackage(string $workspace, string $directory, string $name, bool $binary): string
{
    $root = $workspace . DIRECTORY_SEPARATOR . $directory;
    mkdir($root . '/src', 0o755, true);
    file_put_contents($root . '/src/Library.doria', "class Library {}\n");
    $binaryTarget = '';
    if ($binary) {
        file_put_contents($root . '/src/main.doria', "function main(): void {}\n");
        $binaryTarget = "\n[[targets.binary]]\nname = \"{$directory}\"\nentry = \"src/main.doria\"\n";
    }
    file_put_contents($root . '/Baton.toml', <<<TOML
manifest-version = 2
[package]
name = "{$name}"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "library"
{$binaryTarget}[autoload.namespaces]
"" = "src/"
TOML);

    return $root;
}

/** @return array<string, mixed> */
function lockDocument(string $root): array
{
    $document = json_decode((string) file_get_contents($root . '/Baton.lock'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($document) || array_is_list($document) || ($document['schemaVersion'] ?? null) !== 1) {
        fail('PHAR wrote an invalid Baton.lock');
    }

    return $document;
}

function createCompiler(string $root): string
{
    $target = hostTarget();
    $script = $root . '/fake-doriac.php';
    file_put_contents($script, str_replace('__TARGET__', $target, <<<'PHP'
<?php
if (($argv[1] ?? '') === '--version' && ($argv[2] ?? '') === '--json') {
    echo json_encode(['schema' => 1, 'component' => 'doriac', 'toolchainVersion' => '2026.03.1-canary', 'target' => '__TARGET__', 'commit' => 'slice2-phar-smoke']) . "\n";
    exit(0);
}
exit(0);
PHP));
    if (PHP_OS_FAMILY === 'Windows') {
        $launcher = $root . '/doriac.cmd';
        file_put_contents($launcher, "@echo off\r\n\"" . PHP_BINARY . "\" \"{$script}\" %*\r\nexit /b %errorlevel%\r\n");

        return $launcher;
    }
    $launcher = $root . '/doriac';
    file_put_contents($launcher, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . " \"\$@\"\n");
    chmod($launcher, 0o755);

    return $launcher;
}

function hostTarget(): string
{
    $platform = match (PHP_OS_FAMILY) {
        'Windows' => 'windows',
        'Darwin' => 'macos',
        'Linux' => 'linux',
        default => fail('Unsupported PHAR smoke platform.'),
    };
    $architecture = match (strtolower(php_uname('m'))) {
        'x86_64', 'amd64' => 'x86_64',
        'aarch64', 'arm64' => 'aarch64',
        default => fail('Unsupported PHAR smoke architecture.'),
    };

    return "{$platform}-{$architecture}";
}

/**
 * @param list<string> $arguments
 * @param array<string, string> $environment
 * @return array{status: int, stdout: string, stderr: string}
 */
function run(string $phar, array $arguments, string $workingDirectory, array $environment = []): array
{
    $process = proc_open(
        [PHP_BINARY, $phar, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        array_merge(getenv(), $environment),
    );
    if (!is_resource($process)) {
        fail('Could not start Baton PHAR.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'status' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

/** @param array{status: int, stdout: string, stderr: string} $result */
function expectSuccess(array $result, string $operation): void
{
    if ($result['status'] !== 0) {
        fail("{$operation} failed with {$result['status']}: {$result['stderr']}");
    }
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
            @chmod($entry->getPathname(), 0o755);
            rmdir($entry->getPathname());
        } else {
            @chmod($entry->getPathname(), 0o644);
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function fail(string $message): never
{
    fwrite(STDERR, "Stage 33 Slice 2 PHAR smoke failed: {$message}\n");
    exit(1);
}
