<?php

declare(strict_types=1);

namespace Doria\Baton\Process;

final class BoundedProcessTimedOut extends \RuntimeException
{
    public function __construct(public readonly float $timeoutSeconds)
    {
        parent::__construct("Process exceeded {$timeoutSeconds} seconds.");
    }
}
