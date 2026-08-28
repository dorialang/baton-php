<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class Schema2Manifest
{
    public int $manifestVersion;

    /** @param array<string, DependencyDeclaration> $dependencies */
    public function __construct(
        public PackageDefinition $package,
        public TargetCollection $targets,
        public AutoloadConfiguration $autoload,
        public array $dependencies = [],
    ) {
        $this->manifestVersion = 2;
    }
}
