<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;

final readonly class DependencyCache
{
    public function __construct(public string $root)
    {
    }

    public function mirror(string $url): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'mirrors' . DIRECTORY_SEPARATOR . hash('sha256', $url);
    }

    public function checkout(string $url, string $commit): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'checkouts'
            . DIRECTORY_SEPARATOR . hash('sha256', $url)
            . DIRECTORY_SEPARATOR . $commit;
    }

    public function lock(string $identity): CacheLock
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'locks';
        $this->ensureDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $identity) . '.lock';
        if (is_link($path)) {
            throw $this->error("Cache lock is a symbolic link:\n    {$path}");
        }

        return CacheLock::acquire($path);
    }

    public function emptyGitConfig(): string
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . 'config';
        $this->ensureDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . 'empty-gitconfig';
        if (is_link($path)) {
            throw $this->error("Isolated Git configuration is a symbolic link:\n    {$path}");
        }
        if (!is_file($path) && @file_put_contents($path, '') === false) {
            throw $this->error('The isolated Git configuration could not be created.');
        }

        return $path;
    }

    public function ensureDirectory(string $path): void
    {
        $root = rtrim($this->root, '/\\');
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedPath = str_replace('\\', '/', rtrim($path, '/\\'));
        if ($normalizedPath !== $normalizedRoot
            && !str_starts_with($normalizedPath, $normalizedRoot . '/')
        ) {
            throw $this->error("Cache path escapes the configured root:\n    {$path}");
        }
        if (is_link($root)
            || (!is_dir($root) && !@mkdir($root, 0o755, true) && !is_dir($root))
        ) {
            throw $this->error("Cache directory could not be prepared:\n    {$root}");
        }
        $relative = substr($normalizedPath, strlen($normalizedRoot));
        $current = $root;
        foreach (array_values(array_filter(explode('/', $relative), static fn (string $part): bool => $part !== '')) as $part) {
            if ($part === '.' || $part === '..') {
                throw $this->error("Cache path escapes the configured root:\n    {$path}");
            }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)
                || (!is_dir($current) && !@mkdir($current, 0o755) && !is_dir($current))
            ) {
                throw $this->error("Cache directory could not be prepared:\n    {$current}");
            }
        }
    }

    private function error(string $body): BatonError
    {
        return new BatonError(
            'B0360',
            'Dependency Cache Is Unavailable',
            $body,
            ['Check that the user cache location is writable:'],
            ['baton doctor'],
        );
    }
}
