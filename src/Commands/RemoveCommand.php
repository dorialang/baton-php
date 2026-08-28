<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\LockFileFactory;
use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Dependency\ManifestDependencyEditor;
use Doria\Baton\Dependency\ProjectFileTransaction;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Project\ProjectLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'remove', description: 'Remove a direct normal dependency')]
final class RemoveCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED, 'Direct package to remove');
        DependencyOptions::configureOffline($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $root = (new ProjectLocator())->locate(getcwd() ?: '.');
        $loader = new ManifestLoader();
        $manifest = $loader->load($root);
        if ($manifest instanceof Manifest) {
            throw new BatonError('B0335', 'Dependency Package Requires Schema 2', 'Schema-1 projects cannot declare dependencies.');
        }
        /** @var string $package */
        $package = $input->getArgument('package');
        if (!isset($manifest->dependencies[$package])) {
            throw new BatonError('B0381', 'Dependency Is Not Directly Declared', "Package `{$package}` is not a direct normal dependency.");
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . ProjectLocator::MANIFEST_FILE;
        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            throw new BatonError('B0302', 'Project Manifest Could Not Be Read', "The manifest could not be read:\n    {$manifestPath}");
        }
        $proposedBytes = (new ManifestDependencyEditor())->remove($contents, $package);
        $proposed = $loader->loadContents($root, $proposedBytes);
        if ($proposed instanceof Manifest) {
            throw new BatonError('B0335', 'Dependency Package Requires Schema 2');
        }
        $locks = new LockFileStore();
        $graph = (new DependencyResolver())->resolveFresh(
            $root,
            $proposed,
            DependencyOptions::network($input),
            $locks->load($root),
        );
        $lockBytes = (new LockFileFactory())->fromGraph($graph)->json();
        (new ProjectFileTransaction())->commit(
            $manifestPath,
            $proposedBytes,
            $root . DIRECTORY_SEPARATOR . LockFileStore::FILE,
            $lockBytes,
        );
        $output->writeln("<info>Removed {$package}.</info>");

        return self::SUCCESS;
    }
}
