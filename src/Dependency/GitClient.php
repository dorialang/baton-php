<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\GitDependencySource;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class GitClient implements GitTransport
{
    private ?string $git;

    private CheckoutContentFingerprint $checkoutFingerprint;

    public function __construct(?string $executable = null)
    {
        $this->git = $executable ?? (new ExecutableFinder())->find('git');
        $this->checkoutFingerprint = new CheckoutContentFingerprint();
    }

    public function executable(): ?string
    {
        return $this->git;
    }

    public function version(): ?string
    {
        if ($this->git === null) {
            return null;
        }
        try {
            $process = new Process([$this->git, '--version'], timeout: 10);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function resolve(
        GitDependencySource $source,
        NetworkPolicy $network,
        DependencyCache $cache,
        bool $refresh,
    ): string {
        $mirror = $this->ensureMirror($source->url, $network, $cache, $refresh);
        $reference = match ($source->selector->kind) {
            'tag' => 'refs/tags/' . $source->selector->value,
            'branch' => 'refs/heads/' . $source->selector->value,
            default => $source->selector->value,
        };
        $result = $this->run(
            ['--git-dir', $mirror, 'rev-parse', '--verify', "{$reference}^{commit}"],
            $cache,
            null,
            'Git Dependency Could Not Be Resolved',
        );
        $commit = strtolower(trim($result));
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw $this->error(
                'B0350',
                'Git Dependency Could Not Be Resolved',
                "Git did not resolve {$source->selector->describe()} to a full commit.",
            );
        }

        return $commit;
    }

    public function checkout(
        string $url,
        string $commit,
        NetworkPolicy $network,
        DependencyCache $cache,
    ): string {
        if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
            throw $this->error('B0350', 'Git Revision Is Invalid', 'A locked Git commit must be 40 lowercase hexadecimal characters.');
        }
        $destination = $cache->checkout($url, $commit);
        if ($this->validCheckout($destination, $url, $commit)) {
            return $destination;
        }

        $lock = $cache->lock("checkout\0{$url}\0{$commit}");
        try {
            if ($this->validCheckout($destination, $url, $commit)) {
                return $destination;
            }
            if ((file_exists($destination) || is_link($destination))) {
                if (!$network->permitsNetwork()) {
                    throw $this->error(
                        'B0362',
                        'Dependency Cache Entry Is Corrupt',
                        "The exact cached checkout is incomplete or corrupt:\n    {$destination}",
                    );
                }
                $this->removeDirectory($destination);
            }
            $mirror = $this->ensureMirror($url, $network, $cache, false);
            $parent = dirname($destination);
            $cache->ensureDirectory($parent);
            $this->clearInterruptedCheckouts($parent, $commit);
            $temporary = $parent . DIRECTORY_SEPARATOR . '.' . $commit . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $this->run(
                ['clone', '--no-checkout', '--no-local', $this->localRepositoryUrl($mirror), $temporary],
                $cache,
                null,
                'Git Dependency Could Not Be Resolved',
            );
            $this->run(
                ['-C', $temporary, '-c', 'core.hooksPath=' . $this->emptyHooks($cache), '-c', 'filter.lfs.required=false', '-c', 'filter.lfs.smudge=', '-c', 'filter.lfs.process=', 'checkout', '--detach', $commit, '--'],
                $cache,
                null,
                'Git Dependency Could Not Be Resolved',
            );
            if (is_file($temporary . DIRECTORY_SEPARATOR . '.gitmodules')) {
                $this->removeDirectory($temporary);
                throw $this->error(
                    'B0351',
                    'Git Submodules Are Not Supported',
                    'Git dependency packages containing `.gitmodules` are not supported.',
                );
            }
            $this->removeDirectory($temporary . DIRECTORY_SEPARATOR . '.git');
            $contentSha256 = $this->checkoutFingerprint->calculate($temporary);
            if ($contentSha256 === null) {
                $this->removeDirectory($temporary);
                throw $this->error('B0362', 'Dependency Cache Entry Is Corrupt', 'The exact checkout could not be fingerprinted.');
            }
            $marker = json_encode([
                'schemaVersion' => 1,
                'url' => $url,
                'commit' => $commit,
                'contentSha256' => $contentSha256,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (@file_put_contents($temporary . DIRECTORY_SEPARATOR . '.baton-cache.json', $marker, LOCK_EX) !== strlen($marker)
                || !@rename($temporary, $destination)
            ) {
                $this->removeDirectory($temporary);
                throw $this->error('B0361', 'Dependency Cache Could Not Be Written', "Could not publish exact checkout:\n    {$destination}");
            }
            $this->makeImmutable($destination);

            return $destination;
        } finally {
            $lock->release();
        }
    }

    private function ensureMirror(
        string $url,
        NetworkPolicy $network,
        DependencyCache $cache,
        bool $refresh,
    ): string {
        $mirror = $cache->mirror($url);
        $valid = is_dir($mirror) && !is_link($mirror)
            && is_file($mirror . DIRECTORY_SEPARATOR . 'HEAD');
        if (!$valid && !$network->permitsNetwork()) {
            throw $this->error(
                'B0363',
                'Offline Dependency Content Is Missing',
                "No cached Git metadata is available for:\n    {$url}",
            );
        }
        if (!$valid || ($refresh && $network->permitsNetwork())) {
            $this->requireGit();
            $lock = $cache->lock("mirror\0{$url}");
            try {
                $valid = is_dir($mirror) && !is_link($mirror)
                    && is_file($mirror . DIRECTORY_SEPARATOR . 'HEAD');
                if (!$valid) {
                    if ((file_exists($mirror) || is_link($mirror))) {
                        $this->removeDirectory($mirror);
                    }
                    $parent = dirname($mirror);
                    $cache->ensureDirectory($parent);
                    $temporary = $parent . DIRECTORY_SEPARATOR . '.' . basename($mirror) . '.' . bin2hex(random_bytes(6)) . '.tmp';
                    $this->run(['clone', '--mirror', $url, $temporary], $cache, null, 'Git Dependency Could Not Be Resolved');
                    if (!@rename($temporary, $mirror)) {
                        $this->removeDirectory($temporary);
                        throw $this->error('B0361', 'Dependency Cache Could Not Be Written', "Could not publish Git mirror:\n    {$mirror}");
                    }
                } elseif ($refresh) {
                    $this->run(
                        ['--git-dir', $mirror, 'fetch', '--prune', '--force', 'origin', '+refs/heads/*:refs/heads/*', '+refs/tags/*:refs/tags/*'],
                        $cache,
                        null,
                        'Git Dependency Could Not Be Resolved',
                    );
                }
            } finally {
                $lock->release();
            }
        }

        return $mirror;
    }

    /** @param list<string> $arguments */
    private function run(
        array $arguments,
        DependencyCache $cache,
        ?string $workingDirectory,
        string $heading,
    ): string {
        $git = $this->requireGit();
        $environment = [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $cache->emptyGitConfig(),
            'GIT_LFS_SKIP_SMUDGE' => '1',
        ];
        $command = [$git, '-c', 'core.hooksPath=' . $this->emptyHooks($cache), ...$arguments];
        try {
            $process = new Process($command, $workingDirectory, $environment, timeout: 120);
            $process->run();
        } catch (Throwable $error) {
            throw $this->error('B0350', $heading, 'Git could not be started: ' . $error->getMessage());
        }
        if (!$process->isSuccessful()) {
            $detail = trim($process->getErrorOutput());
            $detail = preg_replace('#https://[^\s/@:]+:[^\s/@]+@#', 'https://<redacted>@', $detail) ?? $detail;
            $detail = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $detail) ?? $detail;
            throw $this->error('B0350', $heading, $detail === '' ? 'Git exited unsuccessfully.' : $detail);
        }

        return $process->getOutput();
    }

    private function requireGit(): string
    {
        if ($this->git === null) {
            throw $this->error('B0352', 'Git Is Not Available', 'A Git executable is required for Git dependencies.');
        }

        return $this->git;
    }

    private function emptyHooks(DependencyCache $cache): string
    {
        $path = $cache->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'hooks';
        $cache->ensureDirectory($path);

        return $path;
    }

    /** @phpstan-impure */
    private function validCheckout(string $path, string $url, string $commit): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return false;
        }
        $marker = @file_get_contents($path . DIRECTORY_SEPARATOR . '.baton-cache.json');
        if ($marker === false) {
            return false;
        }
        try {
            $value = json_decode($marker, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return is_array($value)
            && ($value['schemaVersion'] ?? null) === 1
            && ($value['url'] ?? null) === $url
            && ($value['commit'] ?? null) === $commit
            && is_string($value['contentSha256'] ?? null)
            && hash_equals($value['contentSha256'], $this->checkoutFingerprint->calculate($path) ?? '')
            && is_file($path . DIRECTORY_SEPARATOR . 'Baton.toml')
            && !is_file($path . DIRECTORY_SEPARATOR . '.gitmodules');
    }

    private function clearInterruptedCheckouts(string $parent, string $commit): void
    {
        $pattern = $parent . DIRECTORY_SEPARATOR . '.' . $commit . '.*.tmp';
        foreach (glob($pattern) ?: [] as $temporary) {
            $this->removeDirectory($temporary);
        }
    }

    private function localRepositoryUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = array_map('rawurlencode', explode('/', $normalized));
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            $segments[0] = substr($normalized, 0, 2);
        }

        return 'file://' . (str_starts_with($normalized, '/') ? '' : '/') . implode('/', $segments);
    }

    private function makeImmutable(string $path): void
    {
        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isLink()) {
                continue;
            }
            @chmod($entry->getPathname(), $entry->isDir() ? 0o555 : 0o444);
        }
        @chmod($path, 0o555);
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0o755);
        /** @var iterable<SplFileInfo> $permissions */
        $permissions = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($permissions as $entry) {
            if ($entry->isLink()) {
                continue;
            }
            @chmod($entry->getPathname(), $entry->isDir() ? 0o755 : 0o644);
        }
        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }

    private function error(string $code, string $heading, string $body): BatonError
    {
        return new BatonError($code, $heading, $body);
    }
}
