<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class GitDependencySource implements DependencySource
{
    public function __construct(
        public string $url,
        public GitSelector $selector,
    ) {
    }

    public function kind(): string
    {
        return 'git';
    }

    public function describe(): string
    {
        return "git {$this->url} ({$this->selector->describe()})";
    }
}
