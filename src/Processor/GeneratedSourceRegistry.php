<?php

declare(strict_types=1);

namespace Doria\Baton\Processor;

use Doria\Baton\Application;
use Doria\Baton\Build\AtomicFileWriter;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Project\ProjectPathResolver;
use Doria\Baton\Source\GeneratedSourceInput;

/**
 * Private generated-source registry shared by publication and tooling discovery.
 *
 * @phpstan-type RegistrySource array{
 *   identity: string,
 *   package: string,
 *   processor: string,
 *   path: string,
 *   generatedFor: string,
 *   sha256: string
 * }
 */
final class GeneratedSourceRegistry
{
    private const RELATIVE_PATH = 'build/.baton/generated-sources.json';

    /** @param list<RegistrySource> $sources */
    public function replaceOwner(
        string $storageRoot,
        string $compilerRevision,
        string $owner,
        array $sources,
    ): void {
        $path = $this->path($storageRoot);
        try {
            $existing = is_file($path)
                ? $this->decode($path)
                : ['compilerRevision' => $compilerRevision, 'owners' => [], 'sources' => []];
        } catch (BatonError) {
            // This registry is disposable private state. A build replaces an old or corrupt shape.
            $existing = ['compilerRevision' => $compilerRevision, 'owners' => [], 'sources' => []];
        }
        $owners = $existing['owners'];
        $owners[] = $owner;
        $owners = array_values(array_unique($owners));
        sort($owners, SORT_STRING);
        $all = [];
        foreach ($existing['sources'] as $source) {
            if ($source['package'] !== $owner) {
                $all[] = $source;
            }
        }
        $all = [...$all, ...$sources];
        usort($all, static fn (array $left, array $right): int => strcmp(
            $left['identity'],
            $right['identity'],
        ));
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0o755, true) && !is_dir(dirname($path))) {
            throw $this->writeError($path);
        }
        (new AtomicFileWriter())->write(
            $path,
            $this->json([
                'schemaVersion' => 1,
                'batonVersion' => Application::VERSION,
                'compilerRevision' => $compilerRevision,
                'owners' => $owners,
                'sources' => $all,
            ]),
            'Generated Source Inventory Could Not Be Written',
        );
    }

    /**
     * @param array<string, ResolvedPackage> $owners Authored package identity keyed.
     * @return array{compilerRevision: string, inputs: array<string, list<GeneratedSourceInput>>, sources: list<RegistrySource>}
     */
    public function requireValid(string $storageRoot, array $owners): array
    {
        $path = $this->path($storageRoot);
        if (!is_file($path)) {
            throw $this->stale($path);
        }
        $document = $this->decode($path);
        $inputs = [];
        $visible = [];
        foreach ($document['sources'] as $source) {
            $owner = $owners[$source['package']] ?? null;
            if ($owner === null) {
                continue;
            }
            $input = $this->validateSource($source, $owner, $path);
            $inputs[$source['package']][] = $input;
            $visible[] = $source;
        }
        foreach ($owners as $package => $owner) {
            if ($owner->manifest->processors !== [] && !in_array($package, $document['owners'], true)) {
                throw $this->stale($path);
            }
        }
        ksort($inputs, SORT_STRING);
        usort($visible, static fn (array $left, array $right): int => strcmp(
            $left['identity'],
            $right['identity'],
        ));

        return [
            'compilerRevision' => $document['compilerRevision'],
            'inputs' => $inputs,
            'sources' => $visible,
        ];
    }

    /**
     * @return array{compilerRevision: string, owners: list<string>, sources: list<RegistrySource>}
     */
    private function decode(string $path): array
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw $this->stale($path);
        }
        try {
            $value = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->stale($path);
        }
        if (!is_array($value)
            || array_is_list($value)
            || array_keys($value) !== ['schemaVersion', 'batonVersion', 'compilerRevision', 'owners', 'sources']
            || $value['schemaVersion'] !== 1
            || !is_string($value['batonVersion'])
            || !is_string($value['compilerRevision'])
            || $value['compilerRevision'] === ''
            || preg_match('/[\x00-\x1F\x7F]/', $value['compilerRevision']) === 1
            || !is_array($value['owners'])
            || !array_is_list($value['owners'])
            || !is_array($value['sources'])
            || !array_is_list($value['sources'])
        ) {
            throw $this->stale($path);
        }
        $owners = [];
        foreach ($value['owners'] as $owner) {
            if (!is_string($owner) || $owner === '' || in_array($owner, $owners, true)) {
                throw $this->stale($path);
            }
            $owners[] = $owner;
        }
        $sortedOwners = $owners;
        sort($sortedOwners, SORT_STRING);
        if ($owners !== $sortedOwners) {
            throw $this->stale($path);
        }
        $sources = [];
        foreach ($value['sources'] as $source) {
            if (!is_array($source)
                || array_is_list($source)
                || array_keys($source) !== ['identity', 'package', 'processor', 'path', 'generatedFor', 'sha256']
                || !is_string($source['identity'])
                || !is_string($source['package'])
                || !is_string($source['processor'])
                || !is_string($source['path'])
                || !is_string($source['generatedFor'])
                || !in_array($source['generatedFor'], ['main', 'development'], true)
                || !is_string($source['sha256'])
                || preg_match('/^[0-9a-f]{64}$/D', $source['sha256']) !== 1
            ) {
                throw $this->stale($path);
            }
            $sources[] = [
                'identity' => $source['identity'],
                'package' => $source['package'],
                'processor' => $source['processor'],
                'path' => $source['path'],
                'generatedFor' => $source['generatedFor'],
                'sha256' => $source['sha256'],
            ];
        }

        return [
            'compilerRevision' => $value['compilerRevision'],
            'owners' => $owners,
            'sources' => $sources,
        ];
    }

    /** @param RegistrySource $source */
    private function validateSource(array $source, ResolvedPackage $owner, string $registryPath): GeneratedSourceInput
    {
        $prefix = 'build/generated/' . $source['processor'] . '/' . $source['generatedFor'] . '/';
        $canonical = realpath($source['path']);
        if (!isset($owner->manifest->processors[$source['processor']])
            || $canonical === false
            || !is_file($canonical)
        ) {
            throw $this->stale($registryPath);
        }
        try {
            $relative = (new ProjectPathResolver($owner->source->root))->projectRelative($canonical);
        } catch (BatonError) {
            throw $this->stale($registryPath);
        }
        if (!str_starts_with($relative, $prefix)
            || $source['identity'] !== $owner->manifest->package->compilerIdentity . ':' . $relative
            || hash_file('sha256', $canonical) !== $source['sha256']
        ) {
            throw $this->stale($registryPath);
        }

        return new GeneratedSourceInput(
            $relative,
            $source['generatedFor'],
            null,
            $relative,
            $source['sha256'],
            $source['processor'],
            $owner->manifest->package->compilerIdentity,
        );
    }

    private function path(string $storageRoot): string
    {
        return $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
    }

    private function stale(string $path): BatonError
    {
        return new BatonError(
            'B0418',
            'Project Generated Sources Are Stale',
            "Required generated-source inventory is missing, corrupt, or stale:\n    {$path}",
            ['Run an online check or build to refresh processor output:'],
            ['baton check'],
        );
    }

    private function writeError(string $path): BatonError
    {
        return new BatonError(
            'B0416',
            'Generated Source Inventory Could Not Be Written',
            $path,
        );
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
