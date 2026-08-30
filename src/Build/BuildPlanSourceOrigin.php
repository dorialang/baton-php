<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Source\DiscoveredSource;

final class BuildPlanSourceOrigin
{
    public static function resolve(
        DiscoveredSource $source,
        string $identity,
        ?string $selectedEntryIdentity,
    ): string {
        if ($source->origin !== 'entry' || $identity === $selectedEntryIdentity) {
            return $source->origin;
        }

        return $source->scope === 'generated' ? 'generated' : 'explicit';
    }
}
