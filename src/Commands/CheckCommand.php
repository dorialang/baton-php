<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Manifest\ManifestLoader;
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
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
        $manifest = (new ManifestLoader())->load($projectRoot);

        $toolchain = CompilerOptions::locate($input);

        // Run the compiler from the project root so its diagnostics carry the
        // project-relative entry path, and forward them (and the exit code)
        // unchanged.
        return (new CompilerAdapter($toolchain->compilerPath))
            ->passthrough(['check', $manifest->entry], $projectRoot);
    }
}
