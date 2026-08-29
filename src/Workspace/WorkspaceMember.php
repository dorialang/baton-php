<?php

declare(strict_types=1);

namespace Doria\Baton\Workspace;

use Doria\Baton\Manifest\Schema2Manifest;

final readonly class WorkspaceMember
{
    public function __construct(
        public string $root,
        public string $relativePath,
        public string $manifestPath,
        public Schema2Manifest $manifest,
    ) {
    }
}
