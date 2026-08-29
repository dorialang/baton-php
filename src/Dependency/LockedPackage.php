<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

final readonly class LockedPackage
{
    /**
     * @param array{kind: 'path'|'workspace', path: string}|array{kind: 'git', url: string, selector: array{kind: string, value: string}, commit: string} $source
     * @param list<LockedDependency> $dependencies
     */
    public function __construct(
        public string $package,
        public string $compilerPackage,
        public string $version,
        public string $manifestFingerprint,
        public array $source,
        public array $dependencies,
    ) {
    }
}
