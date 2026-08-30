<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class DependencyDeclaration
{
    public function __construct(
        public string $package,
        public DependencySource $source,
        public ?PackageVersionConstraint $version,
        public DependencyKind $kind = DependencyKind::Normal,
    ) {
    }
}
