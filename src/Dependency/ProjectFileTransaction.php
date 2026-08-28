<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;

final class ProjectFileTransaction
{
    public function commit(
        string $manifestPath,
        string $manifestBytes,
        string $lockPath,
        string $lockBytes,
    ): void {
        $suffix = bin2hex(random_bytes(8));
        $manifestTemporary = dirname($manifestPath) . DIRECTORY_SEPARATOR . '.' . basename($manifestPath) . ".{$suffix}.tmp";
        $lockTemporary = dirname($lockPath) . DIRECTORY_SEPARATOR . '.' . basename($lockPath) . ".{$suffix}.tmp";
        $manifestBackup = $manifestPath . ".{$suffix}.bak";
        $lockBackup = $lockPath . ".{$suffix}.bak";
        $hadLock = is_file($lockPath);
        try {
            $this->write($manifestTemporary, $manifestBytes);
            $this->write($lockTemporary, $lockBytes);
            if (!@rename($manifestPath, $manifestBackup)) {
                throw new \RuntimeException('the current manifest could not be preserved');
            }
            if ($hadLock && !@rename($lockPath, $lockBackup)) {
                @rename($manifestBackup, $manifestPath);
                throw new \RuntimeException('the current lockfile could not be preserved');
            }
            if (!@rename($manifestTemporary, $manifestPath)
                || !@rename($lockTemporary, $lockPath)
            ) {
                @unlink($manifestPath);
                @unlink($lockPath);
                @rename($manifestBackup, $manifestPath);
                if ($hadLock) {
                    @rename($lockBackup, $lockPath);
                }
                throw new \RuntimeException('the completed project files could not be published');
            }
            @unlink($manifestBackup);
            @unlink($lockBackup);
        } catch (\Throwable $error) {
            @unlink($manifestTemporary);
            @unlink($lockTemporary);
            if (!is_file($manifestPath) && is_file($manifestBackup)) {
                @rename($manifestBackup, $manifestPath);
            }
            if ($hadLock && !is_file($lockPath) && is_file($lockBackup)) {
                @rename($lockBackup, $lockPath);
            }
            throw new BatonError(
                'B0382',
                'Dependency Transaction Could Not Be Committed',
                "The original Baton.toml and Baton.lock were retained.\n{$error->getMessage()}",
            );
        }
    }

    private function write(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("the temporary file could not be created: {$path}");
        }
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = @fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("the complete temporary file could not be written: {$path}");
                }
                $offset += $written;
            }
            if (!@fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
                throw new \RuntimeException("the temporary file could not be flushed: {$path}");
            }
        } finally {
            fclose($handle);
        }
    }
}
