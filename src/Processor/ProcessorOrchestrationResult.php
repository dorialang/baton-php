<?php

declare(strict_types=1);

namespace Doria\Baton\Processor;

use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceInventory;

final readonly class ProcessorOrchestrationResult
{
    /**
     * @param list<GeneratedSourceInput> $sources
     * @param list<array<string, string>> $facts
     */
    public function __construct(
        public SourceInventory $rootInventory,
        public ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        public array $sources,
        public array $facts,
    ) {
    }
}
