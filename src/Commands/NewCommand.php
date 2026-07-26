<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Templates\TemplateRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'new',
    description: 'Generate a new Doria project',
)]
final class NewCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The project (and package) name');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');

        if (preg_match('/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $name) !== 1) {
            throw new BatonError(
                'B0105',
                'Invalid Project Name',
                "\"{$name}\" is not a valid package name. Use lowercase letters,\n"
                    . "digits, '-', or '_' (for example: hello-doria)."
            );
        }

        $destination = (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $name;
        if (file_exists($destination)) {
            throw new BatonError(
                'B0106',
                'Destination Already Exists',
                "A file or directory already exists at:\n    {$destination}"
            );
        }

        TemplateRenderer::projectTemplate()->renderTo($destination, ['name' => $name]);

        $output->writeln("Created Doria project <info>{$name}</info>");
        $output->writeln('');
        $output->writeln("  cd {$name}");
        $output->writeln('  baton check');
        $output->writeln('  baton run');

        return Command::SUCCESS;
    }
}
