<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class AutoloadConfiguration
{
    /**
     * @param list<NamespaceMapping> $main
     * @param list<NamespaceMapping> $development
     */
    public function __construct(
        public array $main,
        public array $development,
    ) {
    }

    /** @return list<NamespaceMapping> */
    public function all(): array
    {
        return [...$this->main, ...$this->development];
    }
}
