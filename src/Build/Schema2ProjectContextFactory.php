<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Dependency\ManifestFingerprint;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Toolchain\ToolchainSelection;

final class Schema2ProjectContextFactory
{
    /**
     * @param list<GeneratedSourceInput> $generatedSources
     */
    public function create(
        string $projectRoot,
        Schema2Manifest $manifest,
        SelectedPackageTarget $selected,
        ToolchainSelection $toolchain,
        string $profile,
        array $generatedSources = [],
        NetworkPolicy $network = NetworkPolicy::Online,
    ): Schema2ProjectContext {
        $canonicalRoot = realpath($projectRoot);
        if ($canonicalRoot === false) {
            $canonicalRoot = $projectRoot;
        }
        $inventory = (new SourceDiscovery($canonicalRoot))->discover(
            $manifest,
            $selected,
            $generatedSources,
        );
        $locks = new LockFileStore();
        $lock = $locks->load($canonicalRoot);
        if ($manifest->dependencies !== [] && $lock === null) {
            $lock = $locks->require($canonicalRoot);
        }
        if ($lock === null) {
            $graph = new ResolvedDependencyGraph(
                $canonicalRoot,
                $manifest,
                (new ManifestFingerprint())->calculate($manifest),
                [],
            );
            $lockSha256 = null;
        } else {
            $graph = (new DependencyResolver())->resolveLocked(
                $canonicalRoot,
                $manifest,
                $lock,
                $network,
            );
            $lockPath = $canonicalRoot . DIRECTORY_SEPARATOR . LockFileStore::FILE;
            $lockSha256 = hash_file('sha256', $lockPath);
            if (!is_string($lockSha256)) {
                throw new \Doria\Baton\Diagnostics\BatonError(
                    'B0372',
                    'Baton Lock Is Invalid',
                    "Baton.lock could not be hashed:\n    {$lockPath}",
                );
            }
        }
        $layout = new BuildLayout(
            $canonicalRoot,
            $toolchain->identity->target,
            $profile,
            $selected->name(),
        );
        $plan = (new BuildPlanBuilder())->build(
            $canonicalRoot,
            $manifest,
            $selected,
            $inventory,
            $profile === 'release' ? 'release' : 'fast',
            $graph,
        );
        $written = (new BuildPlanWriter())->write($plan, $layout->buildPlan);

        return new Schema2ProjectContext(
            $canonicalRoot,
            $manifest,
            $selected,
            $inventory,
            $graph,
            $lockSha256,
            $toolchain,
            $profile,
            $layout,
            $written,
        );
    }
}
