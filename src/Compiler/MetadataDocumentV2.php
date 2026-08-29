<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

final readonly class MetadataDocumentV2
{
    /**
     * @param array<string, mixed> $selectedTarget
     * @param list<array<string, mixed>> $sources
     * @param list<array<string, mixed>> $attributeClasses
     * @param list<array<string, mixed>> $applications
     * @param list<MetadataCallable> $callables
     */
    public function __construct(
        public string $edition,
        public string $compilerRevision,
        public string $graphFingerprint,
        public array $selectedTarget,
        public array $sources,
        public array $attributeClasses,
        public array $applications,
        public array $callables,
    ) {
    }
}
