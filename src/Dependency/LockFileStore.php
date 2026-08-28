<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\GitSelector;
use Doria\Baton\Manifest\GitUrl;
use Doria\Baton\Manifest\PackageIdentity;
use Doria\Baton\Manifest\PackageVersionConstraint;
use JsonException;
use UnexpectedValueException;

final class LockFileStore
{
    public const FILE = 'Baton.lock';

    public function load(string $projectRoot): ?LockFile
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . self::FILE;
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw $this->error('Baton Lock Is Invalid', "The lockfile could not be read:\n    {$path}");
        }
        try {
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw $this->error('Baton Lock Is Invalid', 'The lockfile is not valid JSON: ' . $error->getMessage());
        }
        if (!is_array($document) || array_is_list($document)) {
            throw $this->error('Baton Lock Is Invalid', 'The lockfile root must be an object.');
        }

        return $this->parse($this->object($document, 'lockfile'));
    }

    public function require(string $projectRoot): LockFile
    {
        $lock = $this->load($projectRoot);
        if ($lock === null) {
            throw new BatonError(
                'B0370',
                'Baton Lock Is Missing',
                'This project declares dependencies but has no Baton.lock.',
                ['Resolve and lock the dependency graph:'],
                ['baton install'],
            );
        }

        return $lock;
    }

    public function write(string $projectRoot, LockFile $lock): string
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . self::FILE;
        $bytes = $lock->json();
        $temporary = $projectRoot . DIRECTORY_SEPARATOR . '.Baton.lock.' . bin2hex(random_bytes(8)) . '.tmp';
        $this->writeCompleteFile($temporary, $bytes);
        if (!@rename($temporary, $path) && !$this->replaceOnWindows($temporary, $path)) {
            @unlink($temporary);
            throw new BatonError(
                'B0371',
                'Dependency Transaction Could Not Be Committed',
                "Baton.lock was not replaced. The prior project files were retained.\nPath: {$path}",
            );
        }

        return hash('sha256', $bytes);
    }

    private function writeCompleteFile(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw $this->commitError($path);
        }
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = @fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw $this->commitError($path);
                }
                $offset += $written;
            }
            if (!@fflush($handle)) {
                throw $this->commitError($path);
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw $this->commitError($path);
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($path);
            throw $error;
        }
        fclose($handle);
    }

    private function replaceOnWindows(string $temporary, string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || (!is_file($path) && !is_link($path))) {
            return false;
        }
        $backup = $path . '.' . bin2hex(random_bytes(8)) . '.bak';
        if (!@rename($path, $backup)) {
            return false;
        }
        if (@rename($temporary, $path)) {
            @unlink($backup);

            return true;
        }
        @rename($backup, $path);

        return false;
    }

    private function commitError(string $path): BatonError
    {
        return new BatonError(
            'B0371',
            'Dependency Transaction Could Not Be Committed',
            "Baton.lock was not replaced. The prior project files were retained.\nPath: {$path}",
        );
    }

    /** @param array<string, mixed> $document */
    private function parse(array $document): LockFile
    {
        $this->keys($document, ['schemaVersion', 'root', 'packages'], 'lockfile');
        if (($document['schemaVersion'] ?? null) !== 1) {
            throw $this->error('Baton Lock Schema Is Unsupported', 'Only Baton.lock schema 1 is supported.');
        }
        $root = $this->object($document['root'] ?? null, 'root');
        $this->keys($root, ['package', 'compilerPackage', 'version', 'manifestFingerprint', 'dependencies'], 'root');
        $packagesValue = $document['packages'] ?? null;
        if (!is_array($packagesValue) || !array_is_list($packagesValue)) {
            throw $this->error('Baton Lock Is Invalid', '`packages` must be an array.');
        }
        $packages = [];
        $packageOrder = [];
        foreach ($packagesValue as $index => $value) {
            $package = $this->object($value, "packages[{$index}]");
            $this->keys($package, ['package', 'compilerPackage', 'version', 'manifestFingerprint', 'source', 'dependencies'], "packages[{$index}]");
            $name = $this->string($package['package'] ?? null, "packages[{$index}].package");
            $compilerPackage = $this->string($package['compilerPackage'] ?? null, "packages[{$index}].compilerPackage");
            $this->packageIdentity($name, $compilerPackage, "packages[{$index}]");
            if (isset($packages[$name])) {
                throw $this->error('Baton Lock Is Invalid', "Package `{$name}` occurs more than once.");
            }
            $source = $this->source($package['source'] ?? null, "packages[{$index}].source");
            $dependencies = $this->edges($package['dependencies'] ?? null, "packages[{$index}].dependencies");
            $packages[$name] = new LockedPackage(
                $name,
                $compilerPackage,
                $this->semver($package['version'] ?? null, "packages[{$index}].version"),
                $this->digest($package['manifestFingerprint'] ?? null, "packages[{$index}].manifestFingerprint"),
                $source,
                $dependencies,
            );
            $packageOrder[] = $compilerPackage;
        }
        $sortedPackageOrder = $packageOrder;
        sort($sortedPackageOrder, SORT_STRING);
        if ($packageOrder !== $sortedPackageOrder) {
            throw $this->error('Baton Lock Is Invalid', 'Package entries must be ordered by compiler package identity.');
        }
        $rootDependencies = $this->edges($root['dependencies'] ?? null, 'root.dependencies');
        $allEdges = $rootDependencies;
        foreach ($packages as $package) {
            $allEdges = [...$allEdges, ...$package->dependencies];
        }
        foreach ($allEdges as $dependency) {
            if (!isset($packages[$dependency->package])) {
                throw $this->error('Baton Lock Is Invalid', "Dependency edge targets missing package `{$dependency->package}`.");
            }
        }
        $this->assertAcyclic($packages, $rootDependencies);

        $rootPackage = $this->string($root['package'] ?? null, 'root.package');
        $rootCompilerPackage = $this->string($root['compilerPackage'] ?? null, 'root.compilerPackage');
        $this->packageIdentity($rootPackage, $rootCompilerPackage, 'root');

        return new LockFile(
            $rootPackage,
            $rootCompilerPackage,
            $this->semver($root['version'] ?? null, 'root.version'),
            $this->digest($root['manifestFingerprint'] ?? null, 'root.manifestFingerprint'),
            $rootDependencies,
            $packages,
        );
    }

    /** @return array{kind: 'path', path: string}|array{kind: 'git', url: string, selector: array{kind: string, value: string}, commit: string} */
    private function source(mixed $value, string $path): array
    {
        $source = $this->object($value, $path);
        $kind = $this->string($source['kind'] ?? null, "{$path}.kind");
        if ($kind === 'path') {
            $this->keys($source, ['kind', 'path'], $path);
            $sourcePath = $this->string($source['path'] ?? null, "{$path}.path");
            $normalized = str_replace('\\', '/', $sourcePath);
            if ($normalized === ''
                || $normalized !== $sourcePath
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:\//', $normalized) === 1
                || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $normalized) === 1
                || str_contains($normalized, "\0")
            ) {
                throw $this->error('Baton Lock Is Invalid', "Locked path `{$sourcePath}` is not portable.");
            }

            return ['kind' => 'path', 'path' => $normalized];
        }
        if ($kind !== 'git') {
            throw $this->error('Baton Lock Is Invalid', "Unknown dependency source kind `{$kind}`.");
        }
        $this->keys($source, ['kind', 'url', 'selector', 'commit'], $path);
        $url = $this->string($source['url'] ?? null, "{$path}.url");
        try {
            if (GitUrl::canonicalize($url) !== $url) {
                throw new \UnexpectedValueException('not canonical');
            }
        } catch (\UnexpectedValueException) {
            throw $this->error('Baton Lock Is Invalid', 'Locked Git URL is invalid or not canonical.');
        }
        $selector = $this->object($source['selector'] ?? null, "{$path}.selector");
        $this->keys($selector, ['kind', 'value'], "{$path}.selector");
        $selectorKind = $this->string($selector['kind'] ?? null, "{$path}.selector.kind");
        $selectorValue = $this->string($selector['value'] ?? null, "{$path}.selector.value");
        try {
            GitSelector::parse($selectorKind, $selectorValue);
        } catch (UnexpectedValueException) {
            throw $this->error('Baton Lock Is Invalid', "Invalid Git selector `{$selectorKind}`.");
        }

        return [
            'kind' => 'git',
            'url' => $url,
            'selector' => [
                'kind' => $selectorKind,
                'value' => $selectorValue,
            ],
            'commit' => $this->commit($source['commit'] ?? null, "{$path}.commit"),
        ];
    }

    /** @return list<LockedDependency> */
    private function edges(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->error('Baton Lock Is Invalid', "`{$path}` must be an array.");
        }
        $packages = [];
        $order = [];
        foreach ($value as $index => $edgeValue) {
            $edge = $this->object($edgeValue, "{$path}[{$index}]");
            $this->keys($edge, ['package', 'kind', 'constraint'], "{$path}[{$index}]");
            if (($edge['kind'] ?? null) !== 'normal') {
                throw $this->error('Baton Lock Is Invalid', 'Slice 2 lock edges must be `normal`.');
            }
            $package = $this->string($edge['package'] ?? null, "{$path}[{$index}].package");
            try {
                PackageIdentity::compilerIdentity($package);
            } catch (UnexpectedValueException) {
                throw $this->error('Baton Lock Is Invalid', "Dependency edge package `{$package}` is invalid.");
            }
            if (isset($packages[$package])) {
                throw $this->error('Baton Lock Is Invalid', "Dependency edge `{$package}` is duplicated.");
            }
            $constraint = $edge['constraint'] ?? null;
            if ($constraint !== null) {
                if (!is_string($constraint)) {
                    throw $this->error('Baton Lock Is Invalid', "`{$path}[{$index}].constraint` must be a string or null.");
                }
                try {
                    PackageVersionConstraint::parse($constraint);
                } catch (UnexpectedValueException) {
                    throw $this->error('Baton Lock Is Invalid', "Dependency constraint `{$constraint}` is invalid.");
                }
            }
            $packages[$package] = new LockedDependency($package, $constraint);
            $order[] = $package;
        }
        $sortedOrder = $order;
        sort($sortedOrder, SORT_STRING);
        if ($order !== $sortedOrder) {
            throw $this->error('Baton Lock Is Invalid', "Dependency edges in `{$path}` must be ordered by package.");
        }

        return array_values($packages);
    }

    /**
     * @param array<string, LockedPackage> $packages
     * @param list<LockedDependency> $roots
     */
    private function assertAcyclic(array $packages, array $roots): void
    {
        $active = [];
        $done = [];
        foreach ($roots as $root) {
            $this->visitLockedPackage($root->package, [], $packages, $active, $done);
        }
    }

    /**
     * @param list<string> $chain
     * @param array<string, LockedPackage> $packages
     * @param array<string, true> $active
     * @param array<string, true> $done
     */
    private function visitLockedPackage(
        string $name,
        array $chain,
        array $packages,
        array &$active,
        array &$done,
    ): void {
        if (isset($active[$name])) {
            $start = array_search($name, $chain, true);
            $cycle = [...array_slice($chain, is_int($start) ? $start : 0), $name];
            throw $this->error('Baton Lock Is Invalid', 'Dependency cycle: ' . implode(' -> ', $cycle));
        }
        if (isset($done[$name])) {
            return;
        }
        $active[$name] = true;
        foreach ($packages[$name]->dependencies ?? [] as $dependency) {
            $this->visitLockedPackage($dependency->package, [...$chain, $name], $packages, $active, $done);
        }
        unset($active[$name]);
        $done[$name] = true;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function keys(array $value, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        $missing = array_diff($allowed, array_keys($value));
        if ($unknown !== [] || $missing !== []) {
            throw $this->error('Baton Lock Is Invalid', "Object `{$path}` has unknown or missing fields.");
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw $this->error('Baton Lock Is Invalid', "`{$path}` must be an object.");
        }

        return $this->stringKeyed($value, $path);
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value, string $path): array
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw $this->error('Baton Lock Is Invalid', "`{$path}` must use string object keys.");
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function packageIdentity(string $package, string $compilerPackage, string $path): void
    {
        try {
            $expected = PackageIdentity::compilerIdentity($package);
        } catch (UnexpectedValueException) {
            throw $this->error('Baton Lock Is Invalid', "`{$path}.package` is not a valid package identity.");
        }
        if ($compilerPackage !== $expected) {
            throw $this->error('Baton Lock Is Invalid', "`{$path}.compilerPackage` does not match `{$package}`.");
        }
    }

    private function semver(mixed $value, string $path): string
    {
        $version = $this->string($value, $path);
        if (preg_match('/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
            . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D', $version) !== 1
        ) {
            throw $this->error('Baton Lock Is Invalid', "`{$path}` must be a SemVer version.");
        }

        return $version;
    }

    private function string(mixed $value, string $path): string
    {
        if (!is_string($value) || $value === '') {
            throw $this->error('Baton Lock Is Invalid', "`{$path}` must be a non-empty string.");
        }

        return $value;
    }

    private function digest(mixed $value, string $path): string
    {
        $digest = $this->string($value, $path);
        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            throw $this->error('Baton Lock Is Invalid', "`{$path}` must be a lowercase SHA-256 digest.");
        }

        return $digest;
    }

    private function commit(mixed $value, string $path): string
    {
        $commit = $this->string($value, $path);
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw $this->error('Baton Lock Is Invalid', "`{$path}` must be a full lowercase Git commit.");
        }

        return $commit;
    }

    private function error(string $heading, string $body): BatonError
    {
        return new BatonError('B0372', $heading, $body);
    }
}
