<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Application;
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

        $cachePath = $this->cachePath();
        if ($cachePath === null) {
            $this->line($output, 'WARNING', 'cache location', 'could not be determined');
        } else {
            $cacheStatus = $this->canCreateOrWrite($cachePath) ? 'PASS' : 'FAIL';
            $this->line($output, $cacheStatus, 'cache location', $cachePath);
            $failed = $failed || $cacheStatus === 'FAIL';
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

    private function cachePath(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $root = getenv('LOCALAPPDATA');

            return is_string($root) && $root !== ''
                ? $root . DIRECTORY_SEPARATOR . 'Doria' . DIRECTORY_SEPARATOR . 'cache'
                : null;
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            return null;
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return $home
                . DIRECTORY_SEPARATOR
                . 'Library'
                . DIRECTORY_SEPARATOR
                . 'Caches'
                . DIRECTORY_SEPARATOR
                . 'Doria';
        }

        $root = getenv('XDG_CACHE_HOME');
        if (!is_string($root) || $root === '') {
            $root = $home . DIRECTORY_SEPARATOR . '.cache';
        }

        return $root . DIRECTORY_SEPARATOR . 'doria';
    }
}
