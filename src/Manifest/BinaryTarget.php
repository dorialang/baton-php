<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class BinaryTarget implements PackageTarget
{
    public function __construct(
        public string $targetName,
        public string $entryPath,
    ) {
    }

    public function name(): string
    {
        return $this->targetName;
    }

    public function kind(): string
    {
        return 'binary';
    }

    public function entry(): string
    {
        return $this->entryPath;
    }
}
