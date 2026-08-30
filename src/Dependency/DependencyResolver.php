<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\DependencyDeclaration;
use Doria\Baton\Manifest\DependencyKind;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\GitSelector;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\PathDependencySource;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Workspace\WorkspaceContext;

final class DependencyResolver
{
    private ManifestFingerprint $fingerprints;

    private ManifestLoader $manifests;

    /** @var array<string, ResolvedPackage> */
    private array $resolved = [];

    /** @var array<string, string> */
    private array $active = [];

    /** @var array<string, string> */
    private array $roots = [];

    /** @var array<string, list<array{chain: string, constraint: string, source: string}>> */
    private array $requirements = [];

    /** @var array<string, true> */
    private array $sourceConflicts = [];

    /**
     * @var array<string, array{
     *     declaration: DependencyDeclaration,
     *     manifest: Schema2Manifest,
     *     chain: string
     * }>
     */
    private array $versionConflicts = [];

    /** @var array<string, ResolvedPackageSource> */
    private array $sourceCache = [];

    /** @var array<string, string> */
    private array $processorTargets = [];

    /** @var array<string, string> Canonical root to authored package identity. */
    private array $workspaceRoots = [];

    public function __construct(
        private readonly GitTransport $git = new GitClient(),
        ?DependencyCache $cache = null,
        ?ManifestLoader $manifests = null,
    ) {
        $this->cache = $cache ?? new DependencyCache((new CacheRootLocator())->locate());
        $this->manifests = $manifests ?? new ManifestLoader();
        $this->fingerprints = new ManifestFingerprint();
    }

    private readonly DependencyCache $cache;

    /**
     * @param list<string> $unlockedPackages
     * @param list<string> $selectedPackages
     */
    public function resolveWorkspace(
        WorkspaceContext $workspace,
        NetworkPolicy $network,
        ?WorkspaceLockFile $lock = null,
        bool $strictLock = false,
        array $unlockedPackages = [],
        array $selectedPackages = [],
    ): ResolvedWorkspaceGraph {
        $this->resolved = [];
        $this->active = [];
        $this->roots = [];
        $this->workspaceRoots = [];
        $this->requirements = [];
        $this->sourceConflicts = [];
        $this->versionConflicts = [];
        $this->sourceCache = [];
        $this->processorTargets = [];
        $workspaceFingerprint = (new WorkspaceFingerprint())->calculate($workspace);
        if ($strictLock) {
            if ($lock === null) {
                throw new \LogicException('Locked workspace resolution requires a workspace lock.');
            }
            $this->validateWorkspaceLock($workspace, $workspaceFingerprint, $lock);
        }
        foreach ($workspace->members as $member) {
            $name = $member->manifest->package->name;
            $this->roots[$member->root] = $name;
            $this->workspaceRoots[$member->root] = $name;
            foreach ($member->manifest->processors as $processor) {
                $this->processorTargets[$processor->package()] = $processor->binary;
            }
        }
        if ($selectedPackages === []) {
            foreach ($workspace->sortedMembers() as $member) {
                foreach ($member->manifest->declaredDependencyEdges(true, true) as $dependency) {
                    $this->visit(
                        $dependency,
                        $member->root,
                        [$member->manifest->package->name],
                        $workspace->root,
                        $member->manifest->package->name,
                        $network,
                        $lock,
                        $unlockedPackages,
                        $strictLock,
                    );
                }
            }
        } else {
            if (!$strictLock) {
                throw new \LogicException('Selected workspace resolution requires a strict lockfile.');
            }
            $visited = [];
            foreach ($selectedPackages as $package) {
                $this->visitLockedWorkspacePackage(
                    $package,
                    $workspace,
                    $lock,
                    $network,
                    $visited,
                );
            }
        }
        $this->throwResolutionConflicts();
        $selectedClosure = $selectedPackages === []
            ? null
            : array_fill_keys($this->lockedClosure($lock, $selectedPackages), true);
        foreach ($workspace->sortedMembers() as $member) {
            $name = $member->manifest->package->name;
            if ($selectedClosure !== null && !isset($selectedClosure[$name])) {
                continue;
            }
            if (isset($this->resolved[$name])) {
                continue;
            }
            $target = $member->manifest->targets->library
                ?? ($member->manifest->targets->binaries[0] ?? null);
            if ($target === null) {
                throw new \LogicException('A workspace member must declare a target.');
            }
            $inventory = (new SourceDiscovery($member->root))->discover(
                $member->manifest,
                new SelectedPackageTarget($target),
            );
            $this->resolved[$name] = new ResolvedPackage(
                $member->manifest,
                new ResolvedPackageSource('workspace', $member->root, $member->relativePath),
                $this->fingerprints->calculate($member->manifest),
                $inventory,
            );
            if ($lock !== null && $strictLock) {
                $locked = $lock->packages[$name] ?? null;
                if ($locked === null
                    || $locked->source['kind'] !== 'workspace'
                    || $locked->source['path'] !== $member->relativePath
                    || $locked->manifestFingerprint !== $this->fingerprints->calculate($member->manifest)
                ) {
                    throw $this->stale("Workspace member `{$name}` no longer matches Baton.lock.");
                }
            }
        }
        ksort($this->resolved, SORT_STRING);

        if ($lock !== null && $strictLock) {
            $resolved = array_keys($this->resolved);
            $locked = $selectedPackages === []
                ? array_keys($lock->packages)
                : $this->lockedClosure($lock, $selectedPackages);
            sort($resolved, SORT_STRING);
            sort($locked, SORT_STRING);
            if ($resolved !== $locked) {
                throw $this->stale('The locked workspace package set does not match the complete workspace graph.');
            }
        }

        return new ResolvedWorkspaceGraph(
            $workspace,
            $workspaceFingerprint,
            $this->resolved,
        );
    }

