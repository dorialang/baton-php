<?php

declare(strict_types=1);

namespace Doria\Baton\Processor;

use Doria\Baton\Dependency\PathContentFingerprint;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Manifest\SelectedPackageTarget;

final class ProcessorSourceIdentity
{
    public function calculate(ResolvedPackage $processor, string $binary): string
    {
        if ($processor->source->kind === 'git') {
            return $processor->source->identity();
        }
        $target = $processor->manifest->targets->binary($binary)
            ?? throw new \LogicException("Processor binary target `{$binary}` must exist.");

        return $processor->source->identity() . "\0content\0"
            . (new PathContentFingerprint())->calculate(
                $processor->manifestFingerprint,
                $processor->inventoryFor(new SelectedPackageTarget($target)),
            );
    }
}
