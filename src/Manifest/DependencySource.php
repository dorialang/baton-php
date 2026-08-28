<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

interface DependencySource
{
    public function kind(): string;

    public function describe(): string;
}
