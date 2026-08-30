<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Workspace\ProjectSelection;
use Doria\Baton\Workspace\WorkspaceMember;

final class LockedGraphLoader
{
    public function load(ProjectSelection $selection): LockedGraphView
    {
        if ($selection->workspace !== null) {
            $selectedManifest = $selection->manifest;
            if (!$selection->aggregate && !$selectedManifest instanceof Schema2Manifest) {
                throw new \LogicException('Selected workspace package is missing its schema-2 manifest.');
            }
            $lock = (new WorkspaceLockFileStore())->require($selection->workspace->root);
            $roots = $selection->aggregate
                ? array_map(
                    static fn (WorkspaceMember $member): string => $member->manifest->package->name,
                    $selection->workspace->sortedMembers(),
                )
                : [$selectedManifest->package->name];
            $edges = [];
            $versions = [];
            foreach ($roots as $root) {
                $edges[$root] = $lock->packages[$root]->dependencies;
                $versions[$root] = $lock->packages[$root]->version;
            }

            return new LockedGraphView($roots, $lock->packages, $edges, $versions);
        }
        $manifest = $selection->manifest;
        if ($manifest instanceof Manifest) {
            return new LockedGraphView([$manifest->name], [], [$manifest->name => []], [$manifest->name => $manifest->version]);
        }
        if (!$manifest instanceof Schema2Manifest) {
            throw new \LogicException('Standalone graph requires a package manifest.');
        }
        $lock = (new LockFileStore())->load($selection->lockRoot);
        if ($lock === null) {
            return new LockedGraphView(
                [$manifest->package->name],
                [],
                [$manifest->package->name => []],
                [$manifest->package->name => $manifest->package->version],
            );
        }

        return new LockedGraphView(
            [$lock->rootPackage],
            $lock->packages,
            [$lock->rootPackage => $lock->rootDependencies],
            [$lock->rootPackage => $lock->rootVersion],
        );
    }
}
