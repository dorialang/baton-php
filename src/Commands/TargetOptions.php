<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class TargetOptions
{
    public static function configure(Command $command): void
    {
        $command->addOption(
            'binary',
            null,
            InputOption::VALUE_REQUIRED,
            'Select a binary target by name',
        );
        $command->addOption(
            'library',
            null,
            InputOption::VALUE_NONE,
            'Select the package library target',
        );
    }

    /** @return array{0: string|null, 1: bool} */
    public static function read(InputInterface $input): array
    {
        /** @var string|null $binary */
        $binary = $input->getOption('binary');

        return [$binary, (bool) $input->getOption('library')];
    }
}
