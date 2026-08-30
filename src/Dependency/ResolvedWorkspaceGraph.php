<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Workspace\WorkspaceContext;

final readonly class ResolvedWorkspaceGraph
{
    /** @param array<string, ResolvedPackage> $packages */
    public function __construct(
        public WorkspaceContext $workspace,
        public string $workspaceFingerprint,
        public array $packages,
    ) {
    }

    /** @return list<ResolvedPackage> */
    public function sortedPackages(): array
    {
        $packages = array_values($this->packages);
        usort($packages, static fn (ResolvedPackage $left, ResolvedPackage $right): int => strcmp(
            $left->manifest->package->compilerIdentity,
            $right->manifest->package->compilerIdentity,
        ));

        return $packages;
    }
}
