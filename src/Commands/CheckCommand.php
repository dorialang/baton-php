<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Project\ProjectLocator;
use Doria\Baton\Toolchain\ToolchainLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'check',
    description: 'Type-check the current project without producing a binary',
)]
final class CheckCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'compiler',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to a doriac executable (development override)'
        );
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
        $manifest = (new ManifestLoader())->load($projectRoot);

        /** @var string|null $compilerOverride */
        $compilerOverride = $input->getOption('compiler');
        $doriac = (new ToolchainLocator($compilerOverride))->locate();

        // Run the compiler from the project root so its diagnostics carry the
        // project-relative entry path, and forward them (and the exit code)
        // unchanged.
        return (new CompilerAdapter($doriac))->passthrough(['check', $manifest->entry], $projectRoot);
    }
}
