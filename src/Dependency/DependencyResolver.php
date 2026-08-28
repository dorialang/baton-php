<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\DependencyDeclaration;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\GitSelector;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\PathDependencySource;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\SourceDiscovery;

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

    /** @param list<string> $unlockedPackages */
    public function resolveFresh(
        string $projectRoot,
        Schema2Manifest $manifest,
        NetworkPolicy $network,
        ?LockFile $pins = null,
        array $unlockedPackages = [],
    ): ResolvedDependencyGraph {
        return $this->resolve(
            $projectRoot,
            $manifest,
            $network,
            $pins,
            $unlockedPackages,
            false,
        );
    }

    public function resolveLocked(
        string $projectRoot,
        Schema2Manifest $manifest,
        LockFile $lock,
        NetworkPolicy $network,
    ): ResolvedDependencyGraph {
        return $this->resolve($projectRoot, $manifest, $network, $lock, [], true);
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
        $fingerprint = $this->fingerprints->calculate($manifest);
        if ($lock !== null && $strictLock) {
            $this->validateRootLock($manifest, $fingerprint, $lock);
        }

        if ($selectedPackages === []) {
            foreach ($manifest->dependencies as $dependency) {
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
                $this->visit(
                    $this->declarationFromLock($lock->packages[$package]),
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
            $locked = $selectedPackages === []
                ? array_keys($lock->packages)
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

    private function declarationFromLock(LockedPackage $package): DependencyDeclaration
    {
        if ($package->source['kind'] === 'path') {
            $source = new PathDependencySource($package->source['path']);
        } else {
            $source = new GitDependencySource(
                $package->source['url'],
                GitSelector::parse(
                    $package->source['selector']['kind'],
                    $package->source['selector']['value'],
                ),
            );
        }

        return new DependencyDeclaration($package->package, $source, null);
    }

    /**
     * @param list<string> $selectedPackages
     * @return list<string>
     */
    private function lockedClosure(LockFile $lock, array $selectedPackages): array
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
        ?LockFile $lock,
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
        if ($source->root === $rootProject) {
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
        if ($manifest->targets->library === null) {
            throw $this->error(
                'Dependency Package Requires A Library Target',
                "Dependency `{$packageName}` has no `[targets.library]`.\nChain: {$chainText}",
                'B0336',
            );
        }
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
        $selected = new SelectedPackageTarget($manifest->targets->library);
        $inventory = (new SourceDiscovery($source->root))->discover($manifest, $selected);
        $resolved = new ResolvedPackage($manifest, $source, $fingerprint, $inventory);
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
            if ($locked !== null
                && ($locked->source['kind'] !== 'path'
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
        if ($manifest instanceof Manifest) {
            throw $this->error(
                'Dependency Package Requires Schema 2',
                "Dependency `{$expected}` uses manifest schema 1; dependency packages require schema 2.",
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
        $dependencies = $this->lockedEdges($manifest);
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
        $dependencies = $this->lockedEdges($manifest);
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
    private function lockedEdges(Schema2Manifest $manifest): array
    {
        $edges = [];
        foreach ($manifest->dependencies as $dependency) {
            $edges[] = new LockedDependency(
                $dependency->package,
                $dependency->version?->expression,
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
