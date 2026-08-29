<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Build\Schema2BuildService;
use Doria\Baton\Build\Schema2ProjectContextFactory;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\TargetSelector;
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
        TargetOptions::configure($this);
        CompilerOptions::configure($this);
        DependencyOptions::configureOffline($this);
        WorkspaceOptions::configure($this, false);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $selection = WorkspaceOptions::select($input, false, 'run');
        $projectRoot = $selection->projectRoot;
        $manifest = $selection->manifest;
        if ($manifest === null) {
            throw new \LogicException('Package run requires one selected package.');
        }
        [$binary, $library] = TargetOptions::read($input);
        $selected = (new TargetSelector())->select($manifest, $binary, $library, 'run');
        $release = (bool) $input->getOption('release');

        if ($manifest instanceof Manifest) {
            $build = $this->buildSchema1($input, $output, $projectRoot, $manifest, $release);
            if ($build['exitCode'] !== self::SUCCESS) {
                return $build['exitCode'];
            }
            $artifact = $build['artifact'];
        } else {
            $toolchain = CompilerOptions::locate($input);
            $context = (new Schema2ProjectContextFactory())->create(
                $projectRoot,
                $manifest,
                $selected,
                $toolchain,
                $release ? 'release' : 'development',
                network: DependencyOptions::network($input),
                workspace: $selection->workspace,
                output: $output,
            );
            $buildOutput = $output->isVerbose() ? $output : new BufferedOutput();
            $buildExitCode = (new Schema2BuildService())->build($context, $buildOutput);
            if ($buildExitCode !== self::SUCCESS) {
                $this->forwardBuildFailure($buildOutput);

                return $buildExitCode;
            }
            $artifact = $context->layout->artifact;
        }

        /** @var list<string> $programArguments */
        $programArguments = $input->getArgument('arguments');

        return $this->runArtifact($artifact, $programArguments, $projectRoot);
    }

    /** @return array{exitCode: int, artifact: string} */
    private function buildSchema1(
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
        Manifest $manifest,
        bool $release,
    ): array {
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
        if ((bool) $input->getOption('offline')) {
            $buildArguments['--offline'] = true;
        }

        $buildInput = new ArrayInput($buildArguments);
        $buildInput->setInteractive($input->isInteractive());
        $buildOutput = $output->isVerbose() ? $output : new BufferedOutput();
        $buildExitCode = (new BuildCommand())->run($buildInput, $buildOutput);
        if ($buildExitCode !== self::SUCCESS) {
            $this->forwardBuildFailure($buildOutput);

            return ['exitCode' => $buildExitCode, 'artifact' => ''];
        }

        return [
            'exitCode' => self::SUCCESS,
            'artifact' => $projectRoot
                . DIRECTORY_SEPARATOR . 'build'
                . DIRECTORY_SEPARATOR . Platform::host()->target()
                . DIRECTORY_SEPARATOR . ($release ? 'release' : 'development')
                . DIRECTORY_SEPARATOR . $manifest->name
                . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''),
        ];
    }

    private function forwardBuildFailure(OutputInterface $output): void
    {
        if ($output instanceof BufferedOutput) {
            $diagnostic = $output->fetch();
            if ($diagnostic !== '') {
                fwrite(STDERR, $diagnostic);
            }
        }
    }

    /** @param list<string> $arguments */
    private function runArtifact(string $artifact, array $arguments, string $projectRoot): int
    {
        $process = new Process(
            [$artifact, ...$arguments],
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
