<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Manifest\NamespaceMapping;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\DiscoveredSource;
use Doria\Baton\Source\SourceInventory;

final class BuildPlanBuilder
{
    public function build(
        string $canonicalProjectRoot,
        Schema2Manifest $manifest,
        SelectedPackageTarget $selected,
        SourceInventory $inventory,
        string $nativeProfile,
        ?ResolvedDependencyGraph $graph = null,
    ): BuildPlan {
        $identity = $manifest->package->compilerIdentity;

        $activeScopes = ['main'];
        foreach ($inventory->sources as $source) {
            if ($source->scope === 'generated' && $source->generatedFor === 'main') {
                $activeScopes[] = 'generated';
                break;
            }
        }
        $entry = $selected->entry();
        $compilerIdentities = [];
        foreach ($graph === null ? [] : $graph->packages as $packageName => $package) {
            $compilerIdentities[$packageName] = $package->manifest->package->compilerIdentity;
        }
        $packages = [$this->package(
            $canonicalProjectRoot,
            $manifest,
            $inventory,
            $compilerIdentities,
            true,
        )];
        foreach ($graph?->sortedPackages() ?? [] as $dependency) {
            $packages[] = $this->package(
                $dependency->source->root,
                $dependency->manifest,
                $dependency->inventory,
                $compilerIdentities,
                false,
            );
        }
        /** @var list<array{identity: string, root: string, namespaceMappings: list<array<string, mixed>>, sources: list<array<string, mixed>>, dependencies: list<array<string, string>>}> $packages */
        usort(
            $packages,
            static fn (array $left, array $right): int => strcmp($left['identity'], $right['identity']),
        );

        return new BuildPlan([
            'schemaVersion' => 1,
            'edition' => $manifest->package->edition,
            'rootPackage' => $identity,
            'selectedTarget' => [
                'package' => $identity,
                'name' => $selected->name(),
                'kind' => $selected->kind(),
                'entrySource' => $entry === null ? null : $identity . ':' . str_replace('\\', '/', $entry),
                'activeScopes' => $activeScopes,
            ],
            'packages' => $packages,
            'compiler' => [
                'target' => 'native',
                'nativeProfile' => $nativeProfile,
                'targetTriple' => null,
            ],
        ]);
    }

    /**
     * @param array<string, string> $compilerIdentities
     * @return array{identity: string, root: string, namespaceMappings: list<array<string, mixed>>, sources: list<array<string, mixed>>, dependencies: list<array<string, string>>}
     */
    private function package(
        string $root,
        Schema2Manifest $manifest,
        SourceInventory $inventory,
        array $compilerIdentities,
        bool $includeDevelopment,
    ): array {
        /** @var list<array{prefix: string, path: string, scope: string, generatedFor: null}> $mappings */
        $mappings = array_map(
            static fn (NamespaceMapping $mapping): array => [
                'prefix' => $mapping->prefix,
                'path' => $mapping->path,
                'scope' => $mapping->scope,
                'generatedFor' => null,
            ],
            $includeDevelopment ? $manifest->autoload->all() : $manifest->autoload->main,
        );
        usort($mappings, static fn (array $left, array $right): int => strcmp(
            $left['scope'] . "\0" . $left['prefix'] . "\0" . $left['path'],
            $right['scope'] . "\0" . $right['prefix'] . "\0" . $right['path'],
        ));
        $identity = $manifest->package->compilerIdentity;
        /** @var list<array{identity: string, path: string, scope: string, origin: string, generatedFor: string|null}> $sources */
        $sources = array_map(
            static fn (DiscoveredSource $source): array => [
                'identity' => $identity . ':' . $source->relativePath,
                'path' => $source->relativePath,
                'scope' => $source->scope,
                'origin' => $source->origin,
                'generatedFor' => $source->generatedFor,
            ],
            array_values(array_filter(
                $inventory->sources,
                static fn (DiscoveredSource $source): bool => $includeDevelopment
                    || $source->scope !== 'development',
            )),
        );
        usort($sources, static fn (array $left, array $right): int => strcmp(
            $left['identity'],
            $right['identity'],
        ));
        /** @var list<array{package: string, kind: string}> $dependencies */
        $dependencies = array_map(
            static fn (string $package): array => [
                'package' => $compilerIdentities[$package] ?? $package,
                'kind' => 'normal',
            ],
            array_keys($manifest->dependencies),
        );
        usort($dependencies, static fn (array $left, array $right): int => strcmp(
            $left['package'],
            $right['package'],
        ));

        return [
            'identity' => $identity,
            'root' => $root,
            'namespaceMappings' => $mappings,
            'sources' => $sources,
            'dependencies' => $dependencies,
        ];
    }
}
