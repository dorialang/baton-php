<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

/** The captured result of a compiler invocation. */
final class CompilerResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
