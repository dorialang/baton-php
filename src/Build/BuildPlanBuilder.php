<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Manifest\NamespaceMapping;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\DiscoveredSource;
use Doria\Baton\Source\SourceInventory;

final class BuildPlanBuilder
{
    public function build(
        string $canonicalProjectRoot,
        Schema2Manifest $manifest,
        SelectedPackageTarget $selected,
        SourceInventory $inventory,
        string $nativeProfile,
    ): BuildPlan {
        $mappings = array_map(
            static fn (NamespaceMapping $mapping): array => [
                'prefix' => $mapping->prefix,
                'path' => $mapping->path,
                'scope' => $mapping->scope,
                'generatedFor' => null,
            ],
            $manifest->autoload->all(),
        );
        usort(
            $mappings,
            static fn (array $left, array $right): int => strcmp(
                $left['scope'] . "\0" . $left['prefix'] . "\0" . $left['path'],
                $right['scope'] . "\0" . $right['prefix'] . "\0" . $right['path'],
            ),
        );

        $identity = $manifest->package->compilerIdentity;
        $sources = array_map(
            static fn (DiscoveredSource $source): array => [
                'identity' => $identity . ':' . $source->relativePath,
                'path' => $source->relativePath,
                'scope' => $source->scope,
                'origin' => $source->origin,
                'generatedFor' => $source->generatedFor,
            ],
            $inventory->sources,
        );
        usort(
            $sources,
            static fn (array $left, array $right): int => strcmp($left['identity'], $right['identity']),
        );

        $activeScopes = ['main'];
        foreach ($inventory->sources as $source) {
            if ($source->scope === 'generated' && $source->generatedFor === 'main') {
                $activeScopes[] = 'generated';
                break;
            }
        }
        $entry = $selected->entry();

        return new BuildPlan([
            'schemaVersion' => 1,
            'edition' => $manifest->package->edition,
            'rootPackage' => $identity,
            'selectedTarget' => [
                'package' => $identity,
                'name' => $selected->name(),
                'kind' => $selected->kind(),
                'entrySource' => $entry === null ? null : $identity . ':' . str_replace('\\', '/', $entry),
                'activeScopes' => $activeScopes,
            ],
            'packages' => [[
                'identity' => $identity,
                'root' => $canonicalProjectRoot,
                'namespaceMappings' => $mappings,
                'sources' => $sources,
                'dependencies' => [],
            ]],
            'compiler' => [
                'target' => 'native',
                'nativeProfile' => $nativeProfile,
                'targetTriple' => null,
            ],
        ]);
    }
}
