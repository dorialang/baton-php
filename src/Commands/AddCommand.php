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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'add', description: 'Add and lock a normal dependency')]
final class AddCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED, 'Authored package name')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path relative to Baton.toml')
            ->addOption('git', null, InputOption::VALUE_REQUIRED, 'Canonical HTTPS or SSH Git URL')
            ->addOption('rev', null, InputOption::VALUE_REQUIRED, 'Git revision')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Git tag')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Git branch')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Package SemVer constraint');
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
        $dependency = (new DependencyInputFactory())->create($input);
        if (isset($manifest->dependencies[$dependency->package])) {
            throw new BatonError('B0380', 'Dependency Is Already Declared', "Package `{$dependency->package}` is already a direct dependency.");
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . ProjectLocator::MANIFEST_FILE;
        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            throw new BatonError('B0302', 'Project Manifest Could Not Be Read', "The manifest could not be read:\n    {$manifestPath}");
        }
        $proposedBytes = (new ManifestDependencyEditor())->add($contents, $dependency);
        $proposed = $loader->loadContents($root, $proposedBytes);
        if ($proposed instanceof Manifest) {
            throw new BatonError('B0335', 'Dependency Package Requires Schema 2');
        }
        $locks = new LockFileStore();
        $existing = $locks->load($root);
        $graph = (new DependencyResolver())->resolveFresh(
            $root,
            $proposed,
            DependencyOptions::network($input),
            $existing,
            [$dependency->package],
        );
        $lockBytes = (new LockFileFactory())->fromGraph($graph)->json();
        (new ProjectFileTransaction())->commit(
            $manifestPath,
            $proposedBytes,
            $root . DIRECTORY_SEPARATOR . LockFileStore::FILE,
            $lockBytes,
        );
        $output->writeln("<info>Added {$dependency->package}.</info>");

        return self::SUCCESS;
    }
}
