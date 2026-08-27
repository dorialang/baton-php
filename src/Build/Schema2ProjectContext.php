<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\SourceInventory;
use Doria\Baton\Toolchain\ToolchainSelection;

final readonly class Schema2ProjectContext
{
    public function __construct(
        public string $projectRoot,
        public Schema2Manifest $manifest,
        public SelectedPackageTarget $selected,
        public SourceInventory $inventory,
        public ToolchainSelection $toolchain,
        public string $profile,
        public BuildLayout $layout,
        public WrittenBuildPlan $buildPlan,
    ) {
    }
}
