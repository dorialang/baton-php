<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Toolchain\ToolchainLocator;
use Doria\Baton\Toolchain\ToolchainSelection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/** Defines and reads the shared compiler-selection options for Baton commands. */
final class CompilerOptions
{
    public static function configure(Command $command): void
    {
        $command
            ->addOption(
                'compiler',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to a doriac executable (explicit development override)'
            )
            ->addOption(
                'development',
                null,
                InputOption::VALUE_NONE,
                'Retained for bootstrap compatibility; development discovery is automatic'
            );
    }

    public static function locate(InputInterface $input): ToolchainSelection
    {
        /** @var string|null $compilerOverride */
        $compilerOverride = $input->getOption('compiler');

        return (new ToolchainLocator(
            $compilerOverride,
            true,
        ))->locate();
    }
}
