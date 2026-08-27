<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

final readonly class WrittenBuildPlan
{
    public function __construct(
        public string $path,
        public string $sha256,
        public string $bytes,
    ) {
    }
}
