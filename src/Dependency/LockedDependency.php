<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\DependencyKind;

final readonly class LockedDependency
{
    public function __construct(
        public string $package,
        public ?string $constraint,
        public DependencyKind $kind = DependencyKind::Normal,
    ) {
    }
}
