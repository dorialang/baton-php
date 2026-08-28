<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\DependencyOperations;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Project\ProjectLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'fetch', description: 'Populate exact locked dependency content')]
final class FetchCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addArgument('packages', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Locked packages to fetch');
        DependencyOptions::configureOffline($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $root = (new ProjectLocator())->locate(getcwd() ?: '.');
        $manifest = (new ManifestLoader())->load($root);
        if ($manifest instanceof Manifest) {
            throw new BatonError('B0335', 'Dependency Package Requires Schema 2', 'Schema-1 projects do not have dependency or lockfile semantics.');
        }
        /** @var list<string> $packages */
        $packages = $input->getArgument('packages');
        (new DependencyOperations())->fetch($root, $manifest, DependencyOptions::network($input), $packages);
        $output->writeln('<info>Locked dependency content is available.</info>');

        return self::SUCCESS;
    }
}
