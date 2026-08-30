<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class WorkspaceManifest
{
    public int $manifestVersion;

    public function __construct(public WorkspaceDefinition $workspace)
    {
        $this->manifestVersion = 2;
    }
}
