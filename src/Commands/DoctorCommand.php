<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Application;
use Doria\Baton\Dependency\CacheRootLocator;
use Doria\Baton\Dependency\GitClient;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Project\ProjectLocator;
use Doria\Baton\Toolchain\Platform;
use Doria\Baton\Toolchain\ToolchainManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'doctor',
    description: 'Report the Baton and toolchain environment',
)]
final class DoctorCommand extends BatonCommand
{
    protected function configure(): void
    {
        CompilerOptions::configure($this);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $failed = false;
        $host = Platform::host();

        $this->line($output, 'PASS', 'Baton version', Application::VERSION);
        $this->line($output, 'PASS', 'Toolchain version', Application::VERSION);
        $this->line($output, 'PASS', 'Release channel', $this->releaseChannel());
        $this->line($output, 'PASS', 'Baton executable', $this->batonExecutable());
        $this->line($output, 'PASS', 'PHP runtime', PHP_BINARY . ' (' . PHP_VERSION . ')');
        $this->line($output, 'PASS', 'Host platform', $host->name);
        $this->line($output, 'PASS', 'Host architecture', $host->architecture);

        try {
            $toolchain = CompilerOptions::locate($input);
            $this->line($output, 'PASS', 'doriac path', $toolchain->compilerPath);
            $this->line($output, 'PASS', 'doriac source', $toolchain->source);
            $this->line(
                $output,
                'PASS',
                'doriac version',
                $toolchain->identity->toolchainVersion
            );
            $this->line($output, 'PASS', 'doriac target', $toolchain->identity->target);
            if ($toolchain->manifest === null) {
                $this->line($output, 'WARNING', 'toolchain manifest', 'not used');
                $this->line($output, 'WARNING', 'component hashes', 'not available');
                $this->line(
                    $output,
                    'WARNING',
                    'doria-lsp',
                    'not verifiable without an installed toolchain manifest',
                );
                $this->line(
                    $output,
                    'WARNING',
                    'private PHP runtime',
                    'not verifiable during source development',
                );
            } else {
                $manifest = $toolchain->manifest;
                $this->line($output, 'PASS', 'toolchain manifest', $manifest->path);
                $this->line($output, 'PASS', 'component hashes', 'verified');
                $this->line($output, 'PASS', 'doria-lsp path', $manifest->languageServerPath);
                $this->line(
                    $output,
                    'PASS',
                    'doria-lsp version',
                    $manifest->languageServerVersion,
                );
                $runtimeStatus = $this->isInsideToolchain(PHP_BINARY, $manifest)
                    ? 'PASS'
                    : 'FAIL';
                $this->line($output, $runtimeStatus, 'private PHP runtime', PHP_BINARY);
                if ($runtimeStatus === 'FAIL') {
                    $failed = true;
                }
            }
        } catch (BatonError $error) {
            $this->line(
                $output,
                'FAIL',
                'doriac',
                "[{$error->diagnosticCode}] {$error->heading}",
            );
            $failed = true;
        }

        try {
            $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
            $buildPath = $projectRoot . DIRECTORY_SEPARATOR . 'build';
            $buildStatus = $this->canCreateOrWrite($buildPath) ? 'PASS' : 'FAIL';
            $this->line($output, $buildStatus, 'build location', $buildPath);
            $failed = $failed || $buildStatus === 'FAIL';
        } catch (BatonError) {
            $this->line(
                $output,
                'WARNING',
                'build location',
                'not checked outside a Baton project',
            );
        }

        try {
            $cachePath = (new CacheRootLocator())->locate();
            $cacheStatus = $this->canCreateOrWrite($cachePath) ? 'PASS' : 'FAIL';
            $this->line($output, $cacheStatus, 'dependency cache', $cachePath);
            $failed = $failed || $cacheStatus === 'FAIL';
        } catch (BatonError) {
            $this->line($output, 'WARNING', 'dependency cache', 'could not be determined');
        }
        $git = new GitClient();
        $gitPath = $git->executable();
        $this->line($output, $gitPath === null ? 'WARNING' : 'PASS', 'Git executable', $gitPath ?? 'not found (path-only projects remain usable)');
        $this->line($output, $gitPath === null ? 'WARNING' : 'PASS', 'Git version', $git->version() ?? 'not available');
        $this->line($output, 'PASS', 'offline policy', 'selected per invocation');

        try {
            $projectRoot = (new ProjectLocator())->locate(getcwd() ?: '.');
            $lockPath = $projectRoot . DIRECTORY_SEPARATOR . 'Baton.lock';
            $this->line($output, 'PASS', 'Baton.lock', is_file($lockPath) ? 'present' : 'absent');
        } catch (BatonError) {
            $this->line($output, 'WARNING', 'Baton.lock', 'not checked outside a Baton project');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function line(OutputInterface $output, string $status, string $label, string $value): void
    {
        $tag = match ($status) {
            'PASS' => '<info>PASS</info>',
            'WARNING' => '<comment>WARNING</comment>',
            default => '<error>FAIL</error>',
        };

        $output->writeln(sprintf('%s  %-20s %s', $tag, $label, $value));
    }

    private function releaseChannel(): string
    {
        if (str_ends_with(Application::VERSION, '-canary')) {
            return 'canary';
        }
        if (str_ends_with(Application::VERSION, '-rc')) {
            return 'rc';
        }

        return 'stable';
    }

    private function batonExecutable(): string
    {
        $arguments = $_SERVER['argv'] ?? null;
        if (!is_array($arguments) || !is_string($arguments[0] ?? null)) {
            return 'baton';
        }
        $path = $arguments[0];
        $resolved = realpath($path);

        return $resolved === false ? $path : $resolved;
    }

    private function isInsideToolchain(string $path, ToolchainManifest $manifest): bool
    {
        $root = realpath(dirname($manifest->path));
        $resolved = realpath($path);
        if ($root === false || $resolved === false) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $resolved = strtolower($resolved);
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $resolved === $root
            || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR);
    }

    private function canCreateOrWrite(string $path): bool
    {
        if (file_exists($path)) {
            return is_dir($path) && is_writable($path);
        }

        $parent = dirname($path);
        while (!file_exists($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                return false;
            }
            $parent = $next;
        }

        return is_dir($parent) && is_writable($parent);
    }

}
