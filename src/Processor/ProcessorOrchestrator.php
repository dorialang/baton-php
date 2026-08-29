<?php

declare(strict_types=1);

namespace Doria\Baton\Processor;

use Doria\Baton\Build\ActivePackageResolver;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceInventory;
use Doria\Baton\Toolchain\ToolchainSelection;
use Symfony\Component\Console\Output\OutputInterface;

final class ProcessorOrchestrator
{
    /** @param list<GeneratedSourceInput> $existingGeneratedSources */
    public function run(
        string $root,
        string $storageRoot,
        Schema2Manifest $manifest,
        SelectedPackageTarget $selected,
        SourceInventory $inventory,
        array $existingGeneratedSources,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        ToolchainSelection $toolchain,
        string $planDirectory,
        NetworkPolicy $network,
        bool $development,
        ?OutputInterface $output,
    ): ProcessorOrchestrationResult {
        $rootInventory = $inventory;
        $packages = $graph->packages;
        $sources = [];
        $facts = [];

        $rootRun = $this->runOwner(
            $root,
            $storageRoot,
            $manifest,
            $selected,
            $inventory,
            $graph,
            $toolchain,
            $planDirectory,
            $network,
            $development,
            $output,
        );
        $rootGeneratedSources = [...$existingGeneratedSources, ...$rootRun->sources];
        if ($rootGeneratedSources !== []) {
            $rootInventory = (new SourceDiscovery($root))->discover($manifest, $selected, $rootGeneratedSources);
        }
        $sources = [...$sources, ...$rootRun->sources];
        $facts = [...$facts, ...$rootRun->facts];

        foreach ((new ActivePackageResolver())->resolve($manifest, $graph, $development) as $package) {
            if ($package->manifest->package->name === $manifest->package->name) {
                continue;
            }
            $target = $package->manifest->targets->library;
            if ($target === null) {
                continue;
            }
            $run = $this->runOwner(
                $package->source->root,
                $storageRoot,
                $package->manifest,
                new SelectedPackageTarget($target),
                $package->inventory,
                $graph,
                $toolchain,
                $planDirectory,
                $network,
                false,
                $output,
            );
            $packageInventory = $run->sources === []
                ? $package->inventory
                : (new SourceDiscovery($package->source->root))->discover(
                    $package->manifest,
                    new SelectedPackageTarget($target),
                    $run->sources,
                );
            $packages[$package->manifest->package->name] = new ResolvedPackage(
                $package->manifest,
                $package->source,
                $package->manifestFingerprint,
                $packageInventory,
            );
            $sources = [...$sources, ...$run->sources];
            $facts = [...$facts, ...$run->facts];
        }

        if (isset($packages[$manifest->package->name])) {
            $rootPackage = $packages[$manifest->package->name];
            $packages[$manifest->package->name] = new ResolvedPackage(
                $rootPackage->manifest,
                $rootPackage->source,
                $rootPackage->manifestFingerprint,
                $rootInventory,
            );
        }
        usort($facts, static fn (array $left, array $right): int => strcmp(
            $left['owner'] . "\0" . $left['processor'] . "\0" . $left['requestSha256'],
            $right['owner'] . "\0" . $right['processor'] . "\0" . $right['requestSha256'],
        ));
        $processedGraph = $graph instanceof ResolvedWorkspaceGraph
            ? new ResolvedWorkspaceGraph($graph->workspace, $graph->workspaceFingerprint, $packages)
            : new ResolvedDependencyGraph($graph->root, $graph->manifest, $graph->manifestFingerprint, $packages);

        return new ProcessorOrchestrationResult($rootInventory, $processedGraph, $sources, $facts);
    }

    private function runOwner(
        string $root,
        string $storageRoot,
        Schema2Manifest $manifest,
        SelectedPackageTarget $selected,
        SourceInventory $inventory,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        ToolchainSelection $toolchain,
        string $planDirectory,
        NetworkPolicy $network,
        bool $development,
        ?OutputInterface $output,
    ): ProcessorRunResult {
        $plan = $planDirectory . DIRECTORY_SEPARATOR . 'processor-base-'
            . substr(hash('sha256', $manifest->package->compilerIdentity), 0, 16) . '.json';

        return (new ProcessorPipeline())->run(
            $root,
            $storageRoot,
            $manifest,
            $selected,
            $inventory,
            $graph,
            $toolchain,
            $plan,
            $network,
            $development,
            $output,
        );
    }
}
