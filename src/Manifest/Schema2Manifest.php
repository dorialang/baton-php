<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class Schema2Manifest
{
    public int $manifestVersion;

    /**
     * @param array<string, DependencyDeclaration> $dependencies
     * @param array<string, DependencyDeclaration> $developmentDependencies
     * @param array<string, ProcessorDeclaration>  $processors
     */
    public function __construct(
        public PackageDefinition $package,
        public TargetCollection $targets,
        public AutoloadConfiguration $autoload,
        public array $dependencies = [],
        public array $developmentDependencies = [],
        public array $processors = [],
        public ?WorkspaceDefinition $workspace = null,
    ) {
        $this->manifestVersion = 2;
    }

    /** @return array<string, DependencyDeclaration> */
    public function declaredDependencies(bool $development, bool $processors): array
    {
        $declared = $this->dependencies;
        if ($development) {
            $declared = [...$declared, ...$this->developmentDependencies];
        }
        if ($processors) {
            foreach ($this->processors as $package => $processor) {
                $declared[$package] = $processor->dependency;
            }
        }
        ksort($declared, SORT_STRING);

        return $declared;
    }
}
