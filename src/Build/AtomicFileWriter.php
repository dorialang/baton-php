<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Diagnostics\BatonError;

final class AtomicFileWriter
{
    public function write(string $path, string $bytes, string $heading): string
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw $this->error($heading, $path, 'The parent directory does not exist.');
        }
        if (is_dir($path)) {
            throw $this->error($heading, $path, 'A directory exists where a file is required.');
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            throw $this->error($heading, $path, 'A secure temporary-file name could not be created.');
        }
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($path) . ".{$suffix}.tmp";
        $written = @file_put_contents($temporary, $bytes, LOCK_EX);
        if ($written !== strlen($bytes)) {
            @unlink($temporary);
            throw $this->error($heading, $path, 'The complete file could not be written.');
        }

        if (!@rename($temporary, $path)) {
            // PHP's Windows rename cannot replace an existing file. The fallback
            // still never exposes partial bytes: the complete sibling temp file
            // is moved only after the old regular file is removed.
            if ((file_exists($path) || is_link($path)) && !is_dir($path) && @unlink($path)) {
                if (@rename($temporary, $path)) {
                    return hash('sha256', $bytes);
                }
            }
            @unlink($temporary);
            throw $this->error($heading, $path, 'The completed temporary file could not replace the destination.');
        }

        return hash('sha256', $bytes);
    }

    private function error(string $heading, string $path, string $detail): BatonError
    {
        return new BatonError(
            'B0405',
            $heading,
            "Path: {$path}\n{$detail}",
            ['Check that the managed build location is writable, then retry:'],
            ['baton doctor'],
        );
    }
}
