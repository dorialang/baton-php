<?php

declare(strict_types=1);

namespace Doria\Baton\Inventory;

use Doria\Baton\Application;
use Doria\Baton\Build\AtomicFileWriter;
use Doria\Baton\Build\Schema2ProjectContext;
use Doria\Baton\Dependency\ManifestFingerprint;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Source\DiscoveredSource;

final class ManagedInventoryStore
{
    private const RELATIVE_PATH = 'build/.baton/inventory.json';

    public function recordContext(string $storageRoot, Schema2ProjectContext $context): void
    {
        $packages = [];
        $rootPackage = new ResolvedPackage(
            $context->manifest,
            new \Doria\Baton\Dependency\ResolvedPackageSource('workspace', $context->projectRoot, '.'),
            (new ManifestFingerprint())->calculate($context->manifest),
            $context->inventory,
        );
        foreach ([$rootPackage, ...$context->graph->sortedPackages()] as $package) {
            $packages[$package->manifest->package->compilerIdentity] = [
                'manifestFingerprint' => $package->manifestFingerprint,
                'sources' => $this->sources($storageRoot, $package->inventory->sources),
            ];
        }
        $selection = [
            'package' => $context->manifest->package->compilerIdentity,
            'target' => $context->selected->name(),
            'targetKind' => $context->selected->kind(),
            'profile' => $context->profile,
            'hostTarget' => $context->toolchain->identity->target,
        ];
        $key = hash('sha256', $this->json($selection));
        $workspace = null;
        if ($context->graph instanceof ResolvedWorkspaceGraph) {
            $members = [];
            foreach ($context->graph->workspace->sortedMembers() as $member) {
                $members[$member->manifest->package->compilerIdentity] = (new ManifestFingerprint())
                    ->calculate($member->manifest);
            }
            $workspace = [
                'fingerprint' => $context->graph->workspaceFingerprint,
                'memberManifests' => $members,
            ];
        }
        $this->update($storageRoot, static function (array $document) use (
            $context,
            $key,
            $selection,
            $packages,
            $workspace,
        ): array {
            $contexts = self::object($document['contexts'] ?? null);
            $contexts[$key] = [
                'selection' => $selection,
                'lockSha256' => $context->lockSha256,
                'workspace' => $workspace,
                'packages' => $packages,
                'buildPlanSha256' => $context->buildPlan->sha256,
            ];
            $document['contexts'] = $contexts;
            $document['compilerRevision'] = $context->toolchain->identity->commit;

            return $document;
        });
    }

    /** @param list<array<string, string>> $facts */
    public function recordProcessors(
        string $storageRoot,
        string $compilerRevision,
        string $owner,
        string $metadataSha256,
        array $facts,
    ): void {
        usort($facts, static fn (array $left, array $right): int => strcmp(
            $left['processor'] . "\0" . $left['requestSha256'],
            $right['processor'] . "\0" . $right['requestSha256'],
        ));
        $this->update($storageRoot, static function (array $document) use (
            $compilerRevision,
            $owner,
            $metadataSha256,
            $facts,
        ): array {
            $processors = self::object($document['processors'] ?? null);
            $processors[$owner] = ['metadataSha256' => $metadataSha256, 'runs' => $facts];
            $document['processors'] = $processors;
            $document['compilerRevision'] = $compilerRevision;

            return $document;
        });
    }

    public function removeProcessors(string $storageRoot, string $compilerRevision, string $owner): void
    {
        $this->update($storageRoot, static function (array $document) use ($compilerRevision, $owner): array {
            $processors = self::object($document['processors'] ?? null);
            unset($processors[$owner]);
            $document['processors'] = $processors;
            $document['compilerRevision'] = $compilerRevision;

            return $document;
        });
    }

