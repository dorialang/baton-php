<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Integration;

use Doria\Baton\Application;
use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;

final class DependencyCommandTest extends TestCase
{
    public function testPathDependencyLifecycleFeedsTheCompilerPlanAndReceipt(): void
    {
        $workspace = $this->temporaryDirectory('dependency commands');
        $root = $this->package($workspace, 'app', 'acme/app', true);
        $this->package($workspace, 'support', 'acme/support');
        $compiler = $this->fakeCompiler($root);

        $install = $this->runBaton(['install', '--offline'], $root);
        self::assertSame(0, $install['exitCode'], $install['stderr']);
        self::assertFileExists($root . '/Baton.lock');
        $initialLock = json_decode((string) file_get_contents($root . '/Baton.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($initialLock);
        self::assertIsArray($initialLock['root']);
        self::assertSame([], $initialLock['root']['dependencies']);
        self::assertSame([], $initialLock['packages']);
        $initialLockBytes = (string) file_get_contents($root . '/Baton.lock');
        $reinstall = $this->runBaton(['install', '--offline'], $root);
        self::assertSame(0, $reinstall['exitCode'], $reinstall['stderr']);
        self::assertSame($initialLockBytes, file_get_contents($root . '/Baton.lock'));

        $add = $this->runBaton(['add', 'acme/support', '--source', 'path', '--path', '../support', '--version', '^1.0', '--offline'], $root);
        self::assertSame(0, $add['exitCode'], $add['stderr']);
        self::assertStringContainsString('# retained project comment', (string) file_get_contents($root . '/Baton.toml'));
        self::assertFileExists($root . '/Baton.lock');

        $check = $this->runBaton(['check', '--binary', 'app', '--compiler', $compiler, '--offline'], $root);
        self::assertSame(0, $check['exitCode'], $check['stderr']);
        $planPath = $this->buildDirectory($root) . '/build-plan.json';
        $plan = json_decode((string) file_get_contents($planPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertIsArray($plan['packages']);
        self::assertSame(['acme/app', 'acme/support'], array_column($plan['packages'], 'identity'));
        self::assertIsArray($plan['packages'][0]);
        self::assertIsArray($plan['packages'][0]['dependencies']);
        self::assertSame(
            [['package' => 'acme/support', 'kind' => 'normal']],
            $plan['packages'][0]['dependencies'],
        );

        $build = $this->runBaton(['build', '--binary', 'app', '--compiler', $compiler, '--offline'], $root);
        self::assertSame(0, $build['exitCode'], $build['stderr']);
        $receipt = json_decode(
            (string) file_get_contents($this->buildDirectory($root) . '/build.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($receipt);
        self::assertIsArray($receipt['lock']);
        self::assertIsArray($receipt['pathDependencies']);
        self::assertSame(hash_file('sha256', $root . '/Baton.lock'), $receipt['lock']['sha256']);
        self::assertSame('Baton.lock', $receipt['lock']['path']);
        self::assertSame(['acme/support'], array_column($receipt['pathDependencies'], 'package'));
        self::assertStringNotContainsString($workspace, json_encode($receipt, JSON_THROW_ON_ERROR));

        $manifestBefore = (string) file_get_contents($root . '/Baton.toml');
        $lockBefore = (string) file_get_contents($root . '/Baton.lock');
        $fetch = $this->runBaton(['fetch', 'acme/support', '--offline'], $root);
        self::assertSame(0, $fetch['exitCode'], $fetch['stderr']);
        self::assertSame($manifestBefore, file_get_contents($root . '/Baton.toml'));
        self::assertSame($lockBefore, file_get_contents($root . '/Baton.lock'));

        $remove = $this->runBaton(['remove', 'acme/support', '--offline'], $root);
        self::assertSame(0, $remove['exitCode'], $remove['stderr']);
        self::assertStringNotContainsString('acme/support', (string) file_get_contents($root . '/Baton.toml'));
        $emptyLock = json_decode((string) file_get_contents($root . '/Baton.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($emptyLock);
        self::assertIsArray($emptyLock['root']);
        self::assertSame([], $emptyLock['root']['dependencies']);
        self::assertSame([], $emptyLock['packages']);
    }

    public function testFailedAddLeavesManifestAndLockBytesUntouched(): void
    {
        $workspace = $this->temporaryDirectory('dependency rollback');
        $root = $this->package($workspace, 'app', 'acme/app', true);
        $update = $this->runBaton(['update', '--offline'], $root);
        self::assertSame(0, $update['exitCode'], $update['stderr']);
        $manifest = (string) file_get_contents($root . '/Baton.toml');
        $lock = (string) file_get_contents($root . '/Baton.lock');

        $failed = $this->runBaton(['add', 'acme/missing', '--source', 'path', '--path', '../missing', '--offline'], $root);

        self::assertSame(1, $failed['exitCode']);
        self::assertStringContainsString('Path Dependency Could Not Be Read', $failed['stderr']);
        self::assertSame($manifest, file_get_contents($root . '/Baton.toml'));
        self::assertSame($lock, file_get_contents($root . '/Baton.lock'));
    }

    private function package(string $workspace, string $directory, string $name, bool $binary = false): string
    {
        $root = $workspace . '/' . $directory;
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/src/Library.doria', "class Library {}\n"));
        $binaryTarget = '';
        if ($binary) {
            self::assertNotFalse(file_put_contents($root . '/src/main.doria', "function main(): void {}\n"));
            $binaryTarget = "\n[[targets.binary]]\nname = \"app\"\nentry = \"src/main.doria\"\n";
        }
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<TOML
# retained project comment
manifest-version = 2
[package]
name = "{$name}"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "library"
{$binaryTarget}[autoload.namespaces]
"" = "src/"
TOML));

        return $root;
    }

    private function fakeCompiler(string $root): string
    {
        $script = $root . '/tools/fake-compiler.php';
        $this->writeExecutable($script, str_replace(
            ['__VERSION__', '__TARGET__'],
            [Application::VERSION, Platform::host()->target()],
            <<<'PHP'
#!/usr/bin/env php
<?php
if (($argv[1] ?? '') === '--version') {
    echo json_encode([
        'schema' => 1,
        'component' => 'doriac',
        'toolchainVersion' => '__VERSION__',
        'target' => '__TARGET__',
        'commit' => 'dependency-test-compiler',
    ]) . "\n";
    exit(0);
}
if (($argv[1] ?? '') === 'compile') {
    $index = array_search('--out', $argv, true);
    $output = is_int($index) ? ($argv[$index + 1] ?? null) : null;
    if (!is_string($output)) {
        exit(91);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        copy(PHP_BINARY, $output);
    } else {
        symlink(PHP_BINARY, $output);
    }
}
PHP));
        if (PHP_OS_FAMILY !== 'Windows') {
            return $script;
        }
        $launcher = dirname($script) . '/doriac.bat';
        $this->writeExecutable($launcher, "@echo off\r\n\"" . PHP_BINARY . '" "' . $script . "\" %*\r\n");

        return $launcher;
    }

    private function buildDirectory(string $root): string
    {
        return $this->nativePath($root . '/build/' . Platform::host()->target() . '/development/app');
    }
}
