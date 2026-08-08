<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Project\ProjectLocator;
use Doria\Baton\Toolchain\Platform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'run',
    description: 'Compile and run the current project',
)]
final class RunCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'release',
                null,
                InputOption::VALUE_NONE,
                'Build and run an optimized release artifact',
            )
            ->addArgument(
                'arguments',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Arguments passed to the program after --',
            );
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
        $manifest = (new ManifestLoader())->load($projectRoot);
        $release = (bool) $input->getOption('release');

        $buildArguments = [];
        if ($release) {
            $buildArguments['--release'] = true;
        }
        /** @var string|null $compiler */
        $compiler = $input->getOption('compiler');
        if ($compiler !== null) {
            $buildArguments['--compiler'] = $compiler;
        }
        if ((bool) $input->getOption('development')) {
            $buildArguments['--development'] = true;
        }

        $buildInput = new ArrayInput($buildArguments);
        $buildInput->setInteractive($input->isInteractive());
        $buildOutput = $output->isVerbose() ? $output : new BufferedOutput();
        $buildExitCode = (new BuildCommand())->run($buildInput, $buildOutput);
        if ($buildExitCode !== self::SUCCESS) {
            if ($buildOutput instanceof BufferedOutput) {
                $diagnostic = $buildOutput->fetch();
                if ($diagnostic !== '') {
                    fwrite(STDERR, $diagnostic);
                }
            }

            return $buildExitCode;
        }

        $profile = $release ? 'release' : 'development';
        $artifact = $projectRoot
            . DIRECTORY_SEPARATOR
            . 'build'
            . DIRECTORY_SEPARATOR
            . Platform::host()->target()
            . DIRECTORY_SEPARATOR
            . $profile
            . DIRECTORY_SEPARATOR
            . $manifest->name
            . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        /** @var list<string> $programArguments */
        $programArguments = $input->getArgument('arguments');
        $process = new Process(
            [$artifact, ...$programArguments],
            $projectRoot,
            input: STDIN,
            timeout: null,
        );

        try {
            if (Process::isTtySupported()) {
                $process->setTty(true);

                return $process->run();
            }

            return $process->run(static function (string $type, string $buffer): void {
                fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
            });
        } catch (ProcessSignaledException $error) {
            return 128 + $error->getSignal();
        } catch (ProcessRuntimeException $error) {
            throw new BatonError(
                'B0404',
                'Built Program Could Not Be Started',
                "Failed to run:\n    {$artifact}\n\n{$error->getMessage()}",
                ['Rebuild the artifact, then run it again:'],
                ['baton build', 'baton run'],
            );
        }
    }
}
