<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Workspace\ProjectEnvironmentFactory;
use Doria\Baton\Workspace\ProjectSelection;
use Doria\Baton\Workspace\ProjectSelector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class WorkspaceOptions
{
    public static function configure(Command $command, bool $allowWorkspace): void
    {
        $command->addOption('package', null, InputOption::VALUE_REQUIRED, 'Select a workspace package by manifest name');
        if ($allowWorkspace) {
            $command->addOption('workspace', null, InputOption::VALUE_NONE, 'Operate on every workspace package');
        }
    }

    public static function select(InputInterface $input, bool $allowWorkspace, string $command): ProjectSelection
    {
        /** @var string|null $package */
        $package = $input->getOption('package');
        $workspace = $allowWorkspace && (bool) $input->getOption('workspace');

        return (new ProjectSelector())->select(
            (new ProjectEnvironmentFactory())->create(getcwd() ?: '.'),
            $package,
            $workspace,
            $allowWorkspace,
            $command,
        );
    }
}
