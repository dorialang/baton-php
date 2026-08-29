<?php

declare(strict_types=1);

namespace Doria\Baton\Processor;

use Doria\Baton\Source\GeneratedSourceInput;

final readonly class ProcessorRunResult
{
    /**
     * @param list<GeneratedSourceInput> $sources
     * @param list<array<string, string>> $facts
     */
    public function __construct(
        public array $sources,
        public array $facts,
    ) {
    }
}
