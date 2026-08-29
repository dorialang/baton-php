<?php

declare(strict_types=1);

namespace Doria\Baton\Project;

use Doria\Baton\Application;
use Doria\Baton\Build\ActivePackageResolver;
use Doria\Baton\Build\BuildPlanBuilder;
use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Dependency\ManifestFingerprint;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Dependency\ResolvedPackageSource;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Dependency\WorkspaceLockFileStore;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\LibraryTarget;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Processor\GeneratedSourceRegistry;
use Doria\Baton\Source\DiscoveredSource;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Source\SourceInventory;
use Doria\Baton\Workspace\ProjectSelection;
use Doria\Baton\Workspace\WorkspaceMember;

final class ProjectDocumentBuilder
{
    /** @return array<string, mixed> */
    public function build(ProjectSelection $selection, bool $development, NetworkPolicy $network): array
    {
        [$rootMember, $resolvedGraph, $workspaceFingerprint] = $this->resolve($selection, $network);
        $selected = new SelectedPackageTarget(new LibraryTarget('baton-tooling'));
        [$rootPackage, $resolvedGraph] = $this->ensureRootPackage($rootMember, $resolvedGraph, $selected);
        $visible = $this->visiblePackages($rootPackage, $resolvedGraph, $development, $selection->aggregate);
        $registry = $this->generatedRegistry($selection, $visible);
        [$rootPackage, $resolvedGraph, $visible] = $this->withGeneratedSources(
            $rootPackage,
            $resolvedGraph,
            $visible,
            $registry['inputs'],
            $selected,
            $development,
            $selection->aggregate,
        );

        $plan = (new BuildPlanBuilder())->build(
            $rootMember->root,
            $rootMember->manifest,
            $selected,
            $rootPackage->inventory,
            'fast',
            $resolvedGraph,
            $development,
            $selection->aggregate,
        )->document;
        $plan['selectedTarget'] = [
            'package' => $rootMember->manifest->package->compilerIdentity,
            'name' => 'baton-tooling',
            'kind' => 'library',
            'entrySource' => null,
            'activeScopes' => $this->activeScopes($rootPackage->inventory, $development),
        ];

        $lockPath = $selection->lockRoot . DIRECTORY_SEPARATOR . LockFileStore::FILE;
        $lockHash = $this->fileHash($lockPath, 'Baton Lock Is Invalid');
        $packages = $this->packageDocuments($visible, $development);
        $generated = $this->generatedDocuments($registry, $development);
        $inventoryHash = $this->hashValue([$packages, $generated]);
        $buildPlanHash = hash('sha256', $this->json($plan));

        return [
            'schemaVersion' => 1,
            'batonVersion' => Application::VERSION,
            'workspace' => $selection->workspace === null ? null : [
                'root' => $selection->workspace->root,
                'manifest' => $selection->workspace->root . DIRECTORY_SEPARATOR . 'Baton.toml',
                'lock' => ['path' => $lockPath, 'sha256' => $lockHash],
                'members' => array_map(
                    static fn (WorkspaceMember $member): array => [
                        'package' => $member->manifest->package->name,
                        'compilerPackage' => $member->manifest->package->compilerIdentity,
                        'root' => $member->root,
                        'manifest' => $member->manifestPath,
                    ],
                    $selection->workspace->sortedMembers(),
                ),
            ],
            'selection' => [
                'kind' => $selection->aggregate ? 'workspace' : 'package',
                'package' => $selection->aggregate ? null : $rootMember->manifest->package->name,
                'development' => $development,
            ],
            'packages' => $packages,
            'toolingBuildPlan' => $plan,
            'generatedSources' => $generated,
            'fingerprints' => [
                'workspace' => $workspaceFingerprint,
                'lock' => $lockHash,
                'inventory' => $inventoryHash,
                'buildPlan' => $buildPlanHash,
            ],
        ];
    }

