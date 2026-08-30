<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Build\AtomicFileWriter;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\DependencyKind;
use Doria\Baton\Manifest\GitSelector;
use Doria\Baton\Manifest\GitUrl;
use Doria\Baton\Manifest\PackageIdentity;
use Doria\Baton\Manifest\PackageVersionConstraint;

final class WorkspaceLockFileStore
{
    public function load(string $root): ?WorkspaceLockFile
    {
        $path = $root . DIRECTORY_SEPARATOR . LockFileStore::FILE;
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw $this->error('Workspace Lock Is Invalid', 'Baton.lock could not be read.');
        }
        try {
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw $this->error('Workspace Lock Is Invalid', $error->getMessage());
        }
        $rootObject = $this->object($document, 'lockfile');
        $this->keys($rootObject, ['schemaVersion', 'workspace', 'packages'], 'lockfile');
        if (($rootObject['schemaVersion'] ?? null) !== 2) {
            throw $this->error('Workspace Lock Schema Is Unsupported', 'A workspace requires Baton.lock schema 2.');
        }
        $workspace = $this->object($rootObject['workspace'] ?? null, 'workspace');
        $this->keys($workspace, ['manifestFingerprint', 'members'], 'workspace');
        $fingerprint = $this->digest($workspace['manifestFingerprint'] ?? null, 'workspace.manifestFingerprint');
        $members = $this->members($workspace['members'] ?? null);
        $packages = $this->packages($rootObject['packages'] ?? null);
        foreach ($members as $member) {
            if (!isset($packages[$member->package]) || $packages[$member->package]->source['kind'] !== 'workspace') {
                throw $this->error(
                    'Workspace Lock Is Invalid',
                    "Workspace member `{$member->package}` is missing its workspace package entry.",
                );
            }
        }
        $this->assertAcyclic($packages, array_map(
            static fn (WorkspaceLockedMember $member): string => $member->package,
            $members,
        ));

