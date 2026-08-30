<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

final readonly class LockedGraphView
{
    /**
     * @param list<string> $roots
     * @param array<string, LockedPackage> $packages
     * @param array<string, list<LockedDependency>> $rootEdges
     * @param array<string, string> $rootVersions
     */
    public function __construct(
        public array $roots,
        public array $packages,
        public array $rootEdges,
        public array $rootVersions,
    ) {
    }
}
