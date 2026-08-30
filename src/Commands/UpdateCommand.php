<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\DependencyOperations;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;
use Doria\Baton\Workspace\ProjectEnvironmentFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'update', description: 'Resolve new exact dependency versions')]
final class UpdateCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addArgument('packages', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Packages to update');
        DependencyOptions::configureOffline($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $environment = (new ProjectEnvironmentFactory())->create(getcwd() ?: '.');
        $root = $environment->lockRoot;
        $manifest = $environment->manifest;
        if ($manifest instanceof Manifest) {
            throw new BatonError('B0335', 'Dependency Package Requires Schema 2', 'Schema-1 projects do not have dependency or lockfile semantics.');
        }
        /** @var list<string> $packages */
        $packages = $input->getArgument('packages');
        $operations = new DependencyOperations();
        if ($environment->workspace !== null) {
            $operations->updateWorkspace($environment->workspace, DependencyOptions::network($input), $packages);
        } elseif (!$manifest instanceof WorkspaceManifest) {
            $operations->update($root, $manifest, DependencyOptions::network($input), $packages);
        }
        $output->writeln('<info>Dependencies updated.</info>');

        return self::SUCCESS;
    }
}
