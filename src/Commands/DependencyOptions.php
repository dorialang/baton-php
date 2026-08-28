<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\NetworkPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class DependencyOptions
{
    public static function configureOffline(Command $command): void
    {
        $command->addOption(
            'offline',
            null,
            InputOption::VALUE_NONE,
            'Use only live path dependencies and exact cached Git content',
        );
    }

    public static function network(InputInterface $input): NetworkPolicy
    {
        return (bool) $input->getOption('offline')
            ? NetworkPolicy::Offline
            : NetworkPolicy::Online;
    }
}
