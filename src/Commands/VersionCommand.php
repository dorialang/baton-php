<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Application;
use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Toolchain\ToolchainLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'version',
    description: 'Show the Baton and compiler versions',
)]
final class VersionCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'compiler',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to a doriac executable (development override)'
        );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('baton ' . Application::VERSION);

        /** @var string|null $compilerOverride */
        $compilerOverride = $input->getOption('compiler');

        try {
            $doriac = (new ToolchainLocator($compilerOverride))->locate();
            $result = (new CompilerAdapter($doriac))->capture(['--version']);
            $version = trim($result->stdout) !== '' ? trim($result->stdout) : 'unknown';
            $output->writeln($version);
        } catch (BatonError) {
            $output->writeln('doriac: not found');
        }

        return Command::SUCCESS;
    }
}
