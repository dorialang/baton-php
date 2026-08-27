<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class SelectedPackageTarget
{
    public function __construct(public PackageTarget $target)
    {
    }

    public function name(): string
    {
        return $this->target->name();
    }

    public function kind(): string
    {
        return $this->target->kind();
    }

    public function entry(): ?string
    {
        return $this->target->entry();
    }

    public function isBinary(): bool
    {
        return $this->target instanceof BinaryTarget;
    }
}