    /**
     * @param array<string, true> $visited
     */
    private function visitLockedWorkspacePackage(
        string $package,
        WorkspaceContext $workspace,
        WorkspaceLockFile $lock,
        NetworkPolicy $network,
        array &$visited,
    ): void {
        if (isset($visited[$package])) {
            return;
        }
        $visited[$package] = true;
        $locked = $lock->packages[$package]
            ?? throw new \LogicException("Selected locked workspace package `{$package}` is missing.");
        if ($locked->source['kind'] === 'workspace') {
            $member = $workspace->members[$package]
                ?? throw new \LogicException("Locked workspace member `{$package}` is missing.");
            foreach ($locked->dependencies as $dependency) {
                $dependencyPackage = $lock->packages[$dependency->package];
                if ($dependencyPackage->source['kind'] === 'workspace') {
                    $this->visitLockedWorkspacePackage(
                        $dependency->package,
                        $workspace,
                        $lock,
                        $network,
                        $visited,
                    );
                    continue;
                }
                $this->visit(
                    $this->declarationFromLock($dependencyPackage, $dependency->kind),
                    $workspace->root,
                    [$package],
                    $workspace->root,
                    $package,
                    $network,
                    $lock,
                    [],
                    true,
                );
            }

            return;
        }

        $kinds = [];
        foreach ($lock->packages as $candidate) {
            foreach ($candidate->dependencies as $dependency) {
                if ($dependency->package === $package) {
                    $kinds[$dependency->kind->value] = $dependency->kind;
                }
            }
        }
        if ($kinds === []) {
            $kinds[DependencyKind::Normal->value] = DependencyKind::Normal;
        }
        foreach ($kinds as $kind) {
            $this->visit(
                $this->declarationFromLock($locked, $kind),
                $workspace->root,
                ['workspace'],
                $workspace->root,
                'workspace',
                $network,
                $lock,
                [],
                true,
            );
        }
    }

    private function validateWorkspaceLock(
        WorkspaceContext $workspace,
        string $fingerprint,
        WorkspaceLockFile $lock,
    ): void {
        if ($lock->manifestFingerprint !== $fingerprint) {
            throw $this->stale('The workspace manifest or member set changed after Baton.lock was written.');
        }
        $actual = [];
        foreach ($workspace->sortedMembers() as $member) {
            $actual[] = [$member->manifest->package->name, $member->manifest->package->compilerIdentity, $member->relativePath];
        }
        $locked = array_map(
            static fn (WorkspaceLockedMember $member): array => [$member->package, $member->compilerPackage, $member->path],
            $lock->members,
        );
        if ($actual !== $locked) {
            throw $this->stale('Workspace membership no longer matches Baton.lock.');
        }
    }

