<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\SourceInventory;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Toolchain\ToolchainSelection;

final readonly class Schema2ProjectContext
{
    /**
     * @param list<GeneratedSourceInput> $generatedSources
     * @param list<array<string, string>> $processorFacts
     */
    public function __construct(
        public string $projectRoot,
        public string $storageRoot,
        public Schema2Manifest $manifest,
        public SelectedPackageTarget $selected,
        public SourceInventory $inventory,
        public ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        public ?string $lockSha256,
        public ToolchainSelection $toolchain,
        public string $profile,
        public BuildLayout $layout,
        public WrittenBuildPlan $buildPlan,
        public array $generatedSources,
        public array $processorFacts,
    ) {
    }
}
