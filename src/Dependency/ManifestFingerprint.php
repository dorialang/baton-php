<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\DependencyDeclaration;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\NamespaceMapping;
use Doria\Baton\Manifest\PathDependencySource;
use Doria\Baton\Manifest\Schema2Manifest;

final class ManifestFingerprint
{
    public function calculate(Schema2Manifest $manifest): string
    {
        $targets = array_map(
            static fn ($target): array => [
                'kind' => $target->kind(),
                'name' => $target->name(),
                'entry' => $target->entry(),
            ],
            $manifest->targets->all(),
        );
        usort($targets, static fn (array $left, array $right): int => strcmp(
            $left['kind'] . "\0" . $left['name'],
            $right['kind'] . "\0" . $right['name'],
        ));

        $mappings = array_map(
            static fn (NamespaceMapping $mapping): array => [
                'prefix' => $mapping->prefix,
                'path' => $mapping->path,
                'scope' => $mapping->scope,
                'include' => $mapping->patterns->include,
                'exclude' => $mapping->patterns->exclude,
            ],
            $manifest->autoload->main,
        );
        usort($mappings, static fn (array $left, array $right): int => strcmp(
            $left['scope'] . "\0" . $left['prefix'] . "\0" . $left['path'],
            $right['scope'] . "\0" . $right['prefix'] . "\0" . $right['path'],
        ));

        $dependencies = array_map(
            static function (DependencyDeclaration $dependency): array {
                $source = $dependency->source;
                $sourceData = $source instanceof PathDependencySource
                    ? ['kind' => 'path', 'path' => str_replace('\\', '/', $source->path)]
                    : [
                        'kind' => 'git',
                        'url' => $source instanceof GitDependencySource ? $source->url : '',
                        'selector' => $source instanceof GitDependencySource
                            ? ['kind' => $source->selector->kind, 'value' => $source->selector->value]
                            : null,
                    ];

                return [
                    'package' => $dependency->package,
                    'source' => $sourceData,
                    'version' => $dependency->version?->expression,
                ];
            },
            array_values($manifest->dependencies),
        );
        usort($dependencies, static fn (array $left, array $right): int => strcmp(
            $left['package'],
            $right['package'],
        ));

        $document = [
            'manifestVersion' => 2,
            'package' => [
                'name' => $manifest->package->name,
                'compilerPackage' => $manifest->package->compilerIdentity,
                'version' => $manifest->package->version,
                'edition' => $manifest->package->edition,
                'publishable' => $manifest->package->publishable,
            ],
            'targets' => $targets,
            'autoload' => $mappings,
            'dependencies' => $dependencies,
        ];

        return hash('sha256', json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
