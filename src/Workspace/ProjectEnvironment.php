<?php

declare(strict_types=1);

namespace Doria\Baton\Workspace;

use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;

final readonly class ProjectEnvironment
{
    public function __construct(
        public string $commandRoot,
        public string $lockRoot,
        public Manifest|Schema2Manifest|WorkspaceManifest $manifest,
        public ?WorkspaceContext $workspace,
    ) {
    }
}
