<?php

declare(strict_types=1);

namespace Doria\Baton\Workspace;

use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\Schema2Manifest;

final readonly class ProjectSelection
{
    public function __construct(
        public string $projectRoot,
        public string $lockRoot,
        public Manifest|Schema2Manifest|null $manifest,
        public ?WorkspaceContext $workspace,
        public bool $aggregate,
    ) {
    }
}
