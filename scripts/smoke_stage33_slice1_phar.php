<?php

declare(strict_types=1);

$repositoryRoot = dirname(__DIR__);
$phar = $argv[1] ?? $repositoryRoot . '/build/baton.phar';
$phar = realpath($phar);
if (!is_string($phar) || !is_file($phar)) {
    fail('Pass the path to an assembled Baton PHAR.');
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baton-phar-slice1-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0o755, true)) {
    fail("Could not create temporary directory: {$root}");
}

try {
    $compiler = createCompiler($root);

    $schema1 = $root . '/schema1';
    mkdir($schema1 . '/src', 0o755, true);
    file_put_contents($schema1 . '/Baton.toml', <<<'TOML'
manifest-version = 1
[package]
name = "legacy"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
TOML);
    file_put_contents($schema1 . '/src/main.doria', "function main(): void {}\n");
    expectSuccess(run($phar, ['check', '--compiler', $compiler], $schema1), 'schema-1 manifest');

    expectSuccess(run($phar, ['new', 'hello'], $root), 'schema-2 project generation');
    $newManifest = (string) file_get_contents($root . '/hello/Baton.toml');
    foreach (['manifest-version = 2', 'publishable = false', '[autoload.namespaces]'] as $needle) {
        if (!str_contains($newManifest, $needle)) {
            fail("generated schema-2 manifest is missing {$needle}");
        }
    }
    expectSuccess(run($phar, ['check', '--compiler', $compiler], $root . '/hello'), 'schema-2 manifest');

    $multi = $root . '/multi';
    mkdir($multi . '/src/Domain/Fixtures', 0o755, true);
    file_put_contents($multi . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/tools"
version = "1.0.0"
edition = "2026"
[[targets.binary]]
name = "web"
entry = "src/web.doria"
[[targets.binary]]
name = "worker"
entry = "src/worker.doria"
[autoload.namespaces]
"Acme\\Tools\\" = { path = "src/", include = ["**/*.doria"], exclude = ["**/Fixtures/**"] }
TOML);
    foreach (['src/web.doria', 'src/worker.doria', 'src/Domain/Shared.doria', 'src/Domain/Fixtures/Skip.doria'] as $path) {
        file_put_contents($multi . '/' . $path, "class Fixture {}\n");
    }
    $ambiguous = run($phar, ['check', '--compiler', $compiler], $multi);
    if ($ambiguous['status'] === 0 || !str_contains($ambiguous['stderr'], 'Target Selection Is Ambiguous')) {
        fail('multi-target PHAR smoke did not require a selector');
    }
    expectSuccess(
        run($phar, ['check', '--binary', 'web', '--compiler', $compiler], $multi),
        'target selection and source discovery',
    );
    $plans = glob($multi . '/build/*/development/web/build-plan.json') ?: [];
    if (count($plans) !== 1) {
        fail('schema-2 PHAR smoke did not write one target-scoped build plan');
    }
    $plan = (string) file_get_contents($plans[0]);
    foreach (['src/web.doria', 'src/Domain/Shared.doria'] as $included) {
        if (!str_contains($plan, $included)) {
            fail("build plan is missing {$included}");
        }
    }
    foreach (['src/worker.doria', 'src/Domain/Fixtures/Skip.doria'] as $excluded) {
        if (str_contains($plan, $excluded)) {
            fail("build plan incorrectly contains {$excluded}");
        }
    }

    fwrite(STDOUT, "Stage 33 Slice 1 PHAR smoke passed\n");
} finally {
    removeDirectory($root);
}

function createCompiler(string $root): string
{
    $target = hostTarget();
    $script = $root . '/fake-doriac.php';
    $source = str_replace('__TARGET__', $target, <<<'PHP'
<?php
if (($argv[1] ?? '') === '--version' && ($argv[2] ?? '') === '--json') {
    echo json_encode([
        'schema' => 1,
        'component' => 'doriac',
        'toolchainVersion' => '2026.03.1-canary',
        'target' => '__TARGET__',
        'commit' => 'phar-smoke-compiler',
    ]) . "\n";
    exit(0);
}
$index = array_search('--build-plan', $argv, true);
if (is_int($index)) {
    $plan = json_decode((string) file_get_contents($argv[$index + 1] ?? ''), true);
    if (($plan['schemaVersion'] ?? null) !== 1) {
        exit(92);
    }
}
exit(0);
PHP);
    file_put_contents($script, $source);
    if (PHP_OS_FAMILY !== 'Windows') {
        $launcher = $root . '/doriac';
        file_put_contents($launcher, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script) . " \"\$@\"\n");
        chmod($launcher, 0o755);
        return $launcher;
    }

    $launcher = $root . '/doriac.cmd';
    file_put_contents($launcher, "@echo off\r\n\"" . PHP_BINARY . "\" \"{$script}\" %*\r\nexit /b %errorlevel%\r\n");
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

/** @param list<string> $arguments
 * @return array{status: int, stdout: string, stderr: string}
 */
function run(string $phar, array $arguments, string $workingDirectory): array
{
    $process = proc_open(
        [PHP_BINARY, $phar, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
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
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function fail(string $message): never
{
    fwrite(STDERR, "Stage 33 Slice 1 PHAR smoke failed: {$message}\n");
    exit(1);
}
