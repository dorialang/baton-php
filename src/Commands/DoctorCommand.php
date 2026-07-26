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

/**
 * A minimal environment report (a first cut of plan B11). The full component
 * hash / toolchain-manifest checks land with the assembled distribution.
 */
#[AsCommand(
    name: 'doctor',
    description: 'Report the Baton and toolchain environment',
)]
final class DoctorCommand extends BatonCommand
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
        $this->line($output, 'PASS', 'Baton version', Application::VERSION);
        $this->line($output, 'PASS', 'Host platform', PHP_OS_FAMILY . ' / ' . php_uname('m'));
        $this->line($output, 'PASS', 'PHP runtime', PHP_VERSION);

        /** @var string|null $compilerOverride */
        $compilerOverride = $input->getOption('compiler');

        try {
            $doriac = (new ToolchainLocator($compilerOverride))->locate();
            $this->line($output, 'PASS', 'doriac path', $doriac);

            $result = (new CompilerAdapter($doriac))->capture(['--version']);
            if ($result->succeeded() && trim($result->stdout) !== '') {
                $this->line($output, 'PASS', 'doriac version', trim($result->stdout));
            } else {
                $this->line($output, 'WARNING', 'doriac version', 'could not query --version');
            }
        } catch (BatonError $error) {
            $this->line($output, 'FAIL', 'doriac', $error->heading);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function line(OutputInterface $output, string $status, string $label, string $value): void
    {
        $tag = match ($status) {
            'PASS' => '<info>PASS</info>',
            'WARNING' => '<comment>WARNING</comment>',
            default => '<error>FAIL</error>',
        };

        $output->writeln(sprintf('%s  %-16s %s', $tag, $label, $value));
    }
}
