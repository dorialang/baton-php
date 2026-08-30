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
            $manifest->autoload->all(),
        );
        usort($mappings, static fn (array $left, array $right): int => strcmp(
            $left['scope'] . "\0" . $left['prefix'] . "\0" . $left['path'],
            $right['scope'] . "\0" . $right['prefix'] . "\0" . $right['path'],
        ));

        $dependencyDeclarations = $manifest->declaredDependencyEdges(true, true);
        usort($dependencyDeclarations, static fn (DependencyDeclaration $left, DependencyDeclaration $right): int => strcmp(
            $left->package . "\0" . $left->kind->value,
            $right->package . "\0" . $right->kind->value,
        ));
        $dependencies = array_map(
            fn (DependencyDeclaration $dependency): array => $this->dependency($dependency),
            $dependencyDeclarations,
        );

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
            'dependencies' => array_values(array_filter(
                $dependencies,
                static fn (array $dependency): bool => $dependency['kind'] === 'normal',
            )),
            'developmentDependencies' => array_values(array_filter(
                $dependencies,
                static fn (array $dependency): bool => $dependency['kind'] === 'development',
            )),
            'processors' => array_map(
                fn (\Doria\Baton\Manifest\ProcessorDeclaration $processor): array => [
                    ...$this->dependency($processor->dependency),
                    'binary' => $processor->binary,
                    'attributes' => $processor->attributes,
                ],
                array_values($manifest->processors),
            ),
            'workspace' => $manifest->workspace === null ? null : ['members' => $manifest->workspace->members],
        ];

        return hash('sha256', json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string, mixed> */
    private function dependency(DependencyDeclaration $dependency): array
    {
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
            'kind' => $dependency->kind->value,
            'source' => $sourceData,
            'version' => $dependency->version?->expression,
        ];
    }
}
