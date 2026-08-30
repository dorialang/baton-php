<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\DependencyOperations;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;
use Doria\Baton\Workspace\ProjectEnvironmentFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'install', description: 'Install the exact project dependency graph')]
final class InstallCommand extends BatonCommand
{
    protected function configure(): void
    {
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
        $operations = new DependencyOperations();
        if ($environment->workspace !== null) {
            $operations->installWorkspace($environment->workspace, DependencyOptions::network($input));
        } elseif (!$manifest instanceof WorkspaceManifest) {
            $operations->install($root, $manifest, DependencyOptions::network($input));
        }
        $output->writeln('<info>Dependencies installed.</info>');

        return self::SUCCESS;
    }
}
