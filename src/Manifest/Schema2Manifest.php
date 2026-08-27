<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class Schema2Manifest
{
    public int $manifestVersion;

    public function __construct(
        public PackageDefinition $package,
        public TargetCollection $targets,
        public AutoloadConfiguration $autoload,
    ) {
        $this->manifestVersion = 2;
    }
}