    /** @param list<string> $unlockedPackages */
    public function resolveFresh(
        string $projectRoot,
        Schema2Manifest $manifest,
        NetworkPolicy $network,
        ?LockFile $pins = null,
        array $unlockedPackages = [],
        bool $development = false,
        bool $processors = false,
    ): ResolvedDependencyGraph {
        return $this->resolve(
            $projectRoot,
            $manifest,
            $network,
            $pins,
            $unlockedPackages,
            false,
            [],
            $development,
            $processors,
        );
    }

    public function resolveLocked(
        string $projectRoot,
        Schema2Manifest $manifest,
        LockFile $lock,
        NetworkPolicy $network,
        bool $development = false,
        bool $processors = false,
    ): ResolvedDependencyGraph {
        return $this->resolve(
            $projectRoot,
            $manifest,
            $network,
            $lock,
            [],
            true,
            [],
            $development,
            $processors,
        );
    }

    /** @param list<string> $selectedPackages */
    public function resolveLockedPackages(
        string $projectRoot,
        Schema2Manifest $manifest,
        LockFile $lock,
        NetworkPolicy $network,
        array $selectedPackages,
    ): ResolvedDependencyGraph {
        return $this->resolve(
            $projectRoot,
            $manifest,
            $network,
            $lock,
            [],
            true,
            $selectedPackages,
        );
    }

    /**
     * @param list<string> $unlockedPackages
     * @param list<string> $selectedPackages
     */
    private function resolve(
        string $projectRoot,
        Schema2Manifest $manifest,
        NetworkPolicy $network,
        ?LockFile $lock,
        array $unlockedPackages,
        bool $strictLock,
        array $selectedPackages = [],
        bool $development = false,
        bool $processors = false,
    ): ResolvedDependencyGraph {
        $canonicalRoot = realpath($projectRoot);
        if ($canonicalRoot === false) {
            throw $this->error('Path Dependency Could Not Be Read', "Project root could not be resolved:\n    {$projectRoot}");
        }
        $this->resolved = [];
        $this->active = [];
        $this->roots = [$canonicalRoot => $manifest->package->name];
        $this->requirements = [];
        $this->sourceConflicts = [];
        $this->versionConflicts = [];
        $this->sourceCache = [];
        $this->workspaceRoots = [];
        $this->processorTargets = array_map(
            static fn (\Doria\Baton\Manifest\ProcessorDeclaration $processor): string => $processor->binary,
            $manifest->processors,
        );
        $fingerprint = $this->fingerprints->calculate($manifest);
        if ($lock !== null && $strictLock) {
            $this->validateRootLock($manifest, $fingerprint, $lock);
        }

        if ($selectedPackages === []) {
            foreach ($manifest->declaredDependencyEdges($development, $processors) as $dependency) {
                $this->visit(
                    $dependency,
                    $canonicalRoot,
                    [$manifest->package->name],
                    $canonicalRoot,
                    $manifest->package->name,
                    $network,
                    $lock,
                    $unlockedPackages,
                    $strictLock,
                );
            }
        } else {
            if ($lock === null) {
                throw new \LogicException('Selected locked resolution requires a lockfile.');
            }
            foreach ($selectedPackages as $package) {
                $kind = DependencyKind::Normal;
                foreach ($lock->rootDependencies as $edge) {
                    if ($edge->package === $package) {
                        $kind = $edge->kind;
                        break;
                    }
                }
                $this->visit(
                    $this->declarationFromLock($lock->packages[$package], $kind),
                    $canonicalRoot,
                    [$manifest->package->name],
                    $canonicalRoot,
                    $manifest->package->name,
                    $network,
                    $lock,
                    [],
                    true,
                );
            }
        }

        $this->throwResolutionConflicts();

        if ($strictLock) {
            if ($lock === null) {
                throw new \LogicException('Locked resolution requires a lockfile.');
            }
            $activeRootPackages = array_map(
                static fn (DependencyDeclaration $dependency): string => $dependency->package,
                $manifest->declaredDependencyEdges($development, $processors),
            );
            $locked = $selectedPackages === []
                ? $this->lockedClosure($lock, $activeRootPackages)
                : $this->lockedClosure($lock, $selectedPackages);
            $resolved = array_keys($this->resolved);
            sort($locked, SORT_STRING);
            sort($resolved, SORT_STRING);
            if ($locked !== $resolved) {
                throw $this->stale('The locked package set does not match the reachable manifest graph.');
            }
        }

        $packages = $this->resolved;
        ksort($packages, SORT_STRING);

        return new ResolvedDependencyGraph(
            $canonicalRoot,
            $manifest,
            $fingerprint,
            $packages,
        );
    }

