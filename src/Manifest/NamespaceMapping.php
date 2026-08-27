<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class NamespaceMapping
{
    public function __construct(
        public string $prefix,
        public string $path,
        public string $scope,
        public SourcePatternSet $patterns,
    ) {
    }
}
