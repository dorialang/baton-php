<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Integration;

use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;

final class CheckCommandTest extends TestCase
{
    public function testCheckFindsNestedProjectAndPreservesCompilerStreamsAndExitCode(): void
    {
        $root = $this->temporaryDirectory('project with spaces');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/nested/directory', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<'TOML'
manifest-version = 1

[package]
name = "check-fixture"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
TOML));
        self::assertNotFalse(file_put_contents(
            $root . '/src/main.doria',
            "function main(): void {}\n"
        ));

        $compiler = $this->writeFakeCompiler($root);

        $success = $this->runBaton(
            ['check', '--compiler', $compiler],
            $root . '/nested/directory',
        );
        self::assertSame(0, $success['exitCode']);
        self::assertSame("compiler stdout\n", $success['stdout']);
        self::assertSame("compiler stderr\n", $success['stderr']);

        self::assertNotFalse(file_put_contents($root . '/src/main.doria', "invalid\n"));
        $failure = $this->runBaton(
            ['check', '--compiler', $compiler],
            $root . '/nested/directory',
        );
        self::assertSame(7, $failure['exitCode']);
        self::assertSame('', $failure['stdout']);
        self::assertSame("compiler diagnostic unchanged\n", $failure['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runBaton(array $arguments, string $workingDirectory): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/baton',
            ...$arguments,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $workingDirectory);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
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
                $this->fakeCompiler()
            )
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            return $script;
        }

        $launcher = $directory . '/doriac.bat';
        $this->writeExecutable(
            $launcher,
            "@echo off\r\n"
                . '"' . PHP_BINARY . '" "' . $script . "\" %*\r\n"
                . "exit /b %errorlevel%\r\n"
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

if (($argv[1] ?? '') !== 'check' || ($argv[2] ?? '') !== 'src/main.doria') {
    fwrite(STDERR, "unexpected arguments\n");
    exit(9);
}

$source = file_get_contents($argv[2]);
if ($source !== false && str_contains($source, 'invalid')) {
    fwrite(STDERR, "compiler diagnostic unchanged\n");
    exit(7);
}

fwrite(STDOUT, "compiler stdout\n");
fwrite(STDERR, "compiler stderr\n");
PHP;
    }
}
