<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Integration;

use Doria\Baton\Application;
use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;

final class DoctorCommandTest extends TestCase
{
    public function testDoctorReportsDevelopmentToolchainHealthOutsideAProject(): void
    {
        $root = $this->temporaryDirectory('doctor outside project');
        $compiler = $this->writeFakeCompiler($root);

        $result = $this->runBaton(
            ['doctor', '--compiler', $compiler],
            $root,
            environment: $this->cacheEnvironment($root),
        );

        self::assertSame(0, $result['exitCode'], $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('PASS  Baton version', $result['stdout']);
        self::assertStringContainsString(Application::VERSION, $result['stdout']);
        self::assertStringContainsString('PASS  Toolchain version', $result['stdout']);
        self::assertStringContainsString('PASS  Release channel', $result['stdout']);
        self::assertStringContainsString('canary', $result['stdout']);
        self::assertStringContainsString('PASS  Baton executable', $result['stdout']);
        self::assertStringContainsString('PASS  PHP runtime', $result['stdout']);
        self::assertStringContainsString('PASS  Host platform', $result['stdout']);
        self::assertStringContainsString('PASS  Host architecture', $result['stdout']);
        self::assertStringContainsString('PASS  doriac path', $result['stdout']);
        self::assertStringContainsString($compiler, $result['stdout']);
        self::assertStringContainsString('PASS  doriac version', $result['stdout']);
        self::assertStringContainsString('PASS  native compiler', $result['stdout']);
        self::assertStringContainsString('runtime archive and linker verified', $result['stdout']);
        self::assertStringContainsString('WARNING  toolchain manifest', $result['stdout']);
        self::assertStringContainsString('WARNING  component hashes', $result['stdout']);
        self::assertStringContainsString('WARNING  doria-lsp', $result['stdout']);
        self::assertStringContainsString('WARNING  private PHP runtime', $result['stdout']);
        self::assertStringContainsString('WARNING  build location', $result['stdout']);
        self::assertStringContainsString('dependency cache', $result['stdout']);
        self::assertStringContainsString('Git executable', $result['stdout']);
        self::assertStringContainsString('offline policy', $result['stdout']);
    }

    public function testDoctorChecksTheCurrentProjectBuildLocation(): void
    {
        $root = $this->temporaryDirectory('doctor project');
        $compiler = $this->writeFakeCompiler($root);
        self::assertNotFalse(file_put_contents(
            $root . '/Baton.toml',
            <<<'TOML'
manifest-version = 1

[package]
name = "doctor-fixture"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
TOML,
        ));

        $result = $this->runBaton(
            ['doctor', '--compiler', $compiler],
            $root,
            environment: $this->cacheEnvironment($root),
        );

        self::assertSame(0, $result['exitCode'], $result['stdout']);
        self::assertStringContainsString(
            'PASS  build location',
            $result['stdout'],
        );
        self::assertStringContainsString(
            $this->nativePath($root . '/build'),
            $result['stdout'],
        );
    }

    public function testDoctorFailsWhenTheCompilerCannotBeStarted(): void
    {
        $root = $this->temporaryDirectory('doctor missing compiler');

        $result = $this->runBaton(
            ['doctor', '--compiler', $root . '/missing-doriac'],
            $root,
            environment: $this->cacheEnvironment($root),
        );

        self::assertSame(1, $result['exitCode']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('FAIL  doriac', $result['stdout']);
        self::assertStringContainsString(
            '[B0202] Doria Compiler Not Found',
            $result['stdout'],
        );
        self::assertStringContainsString('dependency cache', $result['stdout']);
    }

    public function testDoctorFailsWhenTheCompilerCannotLinkANativeProgram(): void
    {
        $root = $this->temporaryDirectory('doctor missing native runtime');
        $compiler = $this->writeFakeCompiler($root, nativeFailure: true);

        $result = $this->runBaton(
            ['doctor', '--compiler', $compiler],
            $root,
            environment: $this->cacheEnvironment($root),
        );

        self::assertSame(1, $result['exitCode'], $result['stdout']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('PASS  doriac version', $result['stdout']);
        self::assertStringContainsString('FAIL  native compiler', $result['stdout']);
        self::assertStringContainsString(
            '[B0001] Backend Error: Doria runtime archive not found',
            $result['stdout'],
        );
    }

    private function writeFakeCompiler(string $root, bool $nativeFailure = false): string
    {
        $script = $root . '/toolchain/doriac.php';
        $this->writeExecutable(
            $script,
            str_replace(
                ['__TARGET__', '__NATIVE_FAILURE__'],
                [Platform::host()->target(), $nativeFailure ? 'true' : 'false'],
                <<<'PHP'
#!/usr/bin/env php
<?php

if (($argv[1] ?? '') === '--version' && ($argv[2] ?? '') === '--json') {
    echo json_encode([
        'schema' => 1,
        'component' => 'doriac',
        'toolchainVersion' => '2026.03.1-canary',
        'target' => '__TARGET__',
        'commit' => 'doctor-fixture',
    ]) . "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'compile') {
    if (__NATIVE_FAILURE__) {
        echo json_encode([
            'schemaVersion' => 1,
            'diagnostics' => [[
                'code' => 'B0001',
                'title' => 'Backend Error',
                'message' => 'Doria runtime archive not found',
            ]],
            'summary' => [
                'status' => 'Compilation Failed',
                'errors' => 1,
                'warnings' => 0,
                'notes' => 0,
            ],
        ]) . "\n";
        exit(1);
    }
    $out = array_search('--out', $argv, true);
    if (!is_int($out) || !is_string($argv[$out + 1] ?? null)) {
        exit(2);
    }
    file_put_contents($argv[$out + 1], 'native fixture');
    chmod($argv[$out + 1], 0755);
    exit(0);
}

exit(2);
PHP,
            ),
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            return $script;
        }

        $launcher = $root . '/toolchain/doriac.bat';
        $this->writeExecutable(
            $launcher,
            "@echo off\r\n"
                . '"' . PHP_BINARY . '" "' . $script . "\" %*\r\n"
                . "exit /b %errorlevel%\r\n",
        );

        return $launcher;
    }

    /** @return array<string, string> */
    private function cacheEnvironment(string $root): array
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => ['LOCALAPPDATA' => $root . '/cache'],
            'Darwin' => ['HOME' => $root],
            default => ['XDG_CACHE_HOME' => $root . '/cache'],
        };
    }
}
