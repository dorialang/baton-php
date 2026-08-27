<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Build\Schema2BuildService;
use Doria\Baton\Build\Schema2ProjectContextFactory;
use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\TargetSelector;
use Doria\Baton\Project\ProjectLocator;
use Doria\Baton\Toolchain\ToolchainSelection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'build',
    description: 'Compile the current project',
)]
final class BuildCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'release',
            null,
            InputOption::VALUE_NONE,
            'Build an optimized release artifact',
        );
        $this->addOption(
            'out',
            'o',
            InputOption::VALUE_REQUIRED,
            'Write the artifact to this path instead of the managed build directory',
        );
        TargetOptions::configure($this);
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
        $manifest = (new ManifestLoader())->load($projectRoot);
        [$binary, $library] = TargetOptions::read($input);
        $selected = (new TargetSelector())->select($manifest, $binary, $library, 'build');
        $toolchain = CompilerOptions::locate($input);
        $release = (bool) $input->getOption('release');
        $profile = $release ? 'release' : 'development';
        /** @var string|null $out */
        $out = $input->getOption('out');
        $explicitOutput = $out === null || $out === ''
            ? null
            : $this->absolutePath($out, getcwd() ?: $projectRoot);

        if ($manifest instanceof Manifest) {
            return $this->buildSchema1(
                $projectRoot,
                $manifest,
                $toolchain,
                $release,
                $explicitOutput,
                $output,
            );
        }

        $context = (new Schema2ProjectContextFactory())->create(
            $projectRoot,
            $manifest,
            $selected,
            $toolchain,
            $profile,
        );

        return (new Schema2BuildService())->build($context, $output, $explicitOutput);
    }

    private function buildSchema1(
        string $projectRoot,
        Manifest $manifest,
        ToolchainSelection $toolchain,
        bool $release,
        ?string $explicitOutput,
        OutputInterface $output,
    ): int {
        $profile = $release ? 'release' : 'development';
        if ($explicitOutput !== null) {
            $artifact = $explicitOutput;
            $directory = dirname($artifact);
            $metadata = null;
        } else {
            $directory = $projectRoot
                . DIRECTORY_SEPARATOR . 'build'
                . DIRECTORY_SEPARATOR . $toolchain->identity->target
                . DIRECTORY_SEPARATOR . $profile;
            $artifact = $directory
                . DIRECTORY_SEPARATOR . $manifest->name
                . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            $metadata = $directory . DIRECTORY_SEPARATOR . 'build.json';
        }

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw $this->outputError("Failed to create:\n    {$directory}");
        }
        $this->removePrevious($artifact);
        if ($metadata !== null) {
            $this->removePrevious($metadata);
        }

        $arguments = ['compile', $manifest->entry, '--target', 'native'];
        if ($release) {
            $arguments[] = '--release';
        }
        $arguments[] = '--out';
        $arguments[] = $artifact;

        $exitCode = $this->compiler(
            new CompilerAdapter($toolchain->compilerPath),
            $arguments,
            $projectRoot,
            $output,
        );
        if ($exitCode !== 0) {
            $this->removeIfPresent($artifact);
            if ($metadata !== null) {
                $this->removeIfPresent($metadata);
            }

            return $exitCode;
        }
        if (!is_file($artifact)) {
            throw new BatonError(
                'B0402',
                'Compiler Did Not Produce Build Artifact',
                "The compiler exited successfully without writing:\n    {$artifact}",
                ['Rebuild with compiler and build detail shown, so the cause is visible:'],
                ['baton build -vv'],
            );
        }

        if ($metadata !== null) {
            $json = json_encode([
                'package' => $manifest->name,
                'packageVersion' => $manifest->version,
                'toolchainVersion' => $toolchain->identity->toolchainVersion,
                'target' => $toolchain->identity->target,
                'profile' => $profile,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false || @file_put_contents($metadata, $json . "\n") === false) {
                $this->removeIfPresent($artifact);
                throw new BatonError(
                    'B0403',
                    'Build Metadata Could Not Be Written',
                    "Failed to write:\n    {$metadata}",
                    ['Check that the build location is writable:'],
                    ['baton doctor'],
                );
            }
        }

        return 0;
    }

    /** @param list<string> $arguments */
    private function compiler(
        CompilerAdapter $adapter,
        array $arguments,
        string $projectRoot,
        OutputInterface $output,
    ): int {
        if (!$output instanceof BufferedOutput) {
            return $adapter->passthrough($arguments, $projectRoot);
        }
        $result = $adapter->capture($arguments, $projectRoot);
        if ($result->stdout !== '') {
            $output->write($result->stdout);
        }
        if ($result->stderr !== '') {
            $output->write($result->stderr);
        }

        return $result->exitCode;
    }

    private function absolutePath(string $path, string $base): string
    {
        $isAbsolute = str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        return $isAbsolute ? $path : $base . DIRECTORY_SEPARATOR . $path;
    }

    private function removePrevious(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) || !@unlink($path)) {
            throw $this->outputError("Failed to replace:\n    {$path}");
        }
    }

    private function removeIfPresent(string $path): void
    {
        if ((file_exists($path) || is_link($path)) && !is_dir($path)) {
            @unlink($path);
        }
    }

    private function outputError(string $body): BatonError
    {
        return new BatonError(
            'B0401',
            'Build Output Could Not Be Prepared',
            $body,
            ['Check that the build and cache locations are writable:'],
            ['baton doctor'],
        );
    }
}
