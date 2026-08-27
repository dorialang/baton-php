<?php

declare(strict_types=1);

namespace Doria\Baton\Project;

final readonly class ResolvedProjectPath
{
    public function __construct(
        public string $relativePath,
        public string $localPath,
        public string $canonicalPath,
    ) {
    }
}
