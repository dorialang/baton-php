<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

final readonly class WorkspaceLockedMember
{
    public function __construct(
        public string $package,
        public string $compilerPackage,
        public string $path,
    ) {
    }
}
