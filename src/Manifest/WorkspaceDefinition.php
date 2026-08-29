<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class WorkspaceDefinition
{
    /** @param list<string> $members */
    public function __construct(public array $members)
    {
    }
}
