<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'version',
    description: 'Show the Baton and compiler versions',
)]
final class VersionCommand extends BatonCommand
{
    protected function configure(): void
    {
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('baton ' . Application::VERSION);

        $toolchain = CompilerOptions::locate($input);
        $output->writeln('doriac ' . $toolchain->identity->toolchainVersion);

        return Command::SUCCESS;
    }
}
