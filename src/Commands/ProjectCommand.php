<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Project\ProjectDocumentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project', description: 'Emit the strict machine-facing project inventory')]
final class ProjectCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit project document schema 1 as JSON');
        WorkspaceOptions::configure($this, true);
        $this->addOption('development', null, InputOption::VALUE_NONE, 'Include development sources and edges');
        $this->addOption('offline', null, InputOption::VALUE_NONE, 'Use only exact locked cached dependencies');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        if (!(bool) $input->getOption('json')) {
            throw new BatonError(
                'B0399',
                'Project Output Format Is Required',
                '`baton project` currently requires `--json`.',
            );
        }
        $document = (new ProjectDocumentBuilder())->build(
            WorkspaceOptions::select($input, true, 'project'),
            (bool) $input->getOption('development'),
            (bool) $input->getOption('offline') ? NetworkPolicy::Offline : NetworkPolicy::Online,
        );
        $output->writeln(json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
