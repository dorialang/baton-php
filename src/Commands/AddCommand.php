<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\LockFileFactory;
use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Dependency\WorkspaceLockFileFactory;
use Doria\Baton\Dependency\WorkspaceLockFileStore;
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
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Dependency source transport: path or git')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Path relative to Baton.toml')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Canonical HTTPS or SSH Git URL')
            ->addOption('git', null, InputOption::VALUE_REQUIRED, 'Legacy Git URL spelling; use --source git --url')
            ->addOption('rev', null, InputOption::VALUE_REQUIRED, 'Git revision')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Git tag')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Git branch')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Package SemVer constraint')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Add to development dependencies');
        DependencyOptions::configureOffline($this);
        WorkspaceOptions::configure($this, false);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $selection = WorkspaceOptions::select($input, false, 'add');
        $root = $selection->projectRoot;
        $loader = new ManifestLoader();
        $manifest = $selection->manifest;
        if ($manifest instanceof Manifest) {
            throw new BatonError('B0335', 'Dependency Package Requires Schema 2', 'Schema-1 projects cannot declare dependencies.');
        }
        $dependency = (new DependencyInputFactory())->create($input);
        if (!$manifest instanceof \Doria\Baton\Manifest\Schema2Manifest) {
            throw new BatonError('B0398', 'Workspace Package Selection Is Ambiguous', 'Select one package.');
        }
        if (isset($manifest->dependencies[$dependency->package])
            || isset($manifest->developmentDependencies[$dependency->package])
        ) {
            throw new BatonError('B0380', 'Dependency Is Already Declared', "Package `{$dependency->package}` is already directly declared.");
        }
        $manifestPath = $root . DIRECTORY_SEPARATOR . ProjectLocator::MANIFEST_FILE;
        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            throw new BatonError('B0302', 'Project Manifest Could Not Be Read', "The manifest could not be read:\n    {$manifestPath}");
        }
        $proposedBytes = (new ManifestDependencyEditor())->add(
            $contents,
            $dependency,
            $dependency->kind === \Doria\Baton\Manifest\DependencyKind::Development,
        );
        $proposed = $loader->loadContents($root, $proposedBytes);
        if (!$proposed instanceof \Doria\Baton\Manifest\Schema2Manifest) {
            throw new BatonError('B0335', 'Dependency Package Requires Schema 2');
        }
        if ($selection->workspace === null) {
            $locks = new LockFileStore();
            $graph = (new DependencyResolver())->resolveFresh(
                $root,
                $proposed,
                DependencyOptions::network($input),
                $locks->load($root),
                [$dependency->package],
                development: true,
                processors: true,
            );
            $lockBytes = (new LockFileFactory())->fromGraph($graph)->json();
        } else {
            $workspace = $selection->workspace->replacingMember($root, $proposed);
            $existing = (new WorkspaceLockFileStore())->load($workspace->root);
            $graph = (new DependencyResolver())->resolveWorkspace(
                $workspace,
                DependencyOptions::network($input),
                $existing,
                false,
                [$dependency->package],
            );
            $lockBytes = (new WorkspaceLockFileFactory())->fromGraph($graph)->json();
        }
        (new ProjectFileTransaction())->commit(
            $manifestPath,
            $proposedBytes,
            $selection->lockRoot . DIRECTORY_SEPARATOR . LockFileStore::FILE,
            $lockBytes,
        );
        $output->writeln("<info>Added {$dependency->package}.</info>");

        return self::SUCCESS;
    }
}
