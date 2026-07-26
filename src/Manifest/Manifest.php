<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

/** The validated, minimal bootstrap manifest (plan B6). */
final class Manifest
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
