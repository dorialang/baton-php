<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Source\SourceInventory;

final readonly class ResolvedPackage
{
    public function __construct(
        public Schema2Manifest $manifest,
        public ResolvedPackageSource $source,
        public string $manifestFingerprint,
        public SourceInventory $inventory,
    ) {
    }
}
