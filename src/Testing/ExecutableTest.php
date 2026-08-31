<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

use Doria\Baton\Compiler\MetadataLocation;

final readonly class ExecutableTest
{
    /**
     * @param list<string> $pathSegments
     * @param list<string> $suitePathIdentities
     * @param list<string> $requiredEffects
     * @param list<string> $ambientEffects
     */
    public function __construct(
        public string $identity,
        public string $displayName,
        public string $callableIdentity,
        public string $callableCanonicalName,
        public string $origin,
        public ?string $authoredSpelling,
        public ?string $suite,
        public array $pathSegments,
        public array $suitePathIdentities,
        public string $source,
        public int $authoredOrdinal,
        public array $requiredEffects,
        public array $ambientEffects,
        public MetadataLocation $location,
    ) {
    }
}
