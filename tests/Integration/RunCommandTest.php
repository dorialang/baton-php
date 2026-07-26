<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Integration;

use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;

final class RunCommandTest extends TestCase
{
    public function testRunBuildsThenForwardsInputOutputArgumentsAndExitCode(): void
    {
        $root = $this->project();
        $compiler = $this->writeFakeCompiler($root);
        $program = <<<'PHP'
$input = stream_get_contents(STDIN);
echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_UNICODE) . "\n";
echo $input;
fwrite(STDERR, "program stderr\n");
exit(23);
PHP;

        $result = $this->runBaton(
            [
                'run',
                '--release',
                '--compiler',
                $compiler,
                '--',
                '-r',
                $program,
                '--',
                '--looks-like-an-option',
                'two words',
                'Zażółć',
            ],
            $root . '/nested/directory',
            "program input\n",
        );

        $artifact = $root
            . '/build/'
            . Platform::host()->target()
            . '/release/'
            . (PHP_OS_FAMILY === 'Windows' ? 'run-fixture.exe' : 'run-fixture');
        self::assertSame(23, $result['exitCode']);
        self::assertSame(
            "[\"--looks-like-an-option\",\"two words\",\"Zażółć\"]\n"
                . "program input\n",
            $result['stdout'],
        );
        self::assertSame("program stderr\n", $result['stderr']);

        $arguments = file_get_contents($root . '/compiler-arguments.json');
        self::assertIsString($arguments);
        self::assertSame([
            'compile',
            'src/main.doria',
            '--target',
            'native',
            '--release',
            '--out',
            $artifact,
        ], json_decode($arguments, true));
    }

    public function testVerboseRunIncludesBuildOutput(): void
    {
        $root = $this->project();
        $compiler = $this->writeFakeCompiler($root);
        $artifact = $root
            . '/build/'
            . Platform::host()->target()
            . '/development/'
            . (PHP_OS_FAMILY === 'Windows' ? 'run-fixture.exe' : 'run-fixture');

        $result = $this->runBaton(
            ['run', '-v', '--compiler', $compiler, '--', '-r', 'echo "program output\n";'],
            $root,
        );

        self::assertSame(0, $result['exitCode']);
        self::assertSame($artifact . "\nprogram output\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testFailedBuildNeverExecutesOrLeavesTheOldArtifact(): void
    {
        $root = $this->project("invalid\n");
        $compiler = $this->writeFakeCompiler($root);
        $directory = $root
            . '/build/'
            . Platform::host()->target()
            . '/development';
        self::assertTrue(mkdir($directory, 0o755, true));
        $artifact = $directory
            . '/'
            . (PHP_OS_FAMILY === 'Windows' ? 'run-fixture.exe' : 'run-fixture');
        self::assertTrue(copy(PHP_BINARY, $artifact));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(chmod($artifact, 0o755));
        }

        $result = $this->runBaton(
            ['run', '--compiler', $compiler, '--', '-r', 'echo "must not run";'],
            $root,
        );

        self::assertSame(17, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertSame("compiler diagnostic unchanged\n", $result['stderr']);
        self::assertFileDoesNotExist($artifact);
    }

    private function project(string $source = "function main(): void {}\n"): string
    {
        $root = $this->temporaryDirectory('run project with spaces');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/nested/directory', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<'TOML'
manifest-version = 1

[package]
name = "run-fixture"
version = "1.2.3"
kind = "binary"
entry = "src/main.doria"
TOML));
        self::assertNotFalse(file_put_contents($root . '/src/main.doria', $source));

        return $root;
    }

    private function writeFakeCompiler(string $root): string
    {
        $directory = $root . '/fake compiler';
        $script = $directory . '/fake-compiler.php';
        $this->writeExecutable(
            $script,
            str_replace(
                '__TARGET__',
                Platform::host()->target(),
                $this->fakeCompiler(),
            ),
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            return $script;
        }

        $launcher = $directory . '/doriac.bat';
        $this->writeExecutable(
            $launcher,
            "@echo off\r\n"
                . '"' . PHP_BINARY . '" "' . $script . "\" %*\r\n"
                . "exit /b %errorlevel%\r\n",
        );

        return $launcher;
    }

    private function fakeCompiler(): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php

if (($argv[1] ?? '') === '--version' && ($argv[2] ?? '') === '--json') {
    echo json_encode([
        'schema' => 1,
        'component' => 'doriac',
        'toolchainVersion' => '2026.03.1-canary',
        'target' => '__TARGET__',
        'commit' => 'fake',
    ]) . "\n";
    exit(0);
}

file_put_contents('compiler-arguments.json', json_encode(array_slice($argv, 1)));
$source = file_get_contents('src/main.doria');
if ($source !== false && str_contains($source, 'invalid')) {
    fwrite(STDERR, "compiler diagnostic unchanged\n");
    exit(17);
}

$outputIndex = array_search('--out', $argv, true);
if (!is_int($outputIndex) || !isset($argv[$outputIndex + 1])) {
    fwrite(STDERR, "missing --out\n");
    exit(9);
}
$output = $argv[$outputIndex + 1];
if (!copy(PHP_BINARY, $output)) {
    fwrite(STDERR, "failed to create fake program\n");
    exit(10);
}
if (PHP_OS_FAMILY !== 'Windows') {
    chmod($output, 0755);
}
echo $output . "\n";
PHP;
    }
}
