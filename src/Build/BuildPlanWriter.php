<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

final readonly class BuildPlanWriter
{
    public function __construct(private AtomicFileWriter $files = new AtomicFileWriter())
    {
    }

    public function write(BuildPlan $plan, string $path): WrittenBuildPlan
    {
        $bytes = $plan->json();
        $hash = $this->files->write($path, $bytes, 'Build Plan Could Not Be Written');

        return new WrittenBuildPlan($path, $hash, $bytes);
    }
}
