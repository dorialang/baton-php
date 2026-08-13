<?php

declare(strict_types=1);

namespace Doria\Baton\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

abstract class TestCase extends PHPUnitTestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function temporaryDirectory(string $name): string
    {
        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'baton-'
            . $name
            . '-'
            . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($path, 0o755, true));
        $canonical = realpath($path);
        self::assertIsString($canonical);
        $this->temporaryDirectories[] = $canonical;

        return $canonical;
    }

    protected function writeExecutable(string $path, string $contents = "compiler\n"): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o755, true));
        }
        self::assertNotFalse(file_put_contents($path, $contents));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(chmod($path, 0o755));
        }
    }

    protected function compilerName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'doriac.exe' : 'doriac';
    }

    protected function languageServerName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'doria-lsp.exe' : 'doria-lsp';
    }

    protected function nativePath(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    protected function assertSamePath(string $expected, string $actual): void
    {
        self::assertSame($this->nativePath($expected), $this->nativePath($actual));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string>|null $environment
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    protected function runBaton(
        array $arguments,
        string $workingDirectory,
        string $input = '',
        ?array $environment = null,
    ): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__) . '/bin/baton',
            ...$arguments,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $currentEnvironment = getenv();
        $processEnvironment = $environment === null
            ? null
            : array_merge($currentEnvironment, $environment);
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDirectory,
            $processEnvironment,
        );
        self::assertIsResource($process);
        if ($input !== '') {
            self::assertSame(strlen($input), fwrite($pipes[0], $input));
        }
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

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
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
}
