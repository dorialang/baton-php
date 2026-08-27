<?php

declare(strict_types=1);

namespace Doria\Baton\Source;

use Doria\Baton\Manifest\NamespaceMapping;

final readonly class DiscoveredSource
{
    public function __construct(
        public string $relativePath,
        public string $canonicalPath,
        public string $scope,
        public string $origin,
        public ?string $generatedFor,
        public ?NamespaceMapping $mapping,
    ) {
    }
}
