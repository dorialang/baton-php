<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Schema2Manifest;

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
            return $this->resolver->resolveLocked($root, $manifest, $lock, $network);
        }
        $graph = $this->resolver->resolveFresh($root, $manifest, $network);
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
            ? $this->resolver->resolveFresh($root, $manifest, $network)
            : $this->resolver->resolveFresh($root, $manifest, $network, $existing, $selected);
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
            ? $this->resolver->resolveLocked($root, $manifest, $lock, $network)
            : $this->resolver->resolveLockedPackages($root, $manifest, $lock, $network, $selected);
    }
}