    /** @return array{WorkspaceMember, ResolvedDependencyGraph|ResolvedWorkspaceGraph, string} */
    private function resolve(ProjectSelection $selection, NetworkPolicy $network): array
    {
        if ($selection->workspace !== null) {
            $members = $selection->aggregate
                ? $selection->workspace->sortedMembers()
                : array_values(array_filter(
                    $selection->workspace->sortedMembers(),
                    static fn (WorkspaceMember $member): bool => $member->root === $selection->projectRoot,
                ));
            $root = $members[0] ?? throw new \LogicException('Selected workspace member is missing.');
            $lock = (new WorkspaceLockFileStore())->require($selection->workspace->root);
            $graph = (new DependencyResolver())->resolveWorkspace(
                $selection->workspace,
                $network,
                $lock,
                true,
            );

            return [$root, $graph, $graph->workspaceFingerprint];
        }
        if (!$selection->manifest instanceof Schema2Manifest) {
            throw new BatonError(
                'B0399',
                'Project Inventory Requires Manifest Schema 2',
                '`baton project` requires a schema-2 package or workspace.',
            );
        }
        $manifest = $selection->manifest;
        $lockStore = new LockFileStore();
        $lock = $lockStore->load($selection->projectRoot);
        if ($manifest->declaredDependencies(true, true) !== [] && $lock === null) {
            $lock = $lockStore->require($selection->projectRoot);
        }
        $graph = $lock === null
            ? new ResolvedDependencyGraph(
                $selection->projectRoot,
                $manifest,
                (new ManifestFingerprint())->calculate($manifest),
                [],
            )
            : (new DependencyResolver())->resolveLocked(
                $selection->projectRoot,
                $manifest,
                $lock,
                $network,
                true,
                true,
            );
        $member = new WorkspaceMember(
            $selection->projectRoot,
            '.',
            $selection->projectRoot . DIRECTORY_SEPARATOR . 'Baton.toml',
            $manifest,
        );

        return [$member, $graph, $graph->manifestFingerprint];
    }

    /** @return array{ResolvedPackage, ResolvedDependencyGraph|ResolvedWorkspaceGraph} */
    private function ensureRootPackage(
        WorkspaceMember $root,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        SelectedPackageTarget $selected,
    ): array {
        $package = $graph->packages[$root->manifest->package->name] ?? new ResolvedPackage(
            $root->manifest,
            new ResolvedPackageSource('workspace', $root->root, $root->relativePath),
            (new ManifestFingerprint())->calculate($root->manifest),
            (new SourceDiscovery($root->root))->discover($root->manifest, $selected),
        );
        $packages = $graph->packages;
        $packages[$root->manifest->package->name] = $package;

        return [$package, $this->replacePackages($graph, $packages)];
    }

    /** @return array<string, ResolvedPackage> Authored package identity keyed. */
    private function visiblePackages(
        ResolvedPackage $root,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        bool $development,
        bool $aggregate,
    ): array {
        $packages = [$root->manifest->package->name => $root];
        foreach ((new ActivePackageResolver())->resolve($root->manifest, $graph, $development, $aggregate) as $package) {
            $packages[$package->manifest->package->name] = $package;
        }
        uasort($packages, static fn (ResolvedPackage $left, ResolvedPackage $right): int => strcmp(
            $left->manifest->package->compilerIdentity,
            $right->manifest->package->compilerIdentity,
        ));

        return $packages;
    }

    /**
     * @param array<string, ResolvedPackage> $visible
     * @return array{compilerRevision: string|null, inputs: array<string, list<GeneratedSourceInput>>, sources: list<array{identity: string, package: string, processor: string, path: string, generatedFor: string, sha256: string}>}
     */
    private function generatedRegistry(ProjectSelection $selection, array $visible): array
    {
        $owners = array_filter(
            $visible,
            static fn (ResolvedPackage $package): bool => $package->manifest->processors !== [],
        );
        if ($owners === []) {
            return ['compilerRevision' => null, 'inputs' => [], 'sources' => []];
        }
        $registry = (new GeneratedSourceRegistry())->requireValid($selection->lockRoot, $owners);

        return [
            'compilerRevision' => $registry['compilerRevision'],
            'inputs' => $registry['inputs'],
            'sources' => $registry['sources'],
        ];
    }

    /**
     * @param array<string, ResolvedPackage>            $visible
     * @param array<string, list<GeneratedSourceInput>> $generated
     * @return array{ResolvedPackage, ResolvedDependencyGraph|ResolvedWorkspaceGraph, array<string, ResolvedPackage>}
     */
    private function withGeneratedSources(
        ResolvedPackage $root,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        array $visible,
        array $generated,
        SelectedPackageTarget $rootTarget,
        bool $development,
        bool $aggregate,
    ): array {
        if ($generated === []) {
            return [$root, $graph, $visible];
        }
        $packages = $graph->packages;
        foreach ($visible as $name => $package) {
            $inputs = $generated[$name] ?? [];
            if ($inputs === []) {
                continue;
            }
            $target = $name === $root->manifest->package->name
                ? $rootTarget
                : new SelectedPackageTarget(
                    $package->manifest->targets->library
                        ?? ($package->manifest->targets->binaries[0]
                            ?? throw new \LogicException('A source-visible package must declare a target.')),
                );
            $inventory = (new SourceDiscovery($package->source->root))->discover(
                $package->manifest,
                $target,
                $inputs,
            );
            $replacement = new ResolvedPackage(
                $package->manifest,
                $package->source,
                $package->manifestFingerprint,
                $inventory,
            );
            $packages[$name] = $replacement;
            $visible[$name] = $replacement;
            if ($name === $root->manifest->package->name) {
                $root = $replacement;
            }
        }
        $graph = $this->replacePackages($graph, $packages);

        return [$root, $graph, $this->visiblePackages($root, $graph, $development, $aggregate)];
    }

