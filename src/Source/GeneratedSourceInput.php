<?php

declare(strict_types=1);

namespace Doria\Baton\Source;

/** Internal Slice-3 insertion boundary; Slice 1 never discovers or writes it. */
final readonly class GeneratedSourceInput
{
    public function __construct(
        public string $relativePath,
        public string $generatedFor,
        public ?string $contents,
        public ?string $existingPath,
        public string $contentHash,
        public ?string $producer = null,
        public ?string $owner = null,
    ) {
    }
}
