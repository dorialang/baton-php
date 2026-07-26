<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Diagnostics\BatonError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A recognized command that is not available in this build. It exists so the
 * command boundary is frozen (plan B0): invoking it produces a stage-aware
 * diagnostic instead of an "unknown command" error, and it never approximates
 * the future behaviour with a temporary convention.
 */
final class StageGatedCommand extends BatonCommand
{
    public function __construct(
        string $name,
        string $description,
        private readonly BatonError $diagnostic,
    ) {
        parent::__construct($name);
        $this->setDescription($description);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        throw $this->diagnostic;
    }
}
