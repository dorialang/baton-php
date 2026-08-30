<?php

declare(strict_types=1);

namespace Doria\Baton\Processor;

use Doria\Baton\Application;
use Doria\Baton\Build\AtomicFileWriter;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Dependency\ManifestFingerprint;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Project\ProjectPathResolver;
use Doria\Baton\Source\DiscoveredSource;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceInventory;

/**
 * Private generated-source registry shared by publication and tooling discovery.
 *
 * @phpstan-type RegistrySource array{
 *   identity: string,
 *   package: string,
 *   processor: string,
 *   path: string,
 *   generatedFor: string,
 *   requestSha256: string,
 *   sha256: string
 * }
 * @phpstan-type RegistryRun array{
 *   processor: string,
 *   sourceIdentitySha256: string,
 *   binaryTarget: string,
 *   requestSha256: string,
 *   graphFingerprint: string
 * }
 * @phpstan-type RegistryOwner array{identitySha256: string, runs: list<RegistryRun>}
 */
final class GeneratedSourceRegistry
{
    private const RELATIVE_PATH = 'build/.baton/generated-sources.json';

    /**
     * @param array<string, ResolvedPackage> $packages
     * @param list<RegistrySource>           $sources
     * @param list<array<string, string>>    $runs
     */
    public function replaceOwner(
        string $storageRoot,
        string $compilerRevision,
        Schema2Manifest $owner,
        SourceInventory $inventory,
        array $packages,
        array $sources,
        array $runs,
    ): void {
        $path = $this->path($storageRoot);
        $lockSha256 = $this->lockSha256($storageRoot);
        try {
            $existing = is_file($path)
                ? $this->decode($path)
                : $this->emptyDocument($compilerRevision, $lockSha256);
        } catch (BatonError) {
            // This registry is disposable private state. A build replaces an old or corrupt shape.
            $existing = $this->emptyDocument($compilerRevision, $lockSha256);
        }
        if ($existing['compilerRevision'] !== $compilerRevision || $existing['lockSha256'] !== $lockSha256) {
            $existing = $this->emptyDocument($compilerRevision, $lockSha256);
        }
        $owners = $existing['owners'];
        $ownerName = $owner->package->name;
        $owners[$ownerName] = [
            'identitySha256' => $this->ownerIdentity($owner, $inventory, $packages),
            'runs' => $this->runs($runs),
        ];
        ksort($owners, SORT_STRING);
        $all = [];
        foreach ($existing['sources'] as $source) {
            if ($source['package'] !== $ownerName) {
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
                'lockSha256' => $lockSha256,
                'owners' => $owners,
                'sources' => $all,
            ]),
            'Generated Source Inventory Could Not Be Written',
        );
    }

    /**
     * @param array<string, ResolvedPackage> $owners   Authored package identity keyed.
     * @param array<string, ResolvedPackage> $packages Complete resolved package graph.
     * @return array{compilerRevision: string, inputs: array<string, list<GeneratedSourceInput>>, sources: list<RegistrySource>}
     */
    public function requireValid(string $storageRoot, array $owners, array $packages): array
    {
        $path = $this->path($storageRoot);
        if (!is_file($path)) {
            throw $this->stale($path);
        }
        $document = $this->decode($path);
        if ($document['lockSha256'] !== $this->lockSha256($storageRoot)) {
            throw $this->stale($path);
        }
        $inputs = [];
        $visible = [];
        foreach ($document['sources'] as $source) {
            $owner = $owners[$source['package']] ?? null;
            if ($owner === null) {
                continue;
            }
            $state = $document['owners'][$source['package']] ?? null;
            if ($state === null) {
                throw $this->stale($path);
            }
            $input = $this->validateSource($source, $owner, $state, $path);
            $inputs[$source['package']][] = $input;
            $visible[] = $source;
        }
        foreach ($owners as $package => $owner) {
            $state = $document['owners'][$package] ?? null;
            if ($owner->manifest->processors !== []
                && ($state === null
                    || $state['identitySha256'] !== $this->ownerIdentity(
                        $owner->manifest,
                        $owner->inventory,
                        $packages,
                    ))
            ) {
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
     * @return array{compilerRevision: string, lockSha256: string, owners: array<string, RegistryOwner>, sources: list<RegistrySource>}
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
            || array_keys($value) !== ['schemaVersion', 'batonVersion', 'compilerRevision', 'lockSha256', 'owners', 'sources']
            || $value['schemaVersion'] !== 1
            || !is_string($value['batonVersion'])
            || !is_string($value['compilerRevision'])
            || $value['compilerRevision'] === ''
            || preg_match('/[\x00-\x1F\x7F]/', $value['compilerRevision']) === 1
            || !is_string($value['lockSha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $value['lockSha256']) !== 1
            || !is_array($value['owners'])
            || array_is_list($value['owners'])
            || !is_array($value['sources'])
            || !array_is_list($value['sources'])
        ) {
            throw $this->stale($path);
        }
        $owners = [];
        foreach ($value['owners'] as $name => $owner) {
            if (!is_string($name) || $name === '' || !is_array($owner) || array_is_list($owner)
                || array_keys($owner) !== ['identitySha256', 'runs']
                || !is_string($owner['identitySha256'])
                || preg_match('/^[0-9a-f]{64}$/D', $owner['identitySha256']) !== 1
                || !is_array($owner['runs']) || !array_is_list($owner['runs'])
            ) {
                throw $this->stale($path);
            }
            $owners[$name] = ['identitySha256' => $owner['identitySha256'], 'runs' => $this->decodeRuns($owner['runs'], $path)];
        }
        $ownerNames = array_keys($owners);
        $sortedOwnerNames = $ownerNames;
        sort($sortedOwnerNames, SORT_STRING);
        if ($ownerNames !== $sortedOwnerNames) {
            throw $this->stale($path);
        }
        $sources = [];
        foreach ($value['sources'] as $source) {
            if (!is_array($source)
                || array_is_list($source)
                || array_keys($source) !== ['identity', 'package', 'processor', 'path', 'generatedFor', 'requestSha256', 'sha256']
                || !is_string($source['identity'])
                || !is_string($source['package'])
                || !is_string($source['processor'])
                || !is_string($source['path'])
                || !is_string($source['generatedFor'])
                || !in_array($source['generatedFor'], ['main', 'development'], true)
                || !is_string($source['requestSha256'])
                || preg_match('/^[0-9a-f]{64}$/D', $source['requestSha256']) !== 1
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
                'requestSha256' => $source['requestSha256'],
                'sha256' => $source['sha256'],
            ];
        }

        return [
            'compilerRevision' => $value['compilerRevision'],
            'lockSha256' => $value['lockSha256'],
            'owners' => $owners,
            'sources' => $sources,
        ];
    }

    /**
     * @param RegistrySource $source
     * @param RegistryOwner  $state
     */
    private function validateSource(
        array $source,
        ResolvedPackage $owner,
        array $state,
        string $registryPath,
    ): GeneratedSourceInput
    {
        $prefix = 'build/generated/' . $source['processor'] . '/' . $source['generatedFor'] . '/';
        $canonical = realpath($source['path']);
        $requestIsCurrent = array_any(
            $state['runs'],
            static fn (array $run): bool => $run['processor'] === $source['processor']
                && $run['requestSha256'] === $source['requestSha256'],
        );
        if (!isset($owner->manifest->processors[$source['processor']])
            || !$requestIsCurrent
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

    /** @return array{compilerRevision: string, lockSha256: string, owners: array<string, RegistryOwner>, sources: list<RegistrySource>} */
    private function emptyDocument(string $compilerRevision, string $lockSha256): array
    {
        return ['compilerRevision' => $compilerRevision, 'lockSha256' => $lockSha256, 'owners' => [], 'sources' => []];
    }

    /** @param array<string, ResolvedPackage> $packages */
    private function ownerIdentity(Schema2Manifest $owner, SourceInventory $inventory, array $packages): string
    {
        $manifestFingerprint = (new ManifestFingerprint())->calculate($owner);
        $processors = [];
        foreach ($owner->processors as $declaration) {
            $processor = $packages[$declaration->package()] ?? null;
            if ($processor === null) {
                throw new \LogicException("Resolved processor package `{$declaration->package()}` is missing.");
            }
            $attributes = $declaration->attributes;
            sort($attributes, SORT_STRING);
            $processors[] = [
                'package' => $declaration->package(),
                'binary' => $declaration->binary,
                'attributes' => $attributes,
                'sourceIdentitySha256' => hash(
                    'sha256',
                    (new ProcessorSourceIdentity())->calculate($processor, $declaration->binary),
                ),
            ];
        }
        usort($processors, static fn (array $left, array $right): int => strcmp($left['package'], $right['package']));

        return hash('sha256', $this->json([
            'manifestFingerprint' => $manifestFingerprint,
            'sourceFingerprint' => $this->sourceFingerprint($inventory),
            'processors' => $processors,
        ]));
    }

    private function sourceFingerprint(SourceInventory $inventory): string
    {
        $sources = $inventory->sources;
        usort($sources, static fn (DiscoveredSource $left, DiscoveredSource $right): int => strcmp(
            $left->relativePath . "\0" . $left->scope . "\0" . $left->origin,
            $right->relativePath . "\0" . $right->scope . "\0" . $right->origin,
        ));
        $context = hash_init('sha256');
        foreach ($sources as $source) {
            $bytes = @file_get_contents($source->canonicalPath);
            if ($bytes === false) {
                throw new BatonError(
                    'B0340',
                    'Path Dependency Could Not Be Read',
                    "Project source could not be read:\n    {$source->canonicalPath}",
                );
            }
            hash_update(
                $context,
                $source->relativePath . "\0" . $source->scope . "\0" . $source->origin . "\0"
                    . ($source->generatedFor ?? '') . "\0" . ($source->producer ?? '') . "\0",
            );
            hash_update($context, pack('N', strlen($bytes)) . $bytes);
        }

        return hash_final($context);
    }

    /**
     * @param list<array<string, string>> $runs
     * @return list<RegistryRun>
     */
    private function runs(array $runs): array
    {
        $result = [];
        foreach ($runs as $run) {
            $result[] = [
                'processor' => $run['processor'],
                'sourceIdentitySha256' => $run['sourceIdentitySha256'],
                'binaryTarget' => $run['binaryTarget'],
                'requestSha256' => $run['requestSha256'],
                'graphFingerprint' => $run['graphFingerprint'],
            ];
        }
        usort($result, static fn (array $left, array $right): int => strcmp(
            $left['processor'] . "\0" . $left['requestSha256'],
            $right['processor'] . "\0" . $right['requestSha256'],
        ));

        return $result;
    }

    /**
     * @param list<mixed> $runs
     * @return list<RegistryRun>
     */
    private function decodeRuns(array $runs, string $path): array
    {
        $decoded = [];
        foreach ($runs as $run) {
            if (!is_array($run) || array_is_list($run)
                || array_keys($run) !== ['processor', 'sourceIdentitySha256', 'binaryTarget', 'requestSha256', 'graphFingerprint']
                || !is_string($run['processor']) || $run['processor'] === ''
                || !is_string($run['sourceIdentitySha256']) || $run['sourceIdentitySha256'] === ''
                || !is_string($run['binaryTarget']) || $run['binaryTarget'] === ''
                || !is_string($run['requestSha256']) || $run['requestSha256'] === ''
                || !is_string($run['graphFingerprint']) || $run['graphFingerprint'] === ''
                || preg_match('/^[0-9a-f]{64}$/D', $run['sourceIdentitySha256']) !== 1
                || preg_match('/^[0-9a-f]{64}$/D', $run['requestSha256']) !== 1
            ) {
                throw $this->stale($path);
            }
            $decoded[] = [
                'processor' => $run['processor'],
                'sourceIdentitySha256' => $run['sourceIdentitySha256'],
                'binaryTarget' => $run['binaryTarget'],
                'requestSha256' => $run['requestSha256'],
                'graphFingerprint' => $run['graphFingerprint'],
            ];
        }

        $sorted = $decoded;
        usort($sorted, static fn (array $left, array $right): int => strcmp(
            $left['processor'] . "\0" . $left['requestSha256'],
            $right['processor'] . "\0" . $right['requestSha256'],
        ));
        if ($decoded !== $sorted) {
            throw $this->stale($path);
        }

        return $decoded;
    }

    private function lockSha256(string $storageRoot): string
    {
        $path = $storageRoot . DIRECTORY_SEPARATOR . 'Baton.lock';
        $hash = is_file($path) ? hash_file('sha256', $path) : hash('sha256', '');
        if (!is_string($hash)) {
            throw $this->stale($path);
        }

        return $hash;
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