    private function declarationFromLock(
        LockedPackage $package,
        DependencyKind $kind = DependencyKind::Normal,
    ): DependencyDeclaration
    {
        if ($package->source['kind'] === 'path') {
            $source = new PathDependencySource($package->source['path']);
        } elseif ($package->source['kind'] === 'git') {
            $source = new GitDependencySource(
                $package->source['url'],
                GitSelector::parse(
                    $package->source['selector']['kind'],
                    $package->source['selector']['value'],
                ),
            );
        } else {
            throw new \LogicException('Workspace lock members require workspace-aware resolution.');
        }

        return new DependencyDeclaration($package->package, $source, null, $kind);
    }

    /**
     * @param list<string> $selectedPackages
     * @return list<string>
     */
    private function lockedClosure(LockFile|WorkspaceLockFile $lock, array $selectedPackages): array
    {
        $closure = [];
        $visit = function (string $package) use (&$visit, &$closure, $lock): void {
            if (isset($closure[$package])) {
                return;
            }
            $closure[$package] = true;
            foreach ($lock->packages[$package]->dependencies as $dependency) {
                $visit($dependency->package);
            }
        };
        foreach ($selectedPackages as $package) {
            $visit($package);
        }
        $packages = array_keys($closure);
        sort($packages, SORT_STRING);

        return $packages;
    }

