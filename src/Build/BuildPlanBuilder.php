<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Dependency\ResolvedPackage;
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
        ResolvedDependencyGraph|ResolvedWorkspaceGraph|null $graph = null,
        bool $development = false,
        bool $aggregateWorkspace = false,
    ): BuildPlan {
        $identity = $manifest->package->compilerIdentity;

        $activeScopes = $development ? ['main', 'development'] : ['main'];
        foreach ($inventory->sources as $source) {
            if ($source->scope === 'generated'
                && ($source->generatedFor === 'main' || $development && $source->generatedFor === 'development')
            ) {
                $activeScopes[] = 'generated';
                break;
            }
        }
        $entry = $selected->entry();
        $selectedEntryIdentity = $entry === null
            ? null
            : $identity . ':' . str_replace('\\', '/', $entry);
        $compilerIdentities = [];
        foreach ($graph === null ? [] : $graph->packages as $packageName => $package) {
            $compilerIdentities[$packageName] = $package->manifest->package->compilerIdentity;
        }
        $packages = [$this->package(
            $canonicalProjectRoot,
            $manifest,
            $inventory,
            $compilerIdentities,
            $development,
            $selectedEntryIdentity,
        )];
        foreach ((new ActivePackageResolver())->resolve(
            $manifest,
            $graph,
            $development,
            $aggregateWorkspace,
        ) as $dependency) {
            if ($dependency->manifest->package->name === $manifest->package->name) {
                continue;
            }
            $packages[] = $this->package(
                $dependency->source->root,
                $dependency->manifest,
                $dependency->inventory,
                $compilerIdentities,
                $development
                    && $graph instanceof ResolvedWorkspaceGraph
                    && $dependency->source->kind === 'workspace',
                $selectedEntryIdentity,
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
                'entrySource' => $selectedEntryIdentity,
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
        ?string $selectedEntryIdentity,
    ): array {
        /** @var list<array{prefix: string, path: string, scope: string, generatedFor: string|null}> $mappings */
        $mappings = array_map(
            static fn (NamespaceMapping $mapping): array => [
                'prefix' => $mapping->prefix,
                'path' => $mapping->path,
                'scope' => $mapping->scope,
                'generatedFor' => null,
            ],
            $includeDevelopment ? $manifest->autoload->all() : $manifest->autoload->main,
        );
        foreach ($inventory->sources as $source) {
            if ($source->scope !== 'generated'
                || $source->generatedFor === null
                || $source->producer === null
                || $source->generatedFor === 'development' && !$includeDevelopment
            ) {
                continue;
            }
            $surfaceMappings = $source->generatedFor === 'main'
                ? $manifest->autoload->main
                : $manifest->autoload->development;
            foreach ($surfaceMappings as $mapping) {
                $base = 'build/generated/' . $source->producer . '/' . $source->generatedFor . '/';
                $mappings[] = [
                    'prefix' => $mapping->prefix,
                    'path' => $base . ltrim($mapping->path, '/'),
                    'scope' => 'generated',
                    'generatedFor' => $source->generatedFor,
                ];
            }
        }
        usort($mappings, static fn (array $left, array $right): int => strcmp(
            $left['scope'] . "\0" . $left['prefix'] . "\0" . $left['path'],
            $right['scope'] . "\0" . $right['prefix'] . "\0" . $right['path'],
        ));
        $identity = $manifest->package->compilerIdentity;
        /** @var list<array{identity: string, path: string, scope: string, origin: string, generatedFor: string|null}> $sources */
        $sources = array_map(
            static function (DiscoveredSource $source) use ($identity, $selectedEntryIdentity): array {
                $sourceIdentity = $identity . ':' . $source->relativePath;

                return [
                    'identity' => $sourceIdentity,
                    'path' => $source->relativePath,
                    'scope' => $source->scope,
                    'origin' => BuildPlanSourceOrigin::resolve(
                        $source,
                        $sourceIdentity,
                        $selectedEntryIdentity,
                    ),
                    'generatedFor' => $source->generatedFor,
                ];
            },
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
            static fn (\Doria\Baton\Manifest\DependencyDeclaration $dependency): array => [
                'package' => $compilerIdentities[$dependency->package] ?? $dependency->package,
                'kind' => $dependency->kind->value,
            ],
            array_values($manifest->declaredDependencies($includeDevelopment, false)),
        );
        usort($dependencies, static fn (array $left, array $right): int => strcmp(
            $left['package'] . "\0" . $left['kind'],
            $right['package'] . "\0" . $right['kind'],
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
