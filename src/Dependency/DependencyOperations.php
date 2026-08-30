<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Workspace\WorkspaceContext;

final readonly class DependencyOperations
{
    public function __construct(
        private DependencyResolver $resolver = new DependencyResolver(),
        private LockFileStore $locks = new LockFileStore(),
        private LockFileFactory $lockFactory = new LockFileFactory(),
    ) {
    }

    public function install(
        string $root,
        Schema2Manifest $manifest,
        NetworkPolicy $network,
    ): ResolvedDependencyGraph {
        $lock = $this->locks->load($root);
        if ($lock !== null) {
            return $this->resolver->resolveLocked($root, $manifest, $lock, $network, true, true);
        }
        $graph = $this->resolver->resolveFresh($root, $manifest, $network, development: true, processors: true);
        $this->locks->write($root, $this->lockFactory->fromGraph($graph));

        return $graph;
    }

    /** @param list<string> $selected */
    public function update(
        string $root,
        Schema2Manifest $manifest,
        NetworkPolicy $network,
        array $selected,
    ): ResolvedDependencyGraph {
        $existing = $this->locks->load($root);
        if ($selected !== [] && $existing === null) {
            throw new BatonError(
                'B0370',
                'Baton Lock Is Missing',
                'Selected updates require an existing Baton.lock.',
                ['Resolve the complete graph first:'],
                ['baton install'],
            );
        }
        if ($selected !== []) {
            $known = array_unique([
                ...array_keys($manifest->dependencies),
                ...array_keys($existing->packages),
            ]);
            $unknown = array_values(array_diff($selected, $known));
            if ($unknown !== []) {
                sort($known, SORT_STRING);
                throw new BatonError(
                    'B0383',
                    'Dependency Update Target Is Unknown',
                    'Unknown package(s): ' . implode(', ', $unknown)
                        . "\nKnown packages: " . implode(', ', $known),
                );
            }
        }
        $graph = $selected === []
            ? $this->resolver->resolveFresh($root, $manifest, $network, development: true, processors: true)
            : $this->resolver->resolveFresh(
                $root,
                $manifest,
                $network,
                $existing,
                $selected,
                true,
                true,
            );
        $this->locks->write($root, $this->lockFactory->fromGraph($graph));

        return $graph;
    }

    /** @param list<string> $selected */
    public function fetch(
        string $root,
        Schema2Manifest $manifest,
        NetworkPolicy $network,
        array $selected,
    ): ResolvedDependencyGraph {
        $lock = $this->locks->require($root);
        if ($selected !== []) {
            $unknown = array_values(array_diff($selected, array_keys($lock->packages)));
            if ($unknown !== []) {
                throw new BatonError(
                    'B0383',
                    'Dependency Fetch Target Is Unknown',
                    'Unknown locked package(s): ' . implode(', ', $unknown),
                );
            }
        }

        return $selected === []
            ? $this->resolver->resolveLocked($root, $manifest, $lock, $network, true, true)
            : $this->resolver->resolveLockedPackages($root, $manifest, $lock, $network, $selected);
    }

    public function installWorkspace(
        WorkspaceContext $workspace,
        NetworkPolicy $network,
    ): ResolvedWorkspaceGraph {
        $store = new WorkspaceLockFileStore();
        $lock = $store->load($workspace->root);
        if ($lock !== null) {
            return $this->resolver->resolveWorkspace($workspace, $network, $lock, true);
        }
        $graph = $this->resolver->resolveWorkspace($workspace, $network);
        $store->write($workspace->root, (new WorkspaceLockFileFactory())->fromGraph($graph));

        return $graph;
    }

    /** @param list<string> $selected */
    public function updateWorkspace(
        WorkspaceContext $workspace,
        NetworkPolicy $network,
        array $selected,
    ): ResolvedWorkspaceGraph {
        $store = new WorkspaceLockFileStore();
        $existing = $store->load($workspace->root);
        if ($selected !== [] && $existing === null) {
            throw new BatonError(
                'B0409',
                'Workspace Lock Is Missing',
                'Selected updates require an existing workspace Baton.lock.',
            );
        }
        if ($selected !== []) {
            $unknown = array_values(array_diff($selected, array_keys($existing->packages)));
            if ($unknown !== []) {
                throw new BatonError(
                    'B0383',
                    'Dependency Update Target Is Unknown',
                    'Unknown locked package(s): ' . implode(', ', $unknown),
                );
            }
        }
        $graph = $this->resolver->resolveWorkspace(
            $workspace,
            $network,
            $existing,
            false,
            $selected,
        );
        $store->write($workspace->root, (new WorkspaceLockFileFactory())->fromGraph($graph));

        return $graph;
    }

    /** @param list<string> $selected */
    public function fetchWorkspace(
        WorkspaceContext $workspace,
        NetworkPolicy $network,
        array $selected,
    ): ResolvedWorkspaceGraph {
        $lock = (new WorkspaceLockFileStore())->require($workspace->root);
        if ($selected !== []) {
            $unknown = array_values(array_diff($selected, array_keys($lock->packages)));
            if ($unknown !== []) {
                throw new BatonError(
                    'B0383',
                    'Dependency Fetch Target Is Unknown',
                    'Unknown locked package(s): ' . implode(', ', $unknown),
                );
            }
        }

        return $this->resolver->resolveWorkspace(
            $workspace,
            $network,
            $lock,
            true,
            [],
            $selected,
        );
    }
}