    /** @param list<array<string, mixed>> $tests */
    public function recordTests(
        string $storageRoot,
        string $compilerRevision,
        string $package,
        string $metadataSha256,
        string $testInventorySha256,
        string $buildPlanSha256,
        string $dispatcherSha256,
        string $artifactSha256,
        array $tests,
    ): void {
        $this->update($storageRoot, static function (array $document) use (
            $compilerRevision,
            $package,
            $metadataSha256,
            $testInventorySha256,
            $buildPlanSha256,
            $dispatcherSha256,
            $artifactSha256,
            $tests,
        ): array {
            $inventories = self::object($document['tests'] ?? null);
            $inventories[$package] = [
                'metadataSha256' => $metadataSha256,
                'testInventorySha256' => $testInventorySha256,
                'buildPlanSha256' => $buildPlanSha256,
                'dispatcherSha256' => $dispatcherSha256,
                'artifactSha256' => $artifactSha256,
                'tests' => $tests,
            ];
            $document['tests'] = $inventories;
            $document['compilerRevision'] = $compilerRevision;

            return $document;
        });
    }

    public function recordSuccessfulOutput(
        string $storageRoot,
        Schema2ProjectContext $context,
        ?string $artifact,
    ): void {
        $artifactFact = null;
        if ($artifact !== null) {
            $artifactFact = [
                'path' => $this->relative($storageRoot, $artifact),
                'sha256' => $this->fileHash($artifact),
            ];
        }
        $key = $context->manifest->package->compilerIdentity . ':' . $context->selected->name();
        $this->update($storageRoot, static function (array $document) use ($context, $key, $artifactFact): array {
            $outputs = self::object($document['successfulOutputs'] ?? null);
            $outputs[$key] = [
                'package' => $context->manifest->package->compilerIdentity,
                'target' => $context->selected->name(),
                'targetKind' => $context->selected->kind(),
                'profile' => $context->profile,
                'hostTarget' => $context->toolchain->identity->target,
                'buildPlanSha256' => $context->buildPlan->sha256,
                'artifact' => $artifactFact,
            ];
            $document['successfulOutputs'] = $outputs;
            $document['compilerRevision'] = $context->toolchain->identity->commit;

            return $document;
        });
    }

    /**
     * @param list<DiscoveredSource> $sources
     * @return list<array<string, string|null>>
     */
    private function sources(string $storageRoot, array $sources): array
    {
        $facts = [];
        foreach ($sources as $source) {
            $facts[] = [
                'identity' => $source->relativePath,
                'path' => $this->relative($storageRoot, $source->canonicalPath),
                'scope' => $source->scope,
                'origin' => $source->origin,
                'generatedFor' => $source->generatedFor,
                'producer' => $source->producer,
                'sha256' => $this->fileHash($source->canonicalPath),
            ];
        }
        usort($facts, static fn (array $left, array $right): int => strcmp(
            (string) $left['identity'],
            (string) $right['identity'],
        ));

        return $facts;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function update(string $storageRoot, callable $change): void
    {
        $path = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new BatonError('B0426', 'Project Inventory Could Not Be Written', $directory);
        }
        $document = $this->load($path);
        $document = $change($document);
        $document['schemaVersion'] = 1;
        $document['batonVersion'] = Application::VERSION;
        $document = $this->sort($document);
        (new AtomicFileWriter())->write(
            $path,
            $this->json($document) . "\n",
            'Project Inventory Could Not Be Written',
        );
    }

    /** @return array<string, mixed> */
    private function load(string $path): array
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            return [];
        }
        try {
            $value = json_decode($bytes, true, 256, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($value) || array_is_list($value) || ($value['schemaVersion'] ?? null) !== 1) {
            return [];
        }
        $document = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return [];
            }
            $document[$key] = $item;
        }

        return $document;
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function sort(array $document): array
    {
        ksort($document, SORT_STRING);
        foreach ($document as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $document[$key] = $this->sort($this->stringKeys($value));
            }
        }

        return $document;
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private function stringKeys(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        if ($path === $root) {
            return '.';
        }
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    private function fileHash(string $path): string
    {
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new BatonError('B0426', 'Project Inventory Could Not Be Written', "File could not be hashed:\n    {$path}");
        }

        return $hash;
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
