<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class PackageDefinition
{
    public function __construct(
        public string $name,
        public string $compilerIdentity,
        public string $version,
        public string $edition,
        public bool $publishable,
    ) {
    }
}
