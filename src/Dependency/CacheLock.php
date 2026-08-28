<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;

final class CacheLock
{
    /** @param resource $handle */
    private function __construct(private $handle)
    {
    }

    public static function acquire(string $path): self
    {
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw self::error($path);
        }
        $deadline = microtime(true) + 10.0;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return new self($handle);
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        throw self::error($path);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    private static function error(string $path): BatonError
    {
        return new BatonError(
            'B0361',
            'Dependency Cache Could Not Be Written',
            "The dependency cache lock could not be acquired:\n    {$path}",
        );
    }
}
