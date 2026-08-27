<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Build\Schema2ProjectContextFactory;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\TargetSelector;
use Doria\Baton\Project\ProjectLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'check',
    description: 'Type-check the current project without producing a binary',
)]
final class CheckCommand extends BatonCommand
{
    protected function configure(): void
    {
        TargetOptions::configure($this);
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
        $manifest = (new ManifestLoader())->load($projectRoot);
        [$binary, $library] = TargetOptions::read($input);
        $selected = (new TargetSelector())->select($manifest, $binary, $library, 'check');
        $toolchain = CompilerOptions::locate($input);

        if ($manifest instanceof Manifest) {
            // Schema 1 deliberately retains its direct compiler boundary.
            return (new CompilerAdapter($toolchain->compilerPath))
                ->passthrough(['check', $manifest->entry], $projectRoot);
        }

        $context = (new Schema2ProjectContextFactory())->create(
            $projectRoot,
            $manifest,
            $selected,
            $toolchain,
            'development',
        );

        return (new CompilerAdapter($toolchain->compilerPath))->passthrough(
            ['check', '--build-plan', $context->buildPlan->path],
            $projectRoot,
        );
    }
}