    /**
     * @param list<string> $chain
     * @param list<string> $unlockedPackages
     */
    private function visit(
        DependencyDeclaration $declaration,
        string $declaringRoot,
        array $chain,
        string $rootProject,
        string $rootPackage,
        NetworkPolicy $network,
        LockFile|WorkspaceLockFile|null $lock,
        array $unlockedPackages,
        bool $strictLock,
    ): void {
        $packageName = $declaration->package;
        $chainText = implode(' -> ', [...$chain, $packageName]);
        $this->requirements[$packageName][] = [
            'chain' => $chainText,
            'constraint' => $declaration->version === null ? '(any)' : $declaration->version->expression,
            'source' => $declaration->source->describe(),
        ];
        if (isset($this->active[$packageName])) {
            $start = array_search($packageName, $chain, true);
            $cycle = [...array_slice($chain, is_int($start) ? $start : 0), $packageName];
            $sources = [];
            foreach (array_unique($cycle) as $cyclePackage) {
                $source = $this->active[$cyclePackage] ?? $declaration->source->describe();
                $sources[] = "- {$cyclePackage}: {$source}";
            }
            throw $this->error(
                'Dependency Cycle Was Found',
                'Package dependency cycle: ' . implode(' -> ', $cycle)
                    . "\nSources:\n" . implode("\n", $sources),
                'B0339',
            );
        }

        $locked = $lock?->packages[$packageName] ?? null;
        $preservedPin = $locked !== null
            && !$strictLock
            && !in_array($packageName, $unlockedPackages, true);
        $pinned = $locked !== null && ($strictLock || $preservedPin);
        $source = $this->resolveSource(
            $declaration,
            $declaringRoot,
            $rootProject,
            $network,
            $pinned ? $locked : null,
            $preservedPin,
        );
        if (($this->roots[$source->root] ?? null) === $rootPackage) {
            throw $this->error(
                'Dependency Cycle Was Found',
                "Package dependency cycle: {$rootPackage} -> {$packageName} -> {$rootPackage}\n"
                    . "Sources:\n- {$packageName}: {$declaration->source->describe()}",
                'B0339',
            );
        }
        $rootOwner = $this->roots[$source->root] ?? null;
        if ($rootOwner !== null && $rootOwner !== $packageName) {
            throw $this->error(
                'Dependency Source Substitution Was Found',
                "One package root claims both `{$rootOwner}` and `{$packageName}`:\n    {$source->root}",
                'B0338',
            );
        }
        if (isset($this->resolved[$packageName])) {
            $existing = $this->resolved[$packageName];
            if ($existing->source->identity() !== $source->identity()) {
                $this->sourceConflicts[$packageName] = true;

                return;
            }
            if ($declaration->version !== null
                && !$declaration->version->matches($existing->manifest->package->version)
            ) {
                if ($preservedPin) {
                    throw $this->broaderUpdate($packageName, 'its preserved version no longer satisfies the graph');
                }
                $this->versionConflicts[$packageName] ??= [
                    'declaration' => $declaration,
                    'manifest' => $existing->manifest,
                    'chain' => $chainText,
                ];
            }

            $selected = $this->dependencyTarget($declaration, $existing->manifest, $packageName, $chainText);
            if (!$existing->hasInventory($selected)) {
                $inventory = (new SourceDiscovery($existing->source->root))->discover(
                    $existing->manifest,
                    $selected,
                );
                $this->resolved[$packageName] = $existing->withInventory($selected, $inventory);
            }

            return;
        }
        $manifest = $this->dependencyManifest($source->root, $packageName);
        if ($manifest->package->name === $rootPackage) {
            throw $this->error(
                'Dependency Source Substitution Was Found',
                "Dependency `{$packageName}` resolves to the root package identity `{$rootPackage}`.",
                'B0338',
            );
        }
        if ($manifest->package->name !== $packageName) {
            throw $this->error(
                'Dependency Package Name Does Not Match',
                "Dependency key `{$packageName}` resolves to package `{$manifest->package->name}`.\nChain: {$chainText}",
                'B0334',
            );
        }
        $selected = $this->dependencyTarget($declaration, $manifest, $packageName, $chainText);
        if ($declaration->version !== null
            && !$declaration->version->matches($manifest->package->version)
        ) {
            if ($preservedPin) {
                throw $this->broaderUpdate($packageName, 'its preserved version no longer satisfies the graph');
            }
            $this->versionConflicts[$packageName] ??= [
                'declaration' => $declaration,
                'manifest' => $manifest,
                'chain' => $chainText,
            ];
        }
        $fingerprint = $this->fingerprints->calculate($manifest);
        if ($locked !== null && ($strictLock || $source->kind === 'git' && $pinned)) {
            $this->validateLockedPackage(
                $declaration,
                $source,
                $manifest,
                $fingerprint,
                $locked,
                $preservedPin,
            );
        }

        $this->roots[$source->root] = $packageName;
        $canonicalTarget = new SelectedPackageTarget($manifest->targets->library ?? $selected->target);
        $canonicalInventory = (new SourceDiscovery($source->root))->discover($manifest, $canonicalTarget);
        $resolved = (new ResolvedPackage($manifest, $source, $fingerprint, $canonicalInventory))
            ->withInventory($canonicalTarget, $canonicalInventory, true);
        if (!$resolved->hasInventory($selected)) {
            $resolved = $resolved->withInventory(
                $selected,
                (new SourceDiscovery($source->root))->discover($manifest, $selected),
            );
        }
        $this->resolved[$packageName] = $resolved;
        $this->active[$packageName] = $declaration->source->describe();
        foreach ($manifest->dependencies as $dependency) {
            $this->visit(
                $dependency,
                $source->root,
                [...$chain, $packageName],
                $rootProject,
                $rootPackage,
                $network,
                $lock,
                $unlockedPackages,
                $strictLock,
            );
        }
        unset($this->active[$packageName]);
    }

    private function dependencyTarget(
        DependencyDeclaration $declaration,
        Schema2Manifest $manifest,
        string $packageName,
        string $chain,
    ): SelectedPackageTarget {
        if ($declaration->kind !== DependencyKind::Processor) {
            $target = $manifest->targets->library;
            if ($target === null) {
                throw $this->error(
                    'Dependency Package Requires A Library Target',
                    "Dependency `{$packageName}` has no `[targets.library]`.\nChain: {$chain}",
                    'B0336',
                );
            }

            return new SelectedPackageTarget($target);
        }
        if ($manifest->workspace !== null) {
            throw $this->error(
                'Processor Package Cannot Declare A Workspace',
                "Processor package `{$packageName}` declares `[workspace]`.",
                'B0406',
            );
        }
        if ($manifest->processors !== []) {
            throw $this->error(
                'Processor Package Cannot Declare Processors',
                "Processor package `{$packageName}` declares processor recursion.",
                'B0407',
            );
        }
        $name = $this->processorTargets[$packageName] ?? null;
        $target = $name === null ? null : $manifest->targets->binary($name);
        if ($target === null) {
            throw $this->error(
                'Processor Binary Target Is Missing',
                "Processor package `{$packageName}` does not declare binary target `{$name}`.",
                'B0408',
            );
        }

        return new SelectedPackageTarget($target);
    }

