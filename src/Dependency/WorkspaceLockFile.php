<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

final readonly class WorkspaceLockFile
{
    /**
     * @param list<WorkspaceLockedMember> $members
     * @param array<string, LockedPackage> $packages
     */
    public function __construct(
        public string $manifestFingerprint,
        public array $members,
        public array $packages,
    ) {
    }

    public function json(): string
    {
        $members = $this->members;
        usort($members, static fn (WorkspaceLockedMember $left, WorkspaceLockedMember $right): int => strcmp(
            $left->compilerPackage,
            $right->compilerPackage,
        ));
        $packages = array_values($this->packages);
        usort($packages, static fn (LockedPackage $left, LockedPackage $right): int => strcmp(
            $left->compilerPackage,
            $right->compilerPackage,
        ));

        return json_encode([
            'schemaVersion' => 2,
            'workspace' => [
                'manifestFingerprint' => $this->manifestFingerprint,
                'members' => array_map(
                    static fn (WorkspaceLockedMember $member): array => [
                        'package' => $member->package,
                        'compilerPackage' => $member->compilerPackage,
                        'path' => $member->path,
                    ],
                    $members,
                ),
            ],
            'packages' => array_map(fn (LockedPackage $package): array => [
                'package' => $package->package,
                'compilerPackage' => $package->compilerPackage,
                'version' => $package->version,
                'manifestFingerprint' => $package->manifestFingerprint,
                'source' => $package->source,
                'dependencies' => $this->edges($package->dependencies),
            ], $packages),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param list<LockedDependency> $edges
     * @return list<array{package: string, kind: string, constraint: string|null}>
     */
    private function edges(array $edges): array
    {
        usort($edges, static fn (LockedDependency $left, LockedDependency $right): int => strcmp(
            $left->package . "\0" . $left->kind->value,
            $right->package . "\0" . $right->kind->value,
        ));

        /** @var list<array{package: string, kind: string, constraint: string|null}> $document */
        $document = array_map(static fn (LockedDependency $edge): array => [
            'package' => $edge->package,
            'kind' => $edge->kind->value,
            'constraint' => $edge->constraint,
        ], $edges);

        return $document;
    }
}
