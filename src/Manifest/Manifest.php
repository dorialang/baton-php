<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

/** The exact historical schema-1 manifest model. */
final readonly class Manifest
{
    public function __construct(
        public readonly int $manifestVersion,
        public readonly string $name,
        public readonly string $version,
        public readonly string $kind,
        public readonly string $entry,
    ) {
    }
}
