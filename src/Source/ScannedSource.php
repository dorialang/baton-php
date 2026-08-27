<?php

declare(strict_types=1);

namespace Doria\Baton\Source;

final readonly class ScannedSource
{
    public function __construct(
        public string $relativeToMapping,
        public string $canonicalPath,
    ) {
    }
}
