<?php

declare(strict_types=1);

$phar = realpath($argv[1] ?? dirname(__DIR__) . '/build/baton.phar');
if (!is_string($phar) || !is_file($phar)) {
    fail('Pass the path to an assembled Baton PHAR.');
}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'baton-phar-slice3-' . bin2hex(random_bytes(6));
$legacyRoot = $root . '-legacy';
if (!mkdir($root . '/packages', 0o755, true)) {
    fail('Could not create the smoke workspace.');
}

try {
    write($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[workspace]
members = ["packages/*"]
TOML);
    package($root, 'core', 'acme/core');
    package($root, 'test-support', 'acme/test-support');
    package($root, 'web', 'acme/web', <<<'TOML'
[dependencies]
"acme/core" = { source = "path", path = "../core", version = "^1.0" }
[dev-dependencies]
"acme/test-support" = { source = "path", path = "../test-support", version = "^1.0" }
TOML);

    expect(run($phar, ['install', '--offline'], $root), 'workspace install');
    $lock = json_decode((string) file_get_contents($root . '/Baton.lock'), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($lock) || ($lock['schemaVersion'] ?? null) !== 2) {
        fail('workspace install did not write lock schema 2');
    }

    $tree = expect(run($phar, ['tree', '--workspace', '--development'], $root), 'workspace tree');
    foreach (['acme/core 1.0.0 [normal]', 'acme/test-support 1.0.0 [development]'] as $needle) {
        if (!str_contains($tree['stdout'], $needle)) {
            fail("workspace tree omitted {$needle}");
        }
    }
    $why = expect(run($phar, ['why', 'acme/test-support', '--workspace', '--development'], $root), 'workspace why');
    if (!str_contains($why['stdout'], 'acme/web -> acme/test-support')) {
        fail('why did not explain the development dependency');
    }

    $project = expect(run($phar, ['project', '--json', '--workspace', '--development', '--offline'], $root), 'project inventory');
    $document = json_decode($project['stdout'], true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($document)
        || ($document['schemaVersion'] ?? null) !== 1
        || ($document['selection']['kind'] ?? null) !== 'workspace'
        || !is_array($document['toolingBuildPlan'] ?? null)
    ) {
        fail('project command did not emit strict project schema 1');
    }
    $again = expect(run($phar, ['project', '--json', '--workspace', '--development', '--offline'], $root), 'repeat project inventory');
    if ($project['stdout'] !== $again['stdout']) {
        fail('project inventory was not deterministic');
    }

    $help = expect(run($phar, ['test', '--help'], $root), 'test command help');
    if (!str_contains($help['stdout'], 'Discover, compile, and run project tests')) {
        fail('production PHAR omitted the real test command');
    }

    $legacy = package($legacyRoot, 'legacy', 'acme/legacy', <<<'TOML'
[dependencies]
"acme/old" = { git = "https://example.invalid/old.git", tag = "v1.0.0" }
TOML);
    $legacyResult = run($phar, ['install', '--offline'], $legacy);
    if ($legacyResult['status'] === 0
        || !str_contains($legacyResult['stderr'], 'Git Source Locator Spelling Has Changed')
        || !str_contains($legacyResult['stderr'], 'source = "git"')
        || !str_contains($legacyResult['stderr'], 'url = "..."')
    ) {
        fail('legacy Git spelling did not receive canonical migration guidance');
    }

    fwrite(STDOUT, "Stage 33 Slice 3 PHAR smoke passed\n");
} finally {
    removeTree($root);
    removeTree($legacyRoot);
}

function package(string $root, string $directory, string $name, string $extra = ''): string
{
    $package = $root . '/packages/' . $directory;
    if (!is_dir($package . '/src') && !mkdir($package . '/src', 0o755, true)) {
        fail("could not create package {$name}");
    }
    write($package . '/src/Library.doria', "class Library {}\n");
    write($package . '/Baton.toml', <<<TOML
manifest-version = 2
[package]
name = "{$name}"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "{$directory}"
[autoload.namespaces]
"" = "src/"
{$extra}
TOML);

    return $package;
}

function write(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        fail("could not write {$path}");
    }
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
        fail('could not start the production PHAR');
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

/** @param array{status: int, stdout: string, stderr: string} $result
 * @return array{status: int, stdout: string, stderr: string}
 */
function expect(array $result, string $operation): array
{
    if ($result['status'] !== 0) {
        fail("{$operation} failed: " . trim($result['stderr'] . "\n" . $result['stdout']));
    }
    return $result;
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

function fail(string $message): never
{
    fwrite(STDERR, "Stage 33 Slice 3 PHAR smoke failed: {$message}\n");
    exit(1);
}