    /** @param array<string, ResolvedPackage> $packages */
    private function replacePackages(
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        array $packages,
    ): ResolvedDependencyGraph|ResolvedWorkspaceGraph {
        return $graph instanceof ResolvedWorkspaceGraph
            ? new ResolvedWorkspaceGraph($graph->workspace, $graph->workspaceFingerprint, $packages)
            : new ResolvedDependencyGraph($graph->root, $graph->manifest, $graph->manifestFingerprint, $packages);
    }

    /**
     * @param array<string, ResolvedPackage> $visible
     * @return list<array<string, mixed>>
     */
    private function packageDocuments(array $visible, bool $development): array
    {
        $compilerIdentities = [];
        foreach ($visible as $name => $package) {
            $compilerIdentities[$name] = $package->manifest->package->compilerIdentity;
        }
        $packages = [];
        foreach ($visible as $package) {
            $includeDevelopment = $development && $package->source->kind === 'workspace';
            $dependencies = [];
            foreach ($package->manifest->declaredDependencies($includeDevelopment, false) as $dependency) {
                if (!isset($visible[$dependency->package])) {
                    continue;
                }
                $dependencies[] = [
                    'package' => $compilerIdentities[$dependency->package],
                    'kind' => $dependency->kind->value,
                ];
            }
            usort($dependencies, static fn (array $left, array $right): int => strcmp(
                $left['package'] . "\0" . $left['kind'],
                $right['package'] . "\0" . $right['kind'],
            ));
            $packages[] = [
                'package' => $package->manifest->package->name,
                'compilerPackage' => $package->manifest->package->compilerIdentity,
                'root' => $package->source->root,
                'manifest' => $package->source->root . DIRECTORY_SEPARATOR . 'Baton.toml',
                'manifestFingerprint' => $package->manifestFingerprint,
                'source' => $package->source->kind,
                'dependencies' => $dependencies,
                'sources' => $this->sourceDocuments($package, $includeDevelopment),
            ];
        }

        return $packages;
    }

    /** @return list<array<string, mixed>> */
    private function sourceDocuments(ResolvedPackage $package, bool $development): array
    {
        $sources = [];
        foreach ($package->inventory->sources as $source) {
            if (!$development
                && ($source->scope === 'development'
                    || $source->scope === 'generated' && $source->generatedFor === 'development')
            ) {
                continue;
            }
            $sources[] = [
                'identity' => $package->manifest->package->compilerIdentity . ':' . $source->relativePath,
                'path' => $source->canonicalPath,
                'scope' => $source->scope,
                'origin' => $source->origin,
                'generatedFor' => $source->generatedFor,
                'producer' => $source->producer,
                'sha256' => $this->sourceHash($source),
            ];
        }
        usort($sources, static fn (array $left, array $right): int => strcmp(
            (string) $left['identity'],
            (string) $right['identity'],
        ));

        return $sources;
    }

    private function sourceHash(DiscoveredSource $source): string
    {
        return $this->fileHash($source->canonicalPath, 'Project Source Could Not Be Hashed');
    }

    /**
     * @param array{compilerRevision: string|null, inputs: array<string, list<GeneratedSourceInput>>, sources: list<array{identity: string, package: string, processor: string, path: string, generatedFor: string, sha256: string}>} $registry
     * @return list<array<string, string>>
     */
    private function generatedDocuments(array $registry, bool $development): array
    {
        $sources = [];
        foreach ($registry['sources'] as $source) {
            if (!$development && $source['generatedFor'] === 'development') {
                continue;
            }
            $sources[] = [
                'identity' => $source['identity'],
                'package' => $source['package'],
                'processor' => $source['processor'],
                'path' => $source['path'],
                'generatedFor' => $source['generatedFor'],
                'sha256' => $source['sha256'],
                'compilerRevision' => $registry['compilerRevision'] ?? '',
            ];
        }

        return $sources;
    }

    /** @return list<string> */
    private function activeScopes(SourceInventory $inventory, bool $development): array
    {
        $scopes = $development ? ['main', 'development'] : ['main'];
        foreach ($inventory->sources as $source) {
            if ($source->scope === 'generated'
                && ($source->generatedFor === 'main' || $development && $source->generatedFor === 'development')
            ) {
                $scopes[] = 'generated';
                break;
            }
        }

        return $scopes;
    }

    private function fileHash(string $path, string $heading): string
    {
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new BatonError('B0399', $heading, "File could not be hashed:\n    {$path}");
        }

        return $hash;
    }

    /** @param mixed $value */
    private function hashValue(mixed $value): string
    {
        return hash('sha256', $this->json($value));
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
