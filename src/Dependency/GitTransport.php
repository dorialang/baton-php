<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\GitDependencySource;

interface GitTransport
{
    public function executable(): ?string;

    public function version(): ?string;

    public function resolve(
        GitDependencySource $source,
        NetworkPolicy $network,
        DependencyCache $cache,
        bool $refresh,
    ): string;

    public function checkout(
        string $url,
        string $commit,
        NetworkPolicy $network,
        DependencyCache $cache,
    ): string;
}
