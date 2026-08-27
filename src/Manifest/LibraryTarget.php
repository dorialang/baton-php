<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class LibraryTarget implements PackageTarget
{
    public function __construct(public string $targetName)
    {
    }

    public function name(): string
    {
        return $this->targetName;
    }

    public function kind(): string
    {
        return 'library';
    }

    public function entry(): null
    {
        return null;
    }
}
