<?php

declare(strict_types=1);

namespace Doria\Baton\Project;

use Doria\Baton\Diagnostics\BatonError;

/** Canonical containment and exact-case checks shared by discovery and entries. */
final readonly class ProjectPathResolver
{
    public string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $canonical = realpath($projectRoot);
        if ($canonical === false || !is_dir($canonical)) {
            throw $this->error('Autoload Path Escapes Project', '.', 'Project root is unavailable.');
        }
        $this->projectRoot = $canonical;
    }

    public function directory(string $relativePath): ResolvedProjectPath
    {
        return $this->resolve($relativePath, true);
    }

    public function file(string $relativePath): ResolvedProjectPath
    {
        return $this->resolve($relativePath, false);
    }

    public function containsCanonical(string $canonicalPath): bool
    {
        $root = $this->comparisonPath($this->projectRoot);
        $path = $this->comparisonPath($canonicalPath);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    public function projectRelative(string $canonicalPath): string
    {
        if (!$this->containsCanonical($canonicalPath)) {
            throw $this->error(
                'Source Path Escapes Project',
                $canonicalPath,
                'The canonical source path is outside the package root.',
            );
        }
        if ($this->comparisonPath($canonicalPath) === $this->comparisonPath($this->projectRoot)) {
            return '.';
        }

        return str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($canonicalPath, strlen($this->projectRoot) + 1),
        );
    }

    private function resolve(string $relativePath, bool $directory): ResolvedProjectPath
    {
        $normalized = $this->normalize($relativePath, $directory);
        $this->assertExactCase($normalized);
        $local = $normalized === '.'
            ? $this->projectRoot
            : $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
        $canonical = realpath($local);
        if ($canonical === false) {
            if ($this->containsSymlink($normalized)) {
                throw $this->error(
                    'Autoload Symlink Cycle Was Found',
                    $normalized,
                    'The path could not be canonicalized through its symlink chain.',
                );
            }

            throw $this->error(
                $directory ? 'Autoload Directory Is Missing' : 'Entry Source Is Missing',
                $normalized,
                'The configured path does not exist.',
            );
        }
        if (!$this->containsCanonical($canonical)) {
            throw $this->error(
                $directory ? 'Autoload Symlink Escapes Project' : 'Entry Source Escapes Project',
                $normalized,
                'The canonical target is outside the package root.',
            );
        }
        if ($directory && !is_dir($canonical)) {
            throw $this->error(
                'Autoload Directory Is Missing',
                $normalized,
                'The configured autoload path is not a directory.',
            );
        }
        if (!$directory && !is_file($canonical)) {
            throw $this->error(
                'Entry Source Is Missing',
                $normalized,
                'The configured entry path is not a regular file.',
            );
        }
        if (!is_readable($canonical)) {
            throw $this->error(
                $directory ? 'Autoload Directory Is Unreadable' : 'Entry Source Is Unreadable',
                $normalized,
                'The configured path is not readable.',
            );
        }

        return new ResolvedProjectPath($normalized, $local, $canonical);
    }

    private function normalize(string $path, bool $directory): string
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === ''
            || (!$directory && $normalized === '.')
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
        ) {
            throw $this->error(
                $directory ? 'Autoload Path Escapes Project' : 'Entry Source Escapes Project',
                $path,
                'The path must be contained and project-relative.',
            );
        }
        $normalized = trim($normalized, '/');

        return $normalized === '' ? '.' : $normalized;
    }

    private function assertExactCase(string $relativePath): void
    {
        if ($relativePath === '.') {
            return;
        }
        $parent = $this->projectRoot;
        $walked = [];
        foreach (explode('/', $relativePath) as $segment) {
            $entries = @scandir($parent);
            if ($entries === false) {
                throw $this->error(
                    'Autoload Directory Is Unreadable',
                    implode('/', $walked),
                    'A path segment could not be inspected.',
                );
            }
            if (!in_array($segment, $entries, true)) {
                foreach ($entries as $entry) {
                    if (strcasecmp($segment, $entry) === 0) {
                        $requested = implode('/', [...$walked, $segment]);
                        $actual = implode('/', [...$walked, $entry]);
                        throw $this->error(
                            'Source Path Case Does Not Match',
                            $requested,
                            "Filesystem spelling is `{$actual}`.",
                        );
                    }
                }

                return;
            }
            $walked[] = $segment;
            $parent .= DIRECTORY_SEPARATOR . $segment;
        }
    }

    private function containsSymlink(string $relativePath): bool
    {
        if ($relativePath === '.') {
            return false;
        }
        $path = $this->projectRoot;
        foreach (explode('/', $relativePath) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    private function comparisonPath(string $path): string
    {
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function error(string $heading, string $path, string $detail): BatonError
    {
        return new BatonError(
            'B0313',
            $heading,
            "Project path: {$path}\n{$detail}",
            ['Correct the project-relative path or symlink, then run the command again.'],
        );
    }
}
