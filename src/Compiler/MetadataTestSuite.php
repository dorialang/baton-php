<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

final readonly class MetadataTestSuite
{
    /** @param list<string> $pathSegments */
    public function __construct(
        public string $identity,
        public string $displayName,
        public array $pathSegments,
        public string $package,
        public string $source,
        public ?string $parentSuite,
        public int $authoredOrdinal,
        public MetadataLocation $location,
        public MetadataLocation $callNameLocation,
        public MetadataLocation $descriptionLocation,
    ) {
    }
}
