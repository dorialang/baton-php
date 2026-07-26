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
        $this->temporaryDirectories[] = $path;

        return $path;
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
