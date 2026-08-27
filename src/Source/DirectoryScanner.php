<?php

declare(strict_types=1);

namespace Doria\Baton\Source;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Project\ProjectPathResolver;

/** Scans each canonical mapping root once while enforcing symlink containment. */
final class DirectoryScanner
{
    /** @var array<string, list<ScannedSource>> */
    private array $cache = [];

    public function __construct(private readonly ProjectPathResolver $paths)
    {
    }

    /** @return list<ScannedSource> */
    public function scan(string $canonicalRoot): array
    {
        if (isset($this->cache[$canonicalRoot])) {
            return $this->cache[$canonicalRoot];
        }

        $sources = [];
        $visited = [];
        $active = [];
        $this->walk($canonicalRoot, '', $visited, $active, $sources);
        usort(
            $sources,
            static fn (ScannedSource $left, ScannedSource $right): int => strcmp(
                $left->relativeToMapping,
                $right->relativeToMapping,
            ),
        );

        return $this->cache[$canonicalRoot] = $sources;
    }

    /**
     * @param array<string, bool>      $visited
     * @param array<string, bool>      $active
     * @param list<ScannedSource>      $sources
     */
    private function walk(
        string $directory,
        string $relative,
        array &$visited,
        array &$active,
        array &$sources,
    ): void {
        $canonicalDirectory = realpath($directory);
        if ($canonicalDirectory === false || !$this->paths->containsCanonical($canonicalDirectory)) {
            throw $this->sourceError(
                'Autoload Symlink Escapes Project',
                $relative,
                'A traversed directory resolves outside the package root.',
            );
        }
        if (isset($active[$canonicalDirectory])) {
            throw $this->sourceError(
                'Autoload Symlink Cycle Was Found',
                $relative,
                'A directory symlink resolves to an active ancestor.',
            );
        }
        if (isset($visited[$canonicalDirectory])) {
            throw $this->sourceError(
                'Source Is Discovered More Than Once',
                $relative,
                'Two directory paths resolve to the same canonical directory.',
            );
        }
        if (!is_readable($canonicalDirectory)) {
            throw $this->sourceError(
                'Autoload Directory Is Unreadable',
                $relative,
                'A traversed directory is not readable.',
            );
        }

        $visited[$canonicalDirectory] = true;
        $active[$canonicalDirectory] = true;
        $entries = @scandir($directory);
        if ($entries === false) {
            throw $this->sourceError(
                'Autoload Directory Is Unreadable',
                $relative,
                'A traversed directory could not be enumerated.',
            );
        }
        sort($entries, SORT_STRING);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $childRelative = $relative === '' ? $entry : "{$relative}/{$entry}";
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            $projectRelative = $this->logicalProjectRelative($path);
            if ($this->isManagedBoundary($projectRelative)) {
                continue;
            }

            if (is_link($path)) {
                $target = realpath($path);
                if ($target === false) {
                    throw $this->sourceError(
                        'Autoload Symlink Cycle Was Found',
                        $projectRelative,
                        'A symlink target could not be canonicalized.',
                    );
                }
                if (!$this->paths->containsCanonical($target)) {
                    throw $this->sourceError(
                        'Autoload Symlink Escapes Project',
                        $projectRelative,
                        'A symlink target resolves outside the package root.',
                    );
                }
            }

            if (is_dir($path)) {
                $this->walk($path, $childRelative, $visited, $active, $sources);
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            if (!is_readable($path)) {
                throw $this->sourceError(
                    'Source Is Unreadable',
                    $projectRelative,
                    'A discovered source candidate is not readable.',
                );
            }
            $canonical = realpath($path);
            if ($canonical === false || !$this->paths->containsCanonical($canonical)) {
                throw $this->sourceError(
                    'Autoload Symlink Escapes Project',
                    $projectRelative,
                    'A discovered file resolves outside the package root.',
                );
            }
            $sources[] = new ScannedSource($childRelative, $canonical);
        }
        unset($active[$canonicalDirectory]);
    }

    private function logicalProjectRelative(string $path): string
    {
        return str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($path, strlen($this->paths->projectRoot) + 1),
        );
    }

    private function isManagedBoundary(string $relativePath): bool
    {
        $segments = explode('/', $relativePath);

        return $segments[0] === 'build' || in_array('.git', $segments, true);
    }

    private function sourceError(string $heading, string $path, string $detail): BatonError
    {
        return new BatonError(
            'B0314',
            $heading,
            "Project path: {$path}\n{$detail}",
            ['Correct the source tree, then run the command again.'],
        );
    }
}
