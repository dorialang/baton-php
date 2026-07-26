<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Project\ProjectLocator;
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
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
        $manifest = (new ManifestLoader())->load($projectRoot);
        $toolchain = CompilerOptions::locate($input);
        $release = (bool) $input->getOption('release');
        $profile = $release ? 'release' : 'development';
        $directory = $projectRoot
            . DIRECTORY_SEPARATOR
            . 'build'
            . DIRECTORY_SEPARATOR
            . $toolchain->identity->target
            . DIRECTORY_SEPARATOR
            . $profile;
        $artifact = $directory
            . DIRECTORY_SEPARATOR
            . $manifest->name
            . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $metadata = $directory . DIRECTORY_SEPARATOR . 'build.json';

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw $this->outputError("Failed to create:\n    {$directory}");
        }
        $this->removePrevious($artifact);
        $this->removePrevious($metadata);

        $arguments = [
            'compile',
            $manifest->entry,
            '--target',
            'native',
        ];
        if ($release) {
            $arguments[] = '--release';
        }
        $arguments[] = '--out';
        $arguments[] = $artifact;

        $compilerAdapter = new CompilerAdapter($toolchain->compilerPath);
        if ($output instanceof BufferedOutput) {
            $result = $compilerAdapter->capture($arguments, $projectRoot);
            $exitCode = $result->exitCode;
            if ($exitCode !== 0) {
                fwrite(STDOUT, $result->stdout);
                fwrite(STDERR, $result->stderr);
            }
        } else {
            $exitCode = $compilerAdapter->passthrough($arguments, $projectRoot);
        }
        if ($exitCode !== 0) {
            $this->removeIfPresent($artifact);
            $this->removeIfPresent($metadata);

            return $exitCode;
        }
        if (!is_file($artifact)) {
            throw new BatonError(
                'B0402',
                'Compiler Did Not Produce Build Artifact',
                "The compiler exited successfully without writing:\n    {$artifact}",
            );
        }

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
            );
        }

        return 0;
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
        return new BatonError('B0401', 'Build Output Could Not Be Prepared', $body);
    }
}
