<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

final readonly class BuildPlan
{
    /** @param array<string, mixed> $document */
    public function __construct(public array $document)
    {
    }

    public function json(): string
    {
        return json_encode(
            $this->document,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
