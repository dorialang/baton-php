<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

use Doria\Baton\Compiler\MetadataLocation;

final readonly class ExecutableTest
{
    /** @param list<string> $requiredEffects */
    public function __construct(
        public string $identity,
        public string $canonicalName,
        public string $source,
        public array $requiredEffects,
        public MetadataLocation $location,
    ) {
    }
}
