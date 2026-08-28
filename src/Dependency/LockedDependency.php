<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

final readonly class LockedDependency
{
    public function __construct(
        public string $package,
        public ?string $constraint,
    ) {
    }
}
