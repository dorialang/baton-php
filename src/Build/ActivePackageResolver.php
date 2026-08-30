<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Manifest\Schema2Manifest;

final class ActivePackageResolver
{
    /** @return list<ResolvedPackage> */
    public function resolve(
        Schema2Manifest $manifest,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph|null $graph,
        bool $development,
        bool $aggregateWorkspace = false,
    ): array {
        if ($graph === null) {
            return [];
        }
        $active = [];
        $visit = function (Schema2Manifest $owner, bool $includeDevelopment) use (&$visit, &$active, $graph): void {
            foreach ($owner->declaredDependencies($includeDevelopment, false) as $dependency) {
                $package = $graph->packages[$dependency->package] ?? null;
                if ($package === null || isset($active[$dependency->package])) {
                    continue;
                }
                $active[$dependency->package] = $package;
                $visit($package->manifest, false);
            }
        };
        $visit($manifest, $development);
        if ($aggregateWorkspace && $graph instanceof ResolvedWorkspaceGraph) {
            foreach ($graph->workspace->sortedMembers() as $member) {
                if ($member->manifest->package->name !== $manifest->package->name) {
                    $package = $graph->packages[$member->manifest->package->name] ?? null;
                    if ($package !== null) {
                        $active[$member->manifest->package->name] = $package;
                    }
                }
                $visit($member->manifest, $development);
            }
        }
        $packages = array_values($active);
        usort($packages, static fn (ResolvedPackage $left, ResolvedPackage $right): int => strcmp(
            $left->manifest->package->compilerIdentity,
            $right->manifest->package->compilerIdentity,
        ));

        return $packages;
    }
}
