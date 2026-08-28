<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\Schema2Manifest;
final readonly class ResolvedDependencyGraph
{
    /**
     * @param array<string, ResolvedPackage> $packages Authored package identity keyed.
     */
    public function __construct(
        public string $root,
        public Schema2Manifest $manifest,
        public string $manifestFingerprint,
        public array $packages,
    ) {
    }

    /** @return list<ResolvedPackage> */
    public function sortedPackages(): array
    {
        $packages = array_values($this->packages);
        usort(
            $packages,
            static fn (ResolvedPackage $left, ResolvedPackage $right): int => strcmp(
                $left->manifest->package->compilerIdentity,
                $right->manifest->package->compilerIdentity,
            ),
        );

        return $packages;
    }
}
