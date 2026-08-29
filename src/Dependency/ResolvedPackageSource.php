<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\GitSelector;

final readonly class ResolvedPackageSource
{
    public function __construct(
        public string $kind,
        public string $root,
        public ?string $path = null,
        public ?string $url = null,
        public ?GitSelector $selector = null,
        public ?string $commit = null,
    ) {
    }

    public function identity(): string
    {
        return match ($this->kind) {
            'path' => "path\0{$this->root}",
            'workspace' => "workspace\0{$this->root}",
            'git' => "git\0{$this->url}\0{$this->selector?->kind}\0{$this->selector?->value}\0{$this->commit}",
            default => $this->kind,
        };
    }
}
