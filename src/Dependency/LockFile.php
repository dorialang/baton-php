<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

final readonly class LockFile
{
    /**
     * @param list<LockedDependency> $rootDependencies
     * @param array<string, LockedPackage> $packages
     */
    public function __construct(
        public string $rootPackage,
        public string $rootCompilerPackage,
        public string $rootVersion,
        public string $rootManifestFingerprint,
        public array $rootDependencies,
        public array $packages,
    ) {
    }

    public function json(): string
    {
        $rootDependencies = $this->edges($this->rootDependencies);
        $packages = [];
        $sorted = array_values($this->packages);
        usort($sorted, static fn (LockedPackage $left, LockedPackage $right): int => strcmp(
            $left->compilerPackage,
            $right->compilerPackage,
        ));
        foreach ($sorted as $package) {
            $packages[] = [
                'package' => $package->package,
                'compilerPackage' => $package->compilerPackage,
                'version' => $package->version,
                'manifestFingerprint' => $package->manifestFingerprint,
                'source' => $package->source,
                'dependencies' => $this->edges($package->dependencies),
            ];
        }

        return json_encode([
            'schemaVersion' => 1,
            'root' => [
                'package' => $this->rootPackage,
                'compilerPackage' => $this->rootCompilerPackage,
                'version' => $this->rootVersion,
                'manifestFingerprint' => $this->rootManifestFingerprint,
                'dependencies' => $rootDependencies,
            ],
            'packages' => $packages,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param list<LockedDependency> $dependencies
     * @return list<array{package: string, kind: string, constraint: string|null}>
     */
    private function edges(array $dependencies): array
    {
        usort(
            $dependencies,
            static fn (LockedDependency $left, LockedDependency $right): int => strcmp(
                $left->package . "\0" . $left->kind->value,
                $right->package . "\0" . $right->kind->value,
            ),
        );

        return array_map(
            static fn (LockedDependency $dependency): array => [
                'package' => $dependency->package,
                'kind' => $dependency->kind->value,
                'constraint' => $dependency->constraint,
            ],
            $dependencies,
        );
    }
}
