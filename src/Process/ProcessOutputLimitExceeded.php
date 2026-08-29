<?php

declare(strict_types=1);

namespace Doria\Baton\Process;

final class ProcessOutputLimitExceeded extends \RuntimeException
{
    public function __construct(
        public readonly string $stream,
        public readonly int $limit,
    ) {
        parent::__construct("Process {$stream} exceeded {$limit} retained bytes.");
    }
}
