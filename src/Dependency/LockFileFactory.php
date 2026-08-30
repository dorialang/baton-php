<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\DependencyDeclaration;

final class LockFileFactory
{
    public function fromGraph(ResolvedDependencyGraph $graph): LockFile
    {
        $packages = [];
        foreach ($graph->packages as $identity => $package) {
            $source = $package->source;
            if ($source->kind === 'path') {
                $sourceRecord = [
                    'kind' => 'path',
                    'path' => PortablePath::relative($graph->root, $source->root),
                ];
            } else {
                $selector = $source->selector;
                if ($source->url === null || $selector === null || $source->commit === null) {
                    throw new \LogicException('Resolved Git source is incomplete.');
                }
                $sourceRecord = [
                    'kind' => 'git',
                    'url' => $source->url,
                    'selector' => [
                        'kind' => $selector->kind,
                        'value' => $selector->value,
                    ],
                    'commit' => $source->commit,
                ];
            }
            $dependencies = $this->edges($package->manifest->dependencies);
            $packages[$identity] = new LockedPackage(
                $package->manifest->package->name,
                $package->manifest->package->compilerIdentity,
                $package->manifest->package->version,
                $package->manifestFingerprint,
                $sourceRecord,
                $dependencies,
            );
        }
        $rootDependencies = $this->edges($graph->manifest->declaredDependencyEdges(true, true));

        return new LockFile(
            $graph->manifest->package->name,
            $graph->manifest->package->compilerIdentity,
            $graph->manifest->package->version,
            $graph->manifestFingerprint,
            $rootDependencies,
            $packages,
        );
    }

    /**
     * @param list<DependencyDeclaration>|array<string, DependencyDeclaration> $declarations
     * @return list<LockedDependency>
     */
    private function edges(array $declarations): array
    {
        $edges = array_map(
            static fn (DependencyDeclaration $dependency): LockedDependency => new LockedDependency(
                $dependency->package,
                $dependency->version?->expression,
                $dependency->kind,
            ),
            array_values($declarations),
        );
        usort($edges, static fn (LockedDependency $left, LockedDependency $right): int => strcmp(
            $left->package . "\0" . $left->kind->value,
            $right->package . "\0" . $right->kind->value,
        ));

        return $edges;
    }
}