        return new WorkspaceLockFile($fingerprint, $members, $packages);
    }

    public function require(string $root): WorkspaceLockFile
    {
        return $this->load($root) ?? throw $this->error(
            'Workspace Lock Is Missing',
            'Resolve the complete workspace graph with `baton install`.',
            'B0409',
        );
    }

    public function write(string $root, WorkspaceLockFile $lock): string
    {
        return (new AtomicFileWriter())->write(
            $root . DIRECTORY_SEPARATOR . LockFileStore::FILE,
            $lock->json(),
            'Workspace Lock Could Not Be Written',
        );
    }

    /** @return list<WorkspaceLockedMember> */
    private function members(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->error('Workspace Lock Is Invalid', '`workspace.members` must be an array.');
        }
        $members = [];
        $seen = [];
        $order = [];
        foreach ($value as $index => $raw) {
            $member = $this->object($raw, "workspace.members[{$index}]");
            $this->keys($member, ['package', 'compilerPackage', 'path'], "workspace.members[{$index}]");
            $package = $this->string($member['package'] ?? null, "workspace.members[{$index}].package");
            $compiler = $this->string($member['compilerPackage'] ?? null, "workspace.members[{$index}].compilerPackage");
            $path = $this->portable($member['path'] ?? null, "workspace.members[{$index}].path", true);
            $this->identity($package, $compiler);
            if (isset($seen[$package]) || isset($seen["compiler\0{$compiler}"]) || isset($seen["path\0{$path}"])) {
                throw $this->error('Workspace Lock Is Invalid', 'Workspace member identity or path is duplicated.');
            }
            $seen[$package] = $seen["compiler\0{$compiler}"] = $seen["path\0{$path}"] = true;
            $members[] = new WorkspaceLockedMember($package, $compiler, $path);
            $order[] = $compiler;
        }
        $sorted = $order;
        sort($sorted, SORT_STRING);
        if ($sorted !== $order) {
            throw $this->error('Workspace Lock Is Invalid', 'Workspace members must be ordered by compiler package identity.');
        }

        return $members;
    }

    /** @return array<string, LockedPackage> */
    private function packages(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->error('Workspace Lock Is Invalid', '`packages` must be an array.');
        }
        $packages = [];
        $order = [];
        foreach ($value as $index => $raw) {
            $package = $this->object($raw, "packages[{$index}]");
            $this->keys($package, ['package', 'compilerPackage', 'version', 'manifestFingerprint', 'source', 'dependencies'], "packages[{$index}]");
            $name = $this->string($package['package'] ?? null, "packages[{$index}].package");
            $compiler = $this->string($package['compilerPackage'] ?? null, "packages[{$index}].compilerPackage");
            $this->identity($name, $compiler);
            if (isset($packages[$name])) {
                throw $this->error('Workspace Lock Is Invalid', "Package `{$name}` is duplicated.");
            }
            $packages[$name] = new LockedPackage(
                $name,
                $compiler,
                $this->semver($package['version'] ?? null, "packages[{$index}].version"),
                $this->digest($package['manifestFingerprint'] ?? null, "packages[{$index}].manifestFingerprint"),
                $this->source($package['source'] ?? null, "packages[{$index}].source"),
                $this->edges($package['dependencies'] ?? null, "packages[{$index}].dependencies"),
            );
            $order[] = $compiler;
        }
        $sorted = $order;
        sort($sorted, SORT_STRING);
        if ($sorted !== $order) {
            throw $this->error('Workspace Lock Is Invalid', 'Packages must be ordered by compiler package identity.');
        }
        foreach ($packages as $package) {
            foreach ($package->dependencies as $edge) {
                if (!isset($packages[$edge->package])) {
                    throw $this->error('Workspace Lock Is Invalid', "Dependency edge targets missing package `{$edge->package}`.");
                }
            }
        }
        return $packages;
    }

    /**
     * @param array<string, LockedPackage> $packages
     * @param list<string> $roots
     */
    private function assertAcyclic(array $packages, array $roots): void
    {
        $active = [];
        $done = [];
        foreach ($roots as $root) {
            $this->visitAcyclic($root, [], $packages, $active, $done);
        }
    }

    /**
     * @param list<string> $chain
     * @param array<string, LockedPackage> $packages
     * @param array<string, true> $active
     * @param array<string, true> $done
     */
    private function visitAcyclic(
        string $package,
        array $chain,
        array $packages,
        array &$active,
        array &$done,
    ): void {
        if (isset($active[$package])) {
            throw $this->error(
                'Workspace Lock Is Invalid',
                'Dependency cycle: ' . $this->cycle($chain, $package),
            );
        }
        if (isset($done[$package])) {
            return;
        }
        $active[$package] = true;
        foreach ($packages[$package]->dependencies ?? [] as $dependency) {
            $this->visitAcyclic($dependency->package, [...$chain, $package], $packages, $active, $done);
        }
        unset($active[$package]);
        $done[$package] = true;
    }

    /** @param list<string> $chain */
    private function cycle(array $chain, string $package): string
    {
        $start = array_search($package, $chain, true);
        $cycle = array_slice($chain, is_int($start) ? $start : 0);
        $cycle[] = $package;

        return implode(' -> ', $cycle);
    }

    /** @return array{kind: 'path'|'workspace', path: string}|array{kind: 'git', url: string, selector: array{kind: string, value: string}, commit: string} */
    private function source(mixed $value, string $path): array
    {
        $source = $this->object($value, $path);
        $kind = $this->string($source['kind'] ?? null, "{$path}.kind");
        if ($kind === 'path' || $kind === 'workspace') {
            $this->keys($source, ['kind', 'path'], $path);

            return ['kind' => $kind, 'path' => $this->portable($source['path'] ?? null, "{$path}.path", true)];
        }
        if ($kind !== 'git') {
            throw $this->error('Workspace Lock Is Invalid', "Unknown source kind `{$kind}`.");
        }
        $this->keys($source, ['kind', 'url', 'selector', 'commit'], $path);
        $url = $this->string($source['url'] ?? null, "{$path}.url");
        try {
            if (GitUrl::canonicalize($url) !== $url) {
                throw new \UnexpectedValueException();
            }
        } catch (\UnexpectedValueException) {
            throw $this->error('Workspace Lock Is Invalid', 'Locked Git URL is invalid.');
        }
        $selector = $this->object($source['selector'] ?? null, "{$path}.selector");
        $this->keys($selector, ['kind', 'value'], "{$path}.selector");
        $selectorKind = $this->string($selector['kind'] ?? null, "{$path}.selector.kind");
        $selectorValue = $this->string($selector['value'] ?? null, "{$path}.selector.value");
        try {
            GitSelector::parse($selectorKind, $selectorValue);
        } catch (\UnexpectedValueException) {
            throw $this->error('Workspace Lock Is Invalid', 'Locked Git selector is invalid.');
        }
        $commit = $this->string($source['commit'] ?? null, "{$path}.commit");
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw $this->error('Workspace Lock Is Invalid', 'Locked Git commit must be a lowercase 40-character hash.');
        }

        return [
            'kind' => 'git',
            'url' => $url,
            'selector' => ['kind' => $selectorKind, 'value' => $selectorValue],
            'commit' => $commit,
        ];
    }

    /** @return list<LockedDependency> */
    private function edges(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->error('Workspace Lock Is Invalid', "`{$path}` must be an array.");
        }
        $edges = [];
        $order = [];
        foreach ($value as $index => $raw) {
            $edge = $this->object($raw, "{$path}[{$index}]");
            $this->keys($edge, ['package', 'kind', 'constraint'], "{$path}[{$index}]");
            $package = $this->string($edge['package'] ?? null, "{$path}[{$index}].package");
            $kindValue = $this->string($edge['kind'] ?? null, "{$path}[{$index}].kind");
            $kind = DependencyKind::tryFrom($kindValue);
            if ($kind === null) {
                throw $this->error('Workspace Lock Is Invalid', "Unknown edge kind `{$kindValue}`.");
            }
            $constraint = $edge['constraint'] ?? null;
            if ($constraint !== null) {
                if (!is_string($constraint)) {
                    throw $this->error('Workspace Lock Is Invalid', 'Dependency constraint must be a string or null.');
                }
                try {
                    PackageVersionConstraint::parse($constraint);
                } catch (\UnexpectedValueException) {
                    throw $this->error('Workspace Lock Is Invalid', "Constraint `{$constraint}` is invalid.");
                }
            }
            $key = $package . "\0" . $kind->value;
            if (isset($edges[$key])) {
                throw $this->error('Workspace Lock Is Invalid', "Dependency edge `{$package}` [{$kind->value}] is duplicated.");
            }
            $edges[$key] = new LockedDependency($package, $constraint, $kind);
            $order[] = $key;
        }
        $sorted = $order;
        sort($sorted, SORT_STRING);
        if ($sorted !== $order) {
            throw $this->error('Workspace Lock Is Invalid', 'Dependency edges must be ordered by package then kind.');
        }

        return array_values($edges);
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw $this->error('Workspace Lock Is Invalid', "`{$path}` must be an object.");
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw $this->error('Workspace Lock Is Invalid', "`{$path}` must use string field names.");
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function keys(array $value, array $allowed, string $path): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw $this->error('Workspace Lock Is Invalid', "Unknown field `{$path}.{$key}`.");
            }
        }
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $value)) {
                throw $this->error('Workspace Lock Is Invalid', "Missing field `{$path}.{$key}`.");
            }
        }
    }

    private function string(mixed $value, string $path): string
    {
        if (!is_string($value) || $value === '') {
            throw $this->error('Workspace Lock Is Invalid', "`{$path}` must be a nonempty string.");
        }

        return $value;
    }

    private function portable(mixed $value, string $path, bool $allowDot): string
    {
        $string = $this->string($value, $path);
        if ((!$allowDot && $string === '.')
            || str_contains($string, "\0")
            || str_starts_with($string, '/')
            || preg_match('/^[A-Za-z]:\//', $string) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $string) === 1
            || in_array('..', explode('/', $string), true)
            || str_contains($string, '\\')
        ) {
            throw $this->error('Workspace Lock Is Invalid', "`{$path}` must be a portable workspace-relative path.");
        }

        return $string;
    }

    private function digest(mixed $value, string $path): string
    {
        $digest = $this->string($value, $path);
        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            throw $this->error('Workspace Lock Is Invalid', "`{$path}` must be a lowercase SHA-256 digest.");
        }

        return $digest;
    }

    private function semver(mixed $value, string $path): string
    {
        $version = $this->string($value, $path);
        if (preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
            . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version) !== 1
        ) {
            throw $this->error('Workspace Lock Is Invalid', "`{$path}` is not a valid version.");
        }

        return $version;
    }

    private function identity(string $package, string $compiler): void
    {
        try {
            if (PackageIdentity::compilerIdentity($package) !== $compiler) {
                throw new \UnexpectedValueException();
            }
        } catch (\UnexpectedValueException) {
            throw $this->error('Workspace Lock Is Invalid', "Package identity `{$package}` / `{$compiler}` is inconsistent.");
        }
    }

    private function error(string $heading, string $body, string $code = 'B0410'): BatonError
    {
        return new BatonError($code, $heading, $body);
    }
}
