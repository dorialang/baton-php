<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class SourcePatternSet
{
    /**
     * @param non-empty-list<string> $include
     * @param list<string>           $exclude
     */
    public function __construct(
        public array $include,
        public array $exclude,
    ) {
    }
}
