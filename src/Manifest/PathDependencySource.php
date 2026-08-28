<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class PathDependencySource implements DependencySource
{
    public function __construct(public string $path)
    {
    }

    public function kind(): string
    {
        return 'path';
    }

    public function describe(): string
    {
        return "path {$this->path}";
    }
}
