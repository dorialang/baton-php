<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Application;
use Doria\Baton\Diagnostics\BatonError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $this->line($output, 'PASS', 'Baton version', Application::VERSION);
        $this->line($output, 'PASS', 'Host platform', PHP_OS_FAMILY . ' / ' . php_uname('m'));
        $this->line($output, 'PASS', 'PHP runtime', PHP_VERSION);

        try {
            $toolchain = CompilerOptions::locate($input);
            $this->line($output, 'PASS', 'doriac path', $toolchain->compilerPath);
            $this->line($output, 'PASS', 'doriac source', $toolchain->source);
            $this->line(
                $output,
                'PASS',
                'doriac version',
                $toolchain->identity->toolchainVersion
            );
            $this->line($output, 'PASS', 'doriac target', $toolchain->identity->target);
            $this->line(
                $output,
                $toolchain->manifest === null ? 'WARNING' : 'PASS',
                'toolchain manifest',
                $toolchain->manifestStatus()
            );
            $this->line(
                $output,
                $toolchain->manifest === null ? 'WARNING' : 'PASS',
                'component hash',
                $toolchain->hashStatus()
            );
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
