<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\DependencyDeclaration;

final class WorkspaceLockFileFactory
{
    public function fromGraph(ResolvedWorkspaceGraph $graph): WorkspaceLockFile
    {
        $members = [];
        foreach ($graph->workspace->sortedMembers() as $member) {
            $members[] = new WorkspaceLockedMember(
                $member->manifest->package->name,
                $member->manifest->package->compilerIdentity,
                $member->relativePath,
            );
        }
        $memberNames = array_fill_keys(array_map(
            static fn (WorkspaceLockedMember $member): string => $member->package,
            $members,
        ), true);
        $packages = [];
        foreach ($graph->packages as $name => $package) {
            $source = $package->source;
            if ($source->kind === 'git') {
                if ($source->url === null || $source->selector === null || $source->commit === null) {
                    throw new \LogicException('Resolved Git source is incomplete.');
                }
                $sourceRecord = [
                    'kind' => 'git',
                    'url' => $source->url,
                    'selector' => ['kind' => $source->selector->kind, 'value' => $source->selector->value],
                    'commit' => $source->commit,
                ];
            } else {
                $sourceRecord = [
                    'kind' => isset($memberNames[$name]) ? 'workspace' : 'path',
                    'path' => PortablePath::relative($graph->workspace->root, $source->root),
                ];
            }
            $declarations = isset($memberNames[$name])
                ? $package->manifest->declaredDependencies(true, true)
                : $package->manifest->dependencies;
            $packages[$name] = new LockedPackage(
                $package->manifest->package->name,
                $package->manifest->package->compilerIdentity,
                $package->manifest->package->version,
                $package->manifestFingerprint,
                $sourceRecord,
                $this->edges($declarations),
            );
        }

        return new WorkspaceLockFile($graph->workspaceFingerprint, $members, $packages);
    }

    /**
     * @param array<string, DependencyDeclaration> $declarations
     * @return list<LockedDependency>
     */
    private function edges(array $declarations): array
    {
        return array_map(
            static fn (DependencyDeclaration $declaration): LockedDependency => new LockedDependency(
                $declaration->package,
                $declaration->version?->expression,
                $declaration->kind,
            ),
            array_values($declarations),
        );
    }
}
