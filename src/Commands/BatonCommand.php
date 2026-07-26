<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Diagnostics\BatonError;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base command that renders {@see BatonError} in the Doria diagnostic style on
 * stderr and maps it to a failing exit code, so every command shares one
 * consistent error surface.
 */
abstract class BatonCommand extends Command
{
    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->handle($input, $output);
        } catch (BatonError $error) {
            $this->errorOutput($output)->writeln($error->render());

            return Command::FAILURE;
        }
    }

    abstract protected function handle(InputInterface $input, OutputInterface $output): int;

    protected function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
