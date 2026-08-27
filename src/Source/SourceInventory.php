<?php

declare(strict_types=1);

namespace Doria\Baton\Source;

final readonly class SourceInventory
{
    /** @param list<DiscoveredSource> $sources */
    public function __construct(public array $sources)
    {
    }
}