    private function throwResolutionConflicts(): void
    {
        if ($this->sourceConflicts !== []) {
            $packages = array_keys($this->sourceConflicts);
            sort($packages, SORT_STRING);

            throw $this->conflict($packages[0], true);
        }
        if ($this->versionConflicts === []) {
            return;
        }
        $packages = array_keys($this->versionConflicts);
        sort($packages, SORT_STRING);
        $package = $packages[0];
        if (count($this->requirements[$package] ?? []) > 1) {
            throw $this->conflict($package);
        }
        $conflict = $this->versionConflicts[$package];

        throw $this->versionMismatch(
            $conflict['declaration'],
            $conflict['manifest'],
            $conflict['chain'],
        );
    }

    private function resolveSource(
        DependencyDeclaration $declaration,
        string $declaringRoot,
        string $rootProject,
        NetworkPolicy $network,
        ?LockedPackage $locked,
        bool $preservedPin,
    ): ResolvedPackageSource {
        $cacheKey = $declaringRoot . "\0" . $declaration->source->describe() . "\0"
            . ($locked === null ? 'fresh' : json_encode($locked->source, JSON_THROW_ON_ERROR));
        if (isset($this->sourceCache[$cacheKey])) {
            return $this->sourceCache[$cacheKey];
        }
        if ($declaration->source instanceof PathDependencySource) {
            $candidate = $declaringRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $declaration->source->path);
            $root = realpath($candidate);
            if ($root === false || !is_dir($root)) {
                throw $this->error(
                    'Path Dependency Could Not Be Read',
                    "Path dependency `{$declaration->package}` could not be read:\n    {$candidate}",
                    'B0340',
                );
            }
            if (!is_file($root . DIRECTORY_SEPARATOR . 'Baton.toml')) {
                throw $this->error(
                    'Path Dependency Manifest Is Missing',
                    "Path dependency `{$declaration->package}` has no Baton.toml:\n    {$root}",
                    'B0340',
                );
            }
            $portable = PortablePath::relative($rootProject, $root);
            if (($this->workspaceRoots[$root] ?? null) === $declaration->package) {
                return $this->sourceCache[$cacheKey] = new ResolvedPackageSource(
                    'workspace',
                    $root,
                    $portable,
                );
            }
            $expectedKind = ($this->workspaceRoots[$root] ?? null) === $declaration->package
                ? 'workspace'
                : 'path';
            if ($locked !== null
                && ($locked->source['kind'] !== $expectedKind
                    || $locked->source['path'] !== $portable)
            ) {
                throw $this->stale("Path source for `{$declaration->package}` changed.");
            }

            return $this->sourceCache[$cacheKey] = new ResolvedPackageSource('path', $root, $portable);
        }

        if (!$declaration->source instanceof GitDependencySource) {
            throw $this->error('Dependency Declaration Is Invalid', 'Unknown dependency source.', 'B0330');
        }
        if ($locked !== null) {
            if ($locked->source['kind'] !== 'git'
                || $locked->source['url'] !== $declaration->source->url
                || $locked->source['selector']['kind'] !== $declaration->source->selector->kind
                || $locked->source['selector']['value'] !== $declaration->source->selector->value
            ) {
                if ($preservedPin) {
                    throw $this->broaderUpdate($declaration->package, 'its preserved source no longer matches the graph');
                }
                throw $this->stale("Git source for `{$declaration->package}` changed.");
            }
            $commit = $locked->source['commit'];
        } else {
            $commit = $this->git->resolve($declaration->source, $network, $this->cache, true);
        }
        $root = $this->git->checkout($declaration->source->url, $commit, $network, $this->cache);

