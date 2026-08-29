<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Integration;

use Doria\Baton\Application;
use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;

final class Schema2CommandTest extends TestCase
{
    public function testCheckWritesAPlanAndForwardsTheCompilerStatusWithoutAReceipt(): void
    {
        $root = $this->project();
        $compiler = $this->fakeCompiler($root);
        self::assertNotFalse(file_put_contents($root . '/compiler-exit', '29'));

        $result = $this->runBaton(['check', '--binary', 'web', '--compiler', $compiler], $root);

        self::assertSame(29, $result['exitCode']);
        self::assertSame("schema2 compiler diagnostic\n", $result['stderr']);
        $directory = $this->targetDirectory($root, 'development', 'web');
        self::assertFileExists($directory . '/build-plan.json');
        self::assertFileDoesNotExist($directory . '/build.json');
        self::assertSame(
            ['check', '--build-plan', $this->nativePath($directory . '/build-plan.json')],
            $this->compilerArguments($root),
        );
    }

    public function testBinaryBuildUsesTargetLayoutAndWritesExactReceipt(): void
    {
        $root = $this->project();
        $compiler = $this->fakeCompiler($root);

        $result = $this->runBaton(['build', '--release', '--binary', 'worker', '--compiler', $compiler], $root);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $directory = $this->targetDirectory($root, 'release', 'worker');
        $artifact = $directory . '/' . (PHP_OS_FAMILY === 'Windows' ? 'worker.exe' : 'worker');
        self::assertFileExists($artifact);
        self::assertFileExists($directory . '/build-plan.json');
        self::assertFileExists($directory . '/build.json');
        $receipt = json_decode((string) file_get_contents($directory . '/build.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($receipt);
        self::assertIsArray($receipt['target']);
        self::assertIsArray($receipt['toolchain']);
        self::assertIsArray($receipt['artifact']);
        self::assertSame(1, $receipt['schemaVersion']);
        self::assertSame('acme/blog', $receipt['package']);
        self::assertSame(['name' => 'worker', 'kind' => 'binary'], $receipt['target']);
        self::assertSame('release', $receipt['profile']);
        self::assertSame('fake-compiler-commit', $receipt['toolchain']['compilerCommit']);
        self::assertSame(hash_file('sha256', $artifact), $receipt['artifact']['sha256']);
        self::assertSame(hash_file('sha256', $directory . '/build-plan.json'), $receipt['buildPlanSha256']);
        self::assertStringNotContainsString($root, (string) file_get_contents($directory . '/build.json'));
    }

    public function testLibraryBuildChecksAndRecordsNoInventedArtifact(): void
    {
        $root = $this->project();
        $compiler = $this->fakeCompiler($root);

        $result = $this->runBaton(['build', '--library', '--compiler', $compiler], $root);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $directory = $this->targetDirectory($root, 'development', 'blog');
        self::assertSame(
            ['check', '--build-plan', $this->nativePath($directory . '/build-plan.json')],
            $this->compilerArguments($root),
        );
        $receipt = json_decode((string) file_get_contents($directory . '/build.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($receipt);
        self::assertIsArray($receipt['target']);
        self::assertNull($receipt['artifact']);
        self::assertSame('library', $receipt['target']['kind']);
        self::assertFileDoesNotExist($directory . '/blog');
        self::assertFileDoesNotExist($directory . '/blog.exe');

        $rejected = $this->runBaton(['build', '--library', '--out', $root . '/library.a', '--compiler', $compiler], $root);
        self::assertSame(1, $rejected['exitCode']);
        self::assertStringContainsString('Library Target Has No Artifact', $rejected['stderr']);
    }

    public function testFailedBuildRemovesStaleTargetOutputsAndReceipt(): void
    {
        $root = $this->project();
        $compiler = $this->fakeCompiler($root);
        $directory = $this->targetDirectory($root, 'development', 'web');
        self::assertTrue(mkdir($directory, 0o755, true));
        $artifact = $directory . '/' . (PHP_OS_FAMILY === 'Windows' ? 'web.exe' : 'web');
        self::assertNotFalse(file_put_contents($artifact, 'stale'));
        self::assertNotFalse(file_put_contents($directory . '/build.json', '{"stale":true}'));
        self::assertNotFalse(file_put_contents($root . '/compiler-exit', '17'));

        $result = $this->runBaton(['build', '--binary', 'web', '--compiler', $compiler], $root);

        self::assertSame(17, $result['exitCode']);
        self::assertFileDoesNotExist($artifact);
        self::assertFileDoesNotExist($directory . '/build.json');
    }

    public function testExplicitOutputStillUsesManagedPlanAndWritesNoReceipt(): void
    {
        $root = $this->project();
        $compiler = $this->fakeCompiler($root);
        $output = $root . '/custom output/program' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');

        $result = $this->runBaton(
            ['build', '--binary', 'web', '--out', $output, '--compiler', $compiler],
            $root,
        );

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertFileExists($output);
        $directory = $this->targetDirectory($root, 'development', 'web');
        self::assertFileExists($directory . '/build-plan.json');
        self::assertFileDoesNotExist($directory . '/build.json');
    }

    public function testRunBuildsOnceAndExecutesTheSelectedTarget(): void
    {
        $root = $this->project();
        $compiler = $this->fakeCompiler($root);

        $result = $this->runBaton(
            ['run', '--binary', 'web', '--compiler', $compiler, '--', '-r', 'echo "ran schema2\\n"; exit(23);'],
            $root,
        );

        self::assertSame(23, $result['exitCode'], $result['stderr']);
        self::assertSame("ran schema2\n", $result['stdout']);
        self::assertSame(1, (int) file_get_contents($root . '/compiler-call-count'));
    }

    public function testNewDependencyAndGraphCommandsExposeTheCompletedPhaseFBoundary(): void
    {
        $root = $this->temporaryDirectory('new schema2');
        $new = $this->runBaton(['new', 'hello'], $root);
        self::assertSame(0, $new['exitCode'], $new['stderr']);
        $manifest = (string) file_get_contents($root . '/hello/Baton.toml');
        self::assertStringContainsString('manifest-version = 2', $manifest);
        self::assertStringContainsString('edition = "2026"', $manifest);
        self::assertStringContainsString('publishable = false', $manifest);
        self::assertStringContainsString('"" = "src/"', $manifest);
        self::assertFileDoesNotExist($root . '/hello/Baton.lock');

        $install = $this->runBaton(['install'], $root . '/hello');
        self::assertSame(0, $install['exitCode'], $install['stderr']);
        self::assertFileExists($root . '/hello/Baton.lock');
        $lock = json_decode(
            (string) file_get_contents($root . '/hello/Baton.lock'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($lock);
        self::assertSame([], $lock['packages']);
        $update = $this->runBaton(['update'], $root . '/hello');
        self::assertSame(0, $update['exitCode'], $update['stderr']);
        self::assertFileExists($root . '/hello/Baton.lock');
        $fetch = $this->runBaton(['fetch'], $root . '/hello');
        self::assertSame(0, $fetch['exitCode'], $fetch['stderr']);
        $tree = $this->runBaton(['tree'], $root . '/hello');
        self::assertSame(0, $tree['exitCode'], $tree['stderr']);
        self::assertSame("hello\n", $tree['stdout']);
        $why = $this->runBaton(['why', 'hello'], $root . '/hello');
        self::assertSame(0, $why['exitCode'], $why['stderr']);
        self::assertSame("hello\n", $why['stdout']);
        $test = $this->runBaton(['test', '--help'], $root . '/hello');
        self::assertSame(0, $test['exitCode'], $test['stderr']);
        self::assertStringContainsString('Discover, compile, and run project tests', $test['stdout']);
        self::assertStringNotContainsString('Stage 33 Slice 3', $test['stdout'] . $test['stderr']);
    }

    private function project(): string
    {
        $root = $this->temporaryDirectory('schema2 command project');
        self::assertTrue(mkdir($root . '/src/Domain', 0o755, true));
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/blog"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "blog"
[[targets.binary]]
name = "web"
entry = "src/web.doria"
[[targets.binary]]
name = "worker"
entry = "src/worker.doria"
[autoload.namespaces]
"Acme\\Blog\\" = "src/"
[autoload-dev.namespaces]
"Acme\\Blog\\Tests\\" = "tests/"
TOML));
        foreach (['src/Domain/Post.doria', 'src/web.doria', 'src/worker.doria', 'tests/PostTest.doria'] as $source) {
            self::assertNotFalse(file_put_contents($root . '/' . $source, "class Fixture {}\n"));
        }

        return $root;
    }

    private function fakeCompiler(string $root): string
    {
        $script = $root . '/fake compiler/fake-compiler.php';
        $this->writeExecutable(
            $script,
            str_replace(
                ['__TARGET__', '__VERSION__'],
                [Platform::host()->target(), Application::VERSION],
                <<<'PHP'
#!/usr/bin/env php
<?php
if (($argv[1] ?? '') === '--version' && ($argv[2] ?? '') === '--json') {
    echo json_encode([
        'schema' => 1,
        'component' => 'doriac',
        'toolchainVersion' => '__VERSION__',
        'target' => '__TARGET__',
        'commit' => 'fake-compiler-commit',
    ]) . "\n";
    exit(0);
}
file_put_contents('compiler-arguments.json', json_encode(array_slice($argv, 1)));
$count = is_file('compiler-call-count') ? (int) file_get_contents('compiler-call-count') : 0;
file_put_contents('compiler-call-count', (string) ($count + 1));
$planIndex = array_search('--build-plan', $argv, true);
if (!is_int($planIndex) || !isset($argv[$planIndex + 1])) {
    fwrite(STDERR, "missing build plan\n");
    exit(91);
}
$plan = json_decode((string) file_get_contents($argv[$planIndex + 1]), true);
if (($plan['schemaVersion'] ?? null) !== 1 || ($plan['rootPackage'] ?? null) !== 'acme/blog') {
    fwrite(STDERR, "invalid build plan\n");
    exit(92);
}
$configuredExit = is_file('compiler-exit') ? (int) file_get_contents('compiler-exit') : 0;
if ($configuredExit !== 0) {
    fwrite(STDERR, "schema2 compiler diagnostic\n");
    exit($configuredExit);
}
if (($argv[1] ?? '') === 'compile') {
    $outputIndex = array_search('--out', $argv, true);
    $output = is_int($outputIndex) ? ($argv[$outputIndex + 1] ?? null) : null;
    if (!is_string($output)) {
        exit(93);
    }
    if (!(PHP_OS_FAMILY === 'Windows' ? copy(PHP_BINARY, $output) : symlink(PHP_BINARY, $output))) {
        exit(94);
    }
}
PHP,
            ),
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            return $script;
        }
        $launcher = dirname($script) . '/doriac.bat';
        $this->writeExecutable(
            $launcher,
            "@echo off\r\n\"" . PHP_BINARY . '" "' . $script . "\" %*\r\nexit /b %errorlevel%\r\n",
        );

        return $launcher;
    }

    private function targetDirectory(string $root, string $profile, string $target): string
    {
        return $this->nativePath("{$root}/build/" . Platform::host()->target() . "/{$profile}/{$target}");
    }

    /** @return list<string> */
    private function compilerArguments(string $root): array
    {
        $arguments = json_decode((string) file_get_contents($root . '/compiler-arguments.json'), true);
        self::assertIsArray($arguments);
        self::assertContainsOnlyString($arguments);

        return array_values($arguments);
    }
}
