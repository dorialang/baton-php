<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

interface PackageTarget
{
    public function name(): string;

    public function kind(): string;

    public function entry(): ?string;
}
