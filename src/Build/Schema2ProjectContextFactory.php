<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Dependency\ManifestFingerprint;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\WorkspaceLockFileStore;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Inventory\ManagedInventoryStore;
use Doria\Baton\Processor\ProcessorOrchestrator;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Toolchain\ToolchainSelection;
use Doria\Baton\Workspace\WorkspaceContext;
use Symfony\Component\Console\Output\OutputInterface;

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
        ?WorkspaceContext $workspace = null,
        bool $development = false,
        ?OutputInterface $output = null,
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
        if ($workspace !== null) {
            $lock = (new WorkspaceLockFileStore())->require($workspace->root);
            $graph = (new DependencyResolver())->resolveWorkspace($workspace, $network, $lock, true);
            $lockPath = $workspace->root . DIRECTORY_SEPARATOR . LockFileStore::FILE;
            $lockSha256 = $this->hashLock($lockPath);
        } else {
            $locks = new LockFileStore();
            $lock = $locks->load($canonicalRoot);
            if ($manifest->declaredDependencies($development, true) !== [] && $lock === null) {
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
                    $development,
                    true,
                );
                $lockPath = $canonicalRoot . DIRECTORY_SEPARATOR . LockFileStore::FILE;
                $lockSha256 = $this->hashLock($lockPath);
            }
        }
        $layout = new BuildLayout(
            $workspace === null ? $canonicalRoot : $workspace->root,
            $toolchain->identity->target,
            $profile,
            $selected->name(),
            $workspace === null ? null : $manifest->package->compilerIdentity,
        );
        $processorRun = (new ProcessorOrchestrator())->run(
            $canonicalRoot,
            $workspace instanceof WorkspaceContext ? $workspace->root : $canonicalRoot,
            $manifest,
            $selected,
            $inventory,
            $generatedSources,
            $graph,
            $toolchain,
            $layout->directory,
            $network,
            $development,
            $output,
        );
        $generatedSources = [...$generatedSources, ...$processorRun->sources];
        $inventory = $processorRun->rootInventory;
        $graph = $processorRun->graph;
        $plan = (new BuildPlanBuilder())->build(
            $canonicalRoot,
            $manifest,
            $selected,
            $inventory,
            $profile === 'release' ? 'release' : 'fast',
            $graph,
            $development,
        );
        $written = (new BuildPlanWriter())->write($plan, $layout->buildPlan);

        $context = new Schema2ProjectContext(
            $canonicalRoot,
            $workspace instanceof WorkspaceContext ? $workspace->root : $canonicalRoot,
            $manifest,
            $selected,
            $inventory,
            $graph,
            $lockSha256,
            $toolchain,
            $profile,
            $layout,
            $written,
            $generatedSources,
            $processorRun->facts,
        );
        (new ManagedInventoryStore())->recordContext(
            $context->storageRoot,
            $context,
        );

        return $context;
    }

    private function hashLock(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new \Doria\Baton\Diagnostics\BatonError(
                'B0372',
                'Baton Lock Is Invalid',
                "Baton.lock could not be hashed:\n    {$path}",
            );
        }

        return $hash;
    }
}
