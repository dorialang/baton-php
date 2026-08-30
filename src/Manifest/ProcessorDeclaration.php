<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class ProcessorDeclaration
{
    /** @param non-empty-list<string> $attributes */
    public function __construct(
        public DependencyDeclaration $dependency,
        public string $binary,
        public array $attributes,
    ) {
    }

    public function package(): string
    {
        return $this->dependency->package;
    }
}