        return $this->sourceCache[$cacheKey] = new ResolvedPackageSource(
            'git',
            $root,
            null,
            $declaration->source->url,
            $declaration->source->selector,
            $commit,
        );
    }

    private function dependencyManifest(string $root, string $expected): Schema2Manifest
    {
        try {
            $manifest = $this->manifests->load($root);
        } catch (BatonError $error) {
            throw $this->error(
                'Path Dependency Could Not Be Read',
                "Dependency `{$expected}` has an invalid manifest.\n{$error->render()}",
                'B0340',
            );
        }
        if (!$manifest instanceof Schema2Manifest) {
            throw $this->error(
                'Dependency Package Requires Schema 2',
                "Dependency `{$expected}` must be a schema-2 package manifest, not a schema-1 package or virtual workspace.",
                'B0335',
            );
        }

        return $manifest;
    }

    private function validateRootLock(
        Schema2Manifest $manifest,
        string $fingerprint,
        LockFile $lock,
    ): void {
        $dependencies = $this->lockedEdges($manifest, true, true);
        if ($lock->rootPackage !== $manifest->package->name
            || $lock->rootCompilerPackage !== $manifest->package->compilerIdentity
            || $lock->rootVersion !== $manifest->package->version
            || $lock->rootManifestFingerprint !== $fingerprint
            || $lock->rootDependencies != $dependencies
        ) {
            throw $this->stale('The root manifest no longer matches Baton.lock.');
        }
    }

    private function validateLockedPackage(
        DependencyDeclaration $declaration,
        ResolvedPackageSource $source,
        Schema2Manifest $manifest,
        string $fingerprint,
        LockedPackage $locked,
        bool $preservedPin,
    ): void {
        $workspaceMember = isset($this->workspaceRoots[$source->root]);
        $dependencies = $this->lockedEdges($manifest, $workspaceMember, $workspaceMember);
        if ($locked->package !== $manifest->package->name
            || $locked->compilerPackage !== $manifest->package->compilerIdentity
            || $locked->version !== $manifest->package->version
            || $locked->manifestFingerprint !== $fingerprint
            || $locked->dependencies != $dependencies
            || ($source->kind === 'git' && ($locked->source['commit'] ?? null) !== $source->commit)
        ) {
            if ($preservedPin) {
                throw $this->broaderUpdate($declaration->package, 'its preserved package facts no longer match the graph');
            }
            throw $this->stale("Locked package `{$declaration->package}` no longer matches its manifest or source.");
        }
    }

    private function broaderUpdate(string $package, string $reason): BatonError
    {
        return new BatonError(
            'B0384',
            'Dependency Update Requires A Broader Update',
            "Package `{$package}` cannot remain pinned because {$reason}.",
            ['Include that package in the update or update the complete graph:'],
            ["baton update {$package}", 'baton update'],
        );
    }

    private function conflict(string $package, bool $sourceConflict = false): BatonError
    {
        $lines = ["Package `{$package}` has incompatible requirements:"];
        foreach ($this->requirements[$package] ?? [] as $requirement) {
            $lines[] = "- {$requirement['chain']} requires {$requirement['constraint']} from {$requirement['source']}";
        }

        return $this->error(
            $sourceConflict ? 'Dependency Source Substitution Was Found' : 'Dependency Versions Conflict',
            implode("\n", $lines),
            'B0338',
        );
    }

    private function versionMismatch(
        DependencyDeclaration $declaration,
        Schema2Manifest $manifest,
        string $chain,
    ): BatonError {
        return $this->error(
            'Dependency Version Does Not Match',
            "Package `{$declaration->package}` is {$manifest->package->version}, which does not satisfy "
                . "{$declaration->version?->expression}.\nChain: {$chain}",
            'B0337',
        );
    }

    /** @return list<LockedDependency> */
    private function lockedEdges(
        Schema2Manifest $manifest,
        bool $development = false,
        bool $processors = false,
    ): array
    {
        $edges = [];
        foreach ($manifest->declaredDependencyEdges($development, $processors) as $dependency) {
            $edges[] = new LockedDependency(
                $dependency->package,
                $dependency->version?->expression,
                $dependency->kind,
            );
        }
        usort($edges, static fn (LockedDependency $left, LockedDependency $right): int => strcmp(
            $left->package,
            $right->package,
        ));

        return $edges;
    }

    private function stale(string $detail): BatonError
    {
        return new BatonError(
            'B0373',
            'Baton Lock Is Stale',
            $detail,
            ['Resolve the intentional manifest or dependency change:'],
            ['baton update'],
        );
    }

    private function error(string $heading, string $body, string $code = 'B0338'): BatonError
    {
        return new BatonError($code, $heading, $body);
    }
}
