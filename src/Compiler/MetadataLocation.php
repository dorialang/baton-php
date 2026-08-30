<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

final readonly class MetadataLocation
{
    public function __construct(
        public string $source,
        public string $displayPath,
        public int $byteStart,
        public int $byteEnd,
    ) {
    }
}
