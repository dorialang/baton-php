<?php

declare(strict_types=1);

namespace Doria\Baton\Source;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\NamespaceMapping;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Project\ProjectPathResolver;

/** Creates one deterministic, target-filtered inventory without parsing Doria. */
final class SourceDiscovery
{
    private readonly ProjectPathResolver $paths;

    private readonly DirectoryScanner $scanner;

    private readonly PatternMatcher $patterns;

    public function __construct(private readonly string $projectRoot)
    {
        $this->paths = new ProjectPathResolver($projectRoot);
        $this->scanner = new DirectoryScanner($this->paths);
        $this->patterns = new PatternMatcher();
    }

    /**
     * @param list<GeneratedSourceInput> $generatedSources
     */
    public function discover(
        Schema2Manifest $manifest,
        SelectedPackageTarget $selected,
        array $generatedSources = [],
    ): SourceInventory {
        $entries = [];
        if ($selected->isBinary()) {
            $entry = $selected->entry();
            if ($entry === null) {
                throw $this->error('Binary Target Entry Is Missing', '', 'The selected binary has no entry source.');
            }
            $entries[] = $entry;
        }

        return $this->discoverSources($manifest, $entries, $generatedSources);
    }

    /**
     * Returns the complete package surface needed by language tooling rather than
     * the source set of one executable target.
     *
     * @param list<GeneratedSourceInput> $generatedSources
     */
    public function discoverForTooling(
        Schema2Manifest $manifest,
        array $generatedSources = [],
    ): SourceInventory {
        $entries = [];
        foreach ($manifest->targets->binaries as $binary) {
            $entries[$this->normalize($binary->entryPath)] = $binary->entryPath;
        }

        return $this->discoverSources(
            $manifest,
            array_values($entries),
            $generatedSources,
        );
    }

    /**
     * @param list<string>               $includedEntries
     * @param list<GeneratedSourceInput> $generatedSources
     */
    private function discoverSources(
        Schema2Manifest $manifest,
        array $includedEntries,
        array $generatedSources,
    ): SourceInventory {
        $entryPaths = [];
        foreach ($manifest->targets->binaries as $binary) {
            $entryPaths[$this->normalize($binary->entryPath)] = true;
        }

        $sources = [];
        $canonical = [];
        $portable = [];
        foreach ($manifest->autoload->all() as $mapping) {
            $root = $this->paths->directory($mapping->path);
            foreach ($this->scanner->scan($root->canonicalPath) as $candidate) {
                if (!str_ends_with($candidate->relativeToMapping, '.doria')
                    || !$this->included($mapping, $candidate->relativeToMapping)
                ) {
                    continue;
                }
                $relative = $this->mappingRelative($mapping->path, $candidate->relativeToMapping);
                if (isset($entryPaths[$relative])) {
                    continue;
                }
                $this->register(
                    new DiscoveredSource(
                        $relative,
                        $candidate->canonicalPath,
                        $mapping->scope,
                        'autoload',
                        null,
                        $mapping,
                    ),
                    $sources,
                    $canonical,
                    $portable,
                );
            }
        }

        foreach ($includedEntries as $entry) {
            $resolved = $this->paths->file($entry);
            $contents = @file_get_contents($resolved->canonicalPath);
            if ($contents === false || preg_match('//u', $contents) !== 1) {
                throw $this->error(
                    'Entry Source Is Not UTF-8',
                    $resolved->relativePath,
                    'A binary entry must be readable UTF-8 Doria source.',
                );
            }
            $this->register(
                new DiscoveredSource(
                    $resolved->relativePath,
                    $resolved->canonicalPath,
                    'main',
                    'entry',
                    null,
                    null,
                ),
                $sources,
                $canonical,
                $portable,
            );
        }

        foreach ($generatedSources as $generated) {
            $this->registerGenerated($generated, $sources, $canonical, $portable);
        }

        usort(
            $sources,
            static fn (DiscoveredSource $left, DiscoveredSource $right): int => strcmp(
                $left->relativePath,
                $right->relativePath,
            ),
        );

        $active = array_filter(
            $sources,
            static fn (DiscoveredSource $source): bool => $source->scope === 'main'
                || ($source->scope === 'generated' && $source->generatedFor === 'main'),
        );
        if ($active === []) {
            throw $this->error(
                'Source Inventory Is Empty',
                '',
                'The selected target has no active main source.',
            );
        }

        return new SourceInventory($sources);
    }

