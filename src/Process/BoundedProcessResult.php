<?php

declare(strict_types=1);

namespace Doria\Baton\Process;

final readonly class BoundedProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }
}
