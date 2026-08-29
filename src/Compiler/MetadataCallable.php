<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

final readonly class MetadataCallable
{
    /**
     * @param list<array{index: int, name: string, type: string, ownership: string}> $parameters
     * @param list<string> $requiredEffects
     * @param list<string> $ambientEffects
     */
    public function __construct(
        public string $identity,
        public string $canonicalName,
        public string $kind,
        public string $package,
        public string $source,
        public string $access,
        public int $genericParameterCount,
        public array $parameters,
        public string $returnType,
        public array $requiredEffects,
        public array $ambientEffects,
        public MetadataLocation $location,
    ) {
    }
}
