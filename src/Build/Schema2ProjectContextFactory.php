<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

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
        );
        $written = (new BuildPlanWriter())->write($plan, $layout->buildPlan);

        return new Schema2ProjectContext(
            $canonicalRoot,
            $manifest,
            $selected,
            $inventory,
            $toolchain,
            $profile,
            $layout,
            $written,
        );
    }
}