    private function included(NamespaceMapping $mapping, string $relativePath): bool
    {
        $included = false;
        foreach ($mapping->patterns->include as $pattern) {
            if ($this->patterns->matches($pattern, $relativePath)) {
                $included = true;
                break;
            }
        }
        if (!$included) {
            return false;
        }
        foreach ($mapping->patterns->exclude as $pattern) {
            if ($this->patterns->matches($pattern, $relativePath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<DiscoveredSource>       $sources
     * @param array<string, DiscoveredSource> $canonical
     * @param array<string, DiscoveredSource> $portable
     */
    private function register(
        DiscoveredSource $source,
        array &$sources,
        array &$canonical,
        array &$portable,
    ): void {
        if (isset($canonical[$source->canonicalPath])) {
            $previous = $canonical[$source->canonicalPath];
            $heading = $previous->scope === $source->scope
                ? 'Source Is Discovered More Than Once'
                : 'Source Has Conflicting Scopes';
            throw $this->error(
                $heading,
                $source->relativePath,
                "Also discovered as `{$previous->relativePath}` in {$previous->scope} scope.",
            );
        }
        $key = $this->portableKey($source->relativePath);
        if (isset($portable[$key]) && $portable[$key]->relativePath !== $source->relativePath) {
            throw $this->error(
                'Source Paths Collide Across Platforms',
                $source->relativePath,
                "Portable path collides with `{$portable[$key]->relativePath}`.",
            );
        }

        $canonical[$source->canonicalPath] = $source;
        $portable[$key] = $source;
        $sources[] = $source;
    }

    /**
     * @param list<DiscoveredSource>          $sources
     * @param array<string, DiscoveredSource> $canonical
     * @param array<string, DiscoveredSource> $portable
     */
    private function registerGenerated(
        GeneratedSourceInput $generated,
        array &$sources,
        array &$canonical,
        array &$portable,
    ): void {
        if (!in_array($generated->generatedFor, ['main', 'development'], true)) {
            throw $this->error(
                'Generated Source Input Is Invalid',
                $generated->relativePath,
                '`generatedFor` must be `main` or `development`.',
            );
        }
        $rawRelative = str_replace('\\', '/', $generated->relativePath);
        if ($rawRelative === ''
            || str_contains($rawRelative, "\0")
            || str_starts_with($rawRelative, '/')
            || preg_match('/^[A-Za-z]:\//', $rawRelative) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $rawRelative) === 1
            || in_array('..', explode('/', $rawRelative), true)
            || $rawRelative === '.'
            || str_ends_with($rawRelative, '/')
            || !str_ends_with($rawRelative, '.doria')
        ) {
            throw $this->error(
                'Generated Source Input Is Invalid',
                $generated->relativePath,
                'Generated source paths must be contained project-relative `.doria` paths.',
            );
        }
        $relative = trim($rawRelative, '/');
        if ($relative === ''
            || !str_ends_with($relative, '.doria')
        ) {
            throw $this->error(
                'Generated Source Input Is Invalid',
                $generated->relativePath,
                'Generated source paths must be contained project-relative `.doria` paths.',
            );
        }
        if (($generated->contents === null) === ($generated->existingPath === null)) {
            throw $this->error(
                'Generated Source Input Is Invalid',
                $generated->relativePath,
                'Provide exactly one of generated contents or an existing project-relative path.',
            );
        }
        if (preg_match('/^[0-9a-f]{64}$/D', $generated->contentHash) !== 1) {
            throw $this->error(
                'Generated Source Input Is Invalid',
                $generated->relativePath,
                'The content hash must be a lowercase SHA-256 digest.',
            );
        }

        $canonicalPath = '';
        if ($generated->contents !== null) {
            if (preg_match('//u', $generated->contents) !== 1
                || hash('sha256', $generated->contents) !== $generated->contentHash
            ) {
                throw $this->error(
                    'Generated Source Input Is Invalid',
                    $generated->relativePath,
                    'Generated contents must be UTF-8 and match their SHA-256 digest.',
                );
            }
        } else {
            try {
                $resolved = $this->paths->file($generated->existingPath ?? '');
            } catch (BatonError) {
                throw $this->error(
                    'Generated Source Input Is Invalid',
                    $generated->relativePath,
                    'The existing generated source must be a contained project-relative file.',
                );
            }
            $canonicalPath = $resolved->canonicalPath;
            $hash = hash_file('sha256', $canonicalPath);
            if ($resolved->relativePath !== $relative || $hash !== $generated->contentHash) {
                throw $this->error(
                    'Generated Source Input Is Invalid',
                    $generated->relativePath,
                    'The existing generated source must match its plan path and SHA-256 digest.',
                );
            }
        }
        $key = $this->portableKey($relative);
        if ($canonicalPath !== '' && isset($canonical[$canonicalPath])) {
            throw $this->error(
                'Source Is Discovered More Than Once',
                $relative,
                "Generated source resolves to `{$canonical[$canonicalPath]->relativePath}`.",
            );
        }
        if (isset($portable[$key])) {
            throw $this->error(
                'Source Paths Collide Across Platforms',
                $relative,
                "Generated source collides with `{$portable[$key]->relativePath}`.",
            );
        }
        $source = new DiscoveredSource(
            $relative,
            $canonicalPath,
            'generated',
            'generated',
            $generated->generatedFor,
            null,
            $generated->producer,
        );
        if ($canonicalPath !== '') {
            $canonical[$canonicalPath] = $source;
        }
        $portable[$key] = $source;
        $sources[] = $source;
    }

    private function mappingRelative(string $mappingPath, string $relative): string
    {
        $base = trim($this->normalize($mappingPath), '/');

        return $base === '' || $base === '.' ? $relative : "{$base}/{$relative}";
    }

    private function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function portableKey(string $path): string
    {
        return strtr($path, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    private function error(string $heading, string $path, string $detail): BatonError
    {
        $body = $path === '' ? $detail : "Project path: {$path}\n{$detail}";

        return new BatonError(
            'B0314',
            $heading,
            $body,
            ['Correct the package source inventory, then run the command again.'],
        );
    }
}
