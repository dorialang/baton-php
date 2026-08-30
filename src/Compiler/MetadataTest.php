<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

final readonly class MetadataTest
{
    /** @param list<string> $pathSegments */
    public function __construct(
        public string $identity,
        public string $displayName,
        public array $pathSegments,
        public string $origin,
        public ?string $authoredSpelling,
        public string $package,
        public string $source,
        public ?string $suite,
        public string $target,
        public ?MetadataTestCallable $callable,
        public bool $executable,
        public ?string $shapeIssue,
        public int $authoredOrdinal,
        public MetadataLocation $location,
        public MetadataLocation $callNameLocation,
        public MetadataLocation $descriptionLocation,
    ) {
    }
}
