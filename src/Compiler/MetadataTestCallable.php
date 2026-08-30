<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

final readonly class MetadataTestCallable
{
    public function __construct(
        public string $identity,
        public string $canonicalName,
    ) {
    }
}
