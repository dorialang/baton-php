<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use Composer\Semver\VersionParser;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Project\ProjectLocator;
use PhpCollective\Toml\Toml;
use PhpCollective\Toml\TomlVersion;
use UnexpectedValueException;

/** Parses TOML 1.0 once, then validates one of Baton's distinct typed schemas. */
final class ManifestLoader
{
    /** @var list<string> */
    private const SCHEMA_1_TOP_LEVEL = ['manifest-version', 'package'];

    /** @var list<string> */
    private const SCHEMA_1_PACKAGE = ['name', 'version', 'kind', 'entry'];

    /** @var list<string> */
    private const SCHEMA_2_TOP_LEVEL = [
        'manifest-version',
        'package',
        'targets',
        'autoload',
        'autoload-dev',
        'dependencies',
        'dev-dependencies',
        'processors',
        'workspace',
    ];

    /** @var list<string> */
    private const SCHEMA_2_PACKAGE = [
        'name',
        'version',
        'edition',
        'publishable',
        'kind',
        'entry',
    ];

    /** @var list<string> */
    private const MAPPING_FIELDS = ['path', 'include', 'exclude'];

    private string $manifestPath = '';

    private ?TomlLocationIndex $locations = null;

    public function load(string $projectRoot): Manifest|Schema2Manifest|WorkspaceManifest
    {
        $this->manifestPath = $projectRoot . DIRECTORY_SEPARATOR . ProjectLocator::MANIFEST_FILE;
        $contents = @file_get_contents($this->manifestPath);
        if ($contents === false) {
            throw $this->error(
                'B0302',
                'Project Manifest Could Not Be Read',
                '',
                "The manifest could not be read:\n    {$this->manifestPath}",
            );
        }

        return $this->parse($projectRoot, $contents);
    }

    public function loadContents(string $projectRoot, string $contents): Manifest|Schema2Manifest|WorkspaceManifest
    {
        $this->manifestPath = $projectRoot . DIRECTORY_SEPARATOR . ProjectLocator::MANIFEST_FILE;

        return $this->parse($projectRoot, $contents);
    }

    private function parse(string $projectRoot, string $contents): Manifest|Schema2Manifest|WorkspaceManifest
    {

        $result = Toml::tryParse($contents, TomlVersion::V10);
        $document = $result->getDocument();
        $this->locations = $document === null ? null : new TomlLocationIndex($document);
        if (!$result->isValid()) {
            $parseError = $result->getErrors()[0];
            $body = $this->manifestPath
                . ":{$parseError->span->line}:"
                . ($parseError->span->column + 1)
                . "\n{$parseError->message}";
            if ($parseError->hint !== null) {
                $body .= "\nExpected: {$parseError->hint}";
            }
            throw $this->error('B0302', 'Project Manifest TOML Is Invalid', '', $body);
        }

        $values = $result->getValue();
        if ($values === null) {
            throw $this->error(
                'B0302',
                'Project Manifest TOML Is Invalid',
                '',
                'The TOML parser did not produce a manifest value.',
            );
        }

        $manifestVersion = $this->requireInt($values, 'manifest-version');

        return match ($manifestVersion) {
            1 => $this->schema1($values),
            2 => $this->schema2($projectRoot, $values),
            default => throw $this->error(
                'B0303',
                'Manifest Schema Version Is Unsupported',
                'manifest-version',
                "Found manifest schema {$manifestVersion}; expected 1 or 2.",
            ),
        };
    }

    /** @param array<string, mixed> $values */
    private function schema1(array $values): Manifest
    {
        $this->rejectUnknown($values, self::SCHEMA_1_TOP_LEVEL, '');
        $package = $this->requireTable($values, 'package');
        $this->rejectUnknown($package, self::SCHEMA_1_PACKAGE, 'package');

        $name = $this->requireString($package, 'name', 'package');
        if (!$this->isSlug($name)) {
            throw $this->error(
                'B0305',
                'Package Identity Is Invalid',
                'package.name',
                "Invalid schema-1 package name `{$name}`. Use lowercase ASCII letters, "
                    . "digits, '-', or '_'.",
            );
        }

        $version = $this->requireString($package, 'version', 'package');
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+].+)?$/', $version) !== 1) {
            throw $this->error(
                'B0307',
                'Package Version Is Invalid',
                'package.version',
                "Schema-1 package version `{$version}` does not match its historical SemVer shape.",
            );
        }

        $kind = $this->requireString($package, 'kind', 'package');
        if ($kind !== 'binary') {
            throw $this->error(
                'B0309',
                'Package Target Declaration Is Invalid',
                'package.kind',
                "Schema 1 supports only `kind = \"binary\"`; found `{$kind}`.",
            );
        }

        $entry = $this->requireString($package, 'entry', 'package');
        $this->assertRelativePath($entry, 'package.entry', 'Entry Source Escapes Project');

        return new Manifest(1, $name, $version, $kind, $entry);
    }

    /** @param array<string, mixed> $values */
    private function schema2(string $projectRoot, array $values): Schema2Manifest|WorkspaceManifest
    {
        $this->rejectUnknown($values, self::SCHEMA_2_TOP_LEVEL, '');
        $workspace = $this->workspace($values);
        if (!array_key_exists('package', $values)) {
            foreach (['targets', 'autoload', 'autoload-dev', 'dependencies', 'dev-dependencies', 'processors'] as $field) {
                if (array_key_exists($field, $values)) {
                    throw $this->error(
                        'B0390',
                        'Virtual Workspace Cannot Declare Package Fields',
                        $field,
                        "A virtual workspace may contain only `manifest-version` and `[workspace]`; move `{$field}` into a member package.",
                    );
                }
            }
            if ($workspace === null) {
                throw $this->missing('package');
            }
            if ($workspace->members === []) {
                throw $this->error(
                    'B0391',
                    'Workspace Members Are Missing',
                    'workspace.members',
                    'A virtual workspace must select at least one member package.',
                );
            }

            return new WorkspaceManifest($workspace);
        }

        $packageValues = $this->requireTable($values, 'package');
        $this->rejectUnknown($packageValues, self::SCHEMA_2_PACKAGE, 'package');
        $package = $this->schema2Package($packageValues);
        $targets = $this->schema2Targets($values, $package);
        $autoload = new AutoloadConfiguration(
            $this->autoloadMappings($values, 'autoload', 'main'),
            $this->autoloadMappings($values, 'autoload-dev', 'development'),
        );

        $this->rejectMappingOverlap($autoload);

        $dependencies = $this->dependencies($values, 'dependencies', DependencyKind::Normal);
        $developmentDependencies = $this->dependencies(
            $values,
            'dev-dependencies',
            DependencyKind::Development,
        );
        foreach (array_intersect_key($dependencies, $developmentDependencies) as $duplicatePackage => $_dependency) {
            throw $this->error(
                'B0392',
                'Development Dependency Is Duplicated As Normal',
                "dev-dependencies.{$duplicatePackage}",
                "Package `{$duplicatePackage}` is declared in both `[dependencies]` and `[dev-dependencies]`; choose one category.",
            );
        }
        $processors = $this->processors($values);

        return new Schema2Manifest(
            $package,
            $targets,
            $autoload,
            $dependencies,
            $developmentDependencies,
            $processors,
            $workspace,
        );
    }

    /** @param array<string, mixed> $values */
    private function schema2Package(array $values): PackageDefinition
    {
        $name = $this->requireString($values, 'name', 'package');
        $segments = explode('/', $name);
        $scoped = count($segments) === 2;
        if ((!$scoped && count($segments) !== 1)
            || array_filter($segments, fn (string $segment): bool => !$this->isSlug($segment)) !== []
        ) {
            throw $this->error(
                'B0305',
                'Package Identity Is Invalid',
                'package.name',
                "Package name `{$name}` must be one lowercase slug or `vendor/package`.",
            );
        }
        if ($scoped && $segments[0] === 'local') {
            throw $this->error(
                'B0306',
                'Synthetic Local Vendor Is Reserved',
                'package.name',
                '`local` is reserved for deterministic compiler identities of unscoped packages.',
            );
        }

        $hasPublishable = array_key_exists('publishable', $values);
        if (!$scoped) {
            if (!$hasPublishable) {
                throw $this->error(
                    'B0306',
                    'Local Package Must Be Non-Publishable',
                    'package.publishable',
                    'An unscoped package requires the explicit TOML boolean `publishable = false`.',
                );
            }
            $publishable = $this->requireBool($values, 'publishable', 'package');
            if ($publishable) {
                throw $this->error(
                    'B0306',
                    'Local Package Must Be Non-Publishable',
                    'package.publishable',
                    'An unscoped package requires the explicit TOML boolean `publishable = false`.',
                );
            }
            $compilerIdentity = 'local/' . $name;
        } else {
            $publishable = $hasPublishable
                ? $this->requireBool($values, 'publishable', 'package')
                : true;
            $compilerIdentity = $name;
        }

        $version = $this->requireString($values, 'version', 'package');
        if (!$this->isStrictSemver($version)) {
            throw $this->error(
                'B0307',
                'Package Version Is Invalid',
                'package.version',
                "Package version `{$version}` must be a valid SemVer version.",
            );
        }

        $edition = $this->requireString($values, 'edition', 'package');
        if ($edition !== '2026') {
            throw $this->error(
                'B0308',
                'Doria Edition Is Unsupported',
                'package.edition',
                "Edition `{$edition}` is unsupported; this toolchain accepts `2026`.",
            );
        }

        return new PackageDefinition(
            $name,
            $compilerIdentity,
            $version,
            $edition,
            $publishable,
        );
    }

    /** @param array<string, mixed> $values */
    private function schema2Targets(array $values, PackageDefinition $package): TargetCollection
    {
        /** @var array<string, mixed> $packageValues */
        $packageValues = $values['package'];
        $hasShorthand = array_key_exists('kind', $packageValues)
            || array_key_exists('entry', $packageValues);
        $hasExplicit = array_key_exists('targets', $values);
        if ($hasShorthand && $hasExplicit) {
            throw $this->error(
                'B0309',
                'Package Target Modes Conflict',
                'targets',
                'Package-level `kind`/`entry` cannot be combined with explicit target tables.',
            );
        }

        if ($hasShorthand) {
            $kind = $this->requireString($packageValues, 'kind', 'package');
            if ($kind !== 'binary') {
                throw $this->error(
                    'B0309',
                    'Package Target Declaration Is Invalid',
                    'package.kind',
                    'The schema-2 shorthand accepts only `kind = "binary"`; use '
                        . '`[targets.library]` for a library.',
                );
            }
            $entry = $this->requireString($packageValues, 'entry', 'package');
            $this->assertEntryPath($entry, 'package.entry');
            $nameSegments = explode('/', $package->name);

            return new TargetCollection(
                null,
                [new BinaryTarget($nameSegments[count($nameSegments) - 1], $entry)],
            );
        }

        if (!$hasExplicit) {
            throw $this->error(
                'B0309',
                'Package Target Declaration Is Missing',
                'targets',
                'Declare the single-binary shorthand or explicit `[targets.library]` / '
                    . '`[[targets.binary]]` tables.',
            );
        }

        $targetValues = $this->requireTable($values, 'targets');
        $this->rejectUnknown($targetValues, ['library', 'binary'], 'targets');
        $library = null;
        if (array_key_exists('library', $targetValues)) {
            $libraryValues = $this->requireTable($targetValues, 'library', 'targets');
            $this->rejectUnknown($libraryValues, ['name'], 'targets.library');
            $library = new LibraryTarget(
                $this->targetName($this->requireString($libraryValues, 'name', 'targets.library')),
            );
        }

        $binaries = [];
        if (array_key_exists('binary', $targetValues)) {
            $binaryValues = $targetValues['binary'];
            if (!is_array($binaryValues) || !array_is_list($binaryValues)) {
                throw $this->wrongType('targets.binary', 'an array of tables', $binaryValues);
            }
            foreach ($binaryValues as $index => $binaryValue) {
                if (!is_array($binaryValue) || array_is_list($binaryValue)) {
                    throw $this->wrongType("targets.binary.{$index}", 'a table', $binaryValue);
                }
                $path = "targets.binary.{$index}";
                $binaryValue = $this->stringKeyedTable($binaryValue, $path);
                $this->rejectUnknown($binaryValue, ['name', 'entry'], $path);
                $name = $this->targetName($this->requireString($binaryValue, 'name', $path));
                $entry = $this->requireString($binaryValue, 'entry', $path);
                $this->assertEntryPath($entry, "{$path}.entry");
                $binaries[] = new BinaryTarget($name, $entry);
            }
        }

        if ($library === null && $binaries === []) {
            throw $this->error(
                'B0309',
                'Package Target Declaration Is Missing',
                'targets',
                'At least one library or binary target is required.',
            );
        }
        $names = [];
        foreach ($library === null ? $binaries : [$library, ...$binaries] as $target) {
            if (isset($names[$target->name()])) {
                throw $this->error(
                    'B0310',
                    'Target Name Is Duplicated',
                    'targets',
                    "Target name `{$target->name()}` is used more than once.",
                );
            }
            $names[$target->name()] = true;
        }

        return new TargetCollection($library, $binaries);
    }

    /**
     * @param array<string, mixed> $values
     * @return list<NamespaceMapping>
     */
    private function autoloadMappings(array $values, string $tableName, string $scope): array
    {
        if (!array_key_exists($tableName, $values)) {
            return [];
        }

        $autoload = $this->requireTable($values, $tableName);
        $this->rejectUnknown($autoload, ['namespaces'], $tableName);
        if (!array_key_exists('namespaces', $autoload)) {
            return [];
        }
        $namespaceValues = $this->requireTable($autoload, 'namespaces', $tableName);
        $mappings = [];
        foreach ($namespaceValues as $prefix => $mappingValue) {
            if (!$this->isNamespacePrefix($prefix)) {
                throw $this->error(
                    'B0312',
                    'Namespace Mapping Prefix Is Invalid',
                    "{$tableName}.namespaces.{$prefix}",
                    "Namespace prefix `{$prefix}` must be empty or contain PascalCase "
                        . 'folded-acronym segments and end in `\\`.',
                );
            }

            if (is_string($mappingValue)) {
                $mappingPath = $mappingValue;
                $patterns = new SourcePatternSet(['**/*.doria'], []);
            } elseif (is_array($mappingValue) && !array_is_list($mappingValue)) {
                $path = "{$tableName}.namespaces.{$prefix}";
                $mappingValue = $this->stringKeyedTable($mappingValue, $path);
                $this->rejectUnknown($mappingValue, self::MAPPING_FIELDS, $path);
                $mappingPath = $this->requireString($mappingValue, 'path', $path);
                $include = array_key_exists('include', $mappingValue)
                    ? $this->nonEmptyStringList($mappingValue['include'], "{$path}.include")
                    : ['**/*.doria'];
                $exclude = array_key_exists('exclude', $mappingValue)
                    ? $this->stringList($mappingValue['exclude'], "{$path}.exclude")
                    : [];
                $patterns = new SourcePatternSet(
                    array_map(fn (string $pattern): string => $this->pattern($pattern, "{$path}.include"), $include),
                    array_map(fn (string $pattern): string => $this->pattern($pattern, "{$path}.exclude"), $exclude),
                );
            } else {
                throw $this->wrongType(
                    "{$tableName}.namespaces.{$prefix}",
                    'a path string or mapping table',
                    $mappingValue,
                );
            }

            $this->assertRelativePath(
                $mappingPath,
                "{$tableName}.namespaces.{$prefix}.path",
                'Autoload Path Escapes Project',
                true,
            );
            $mappings[] = new NamespaceMapping(
                $prefix,
                $this->normalizeMappingPath($mappingPath),
                $scope,
                $patterns,
            );
        }

        return $mappings;
    }

    private function rejectMappingOverlap(AutoloadConfiguration $autoload): void
    {
        $main = [];
        foreach ($autoload->main as $mapping) {
            $main[$mapping->prefix . "\0" . $mapping->path] = true;
        }
        foreach ($autoload->development as $mapping) {
            if (isset($main[$mapping->prefix . "\0" . $mapping->path])) {
                throw $this->error(
                    'B0318',
                    'Source Has Conflicting Scopes',
                    'autoload-dev.namespaces',
                    "Namespace mapping `{$mapping->prefix}` at `{$mapping->path}` is declared "
                        . 'in both main and development scopes.',
                );
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function workspace(array $values): ?WorkspaceDefinition
    {
        if (!array_key_exists('workspace', $values)) {
            return null;
        }
        $workspace = $this->requireTable($values, 'workspace');
        $this->rejectUnknown($workspace, ['members'], 'workspace');
        if (!array_key_exists('members', $workspace)) {
            throw $this->error(
                'B0391',
                'Workspace Members Are Missing',
                'workspace.members',
                'Every workspace must declare its member patterns explicitly.',
            );
        }
        $members = $this->stringList($workspace['members'], 'workspace.members');
        $normalized = [];
        foreach ($members as $index => $pattern) {
            $normalized[] = $this->workspacePattern($pattern, "workspace.members.{$index}");
        }

        return new WorkspaceDefinition($normalized);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, DependencyDeclaration>
     */
    private function dependencies(
        array $values,
        string $tableName = 'dependencies',
        DependencyKind $kind = DependencyKind::Normal,
    ): array
    {
        if (!array_key_exists($tableName, $values)) {
            return [];
        }
        $table = $this->requireTable($values, $tableName);
        $dependencies = [];
        foreach ($table as $package => $declaration) {
            $path = "{$tableName}.{$package}";
            $this->assertDependencyPackageName($package, $path);
            if (!is_array($declaration) || array_is_list($declaration)) {
                throw $this->wrongType($path, 'an inline dependency table', $declaration);
            }
            $declaration = $this->stringKeyedTable($declaration, $path);
            $this->rejectUnknown(
                $declaration,
                ['source', 'path', 'url', 'git', 'rev', 'tag', 'branch', 'version'],
                $path,
            );
            if (array_key_exists('git', $declaration)) {
                throw $this->error(
                    'B0393',
                    'Git Source Locator Spelling Has Changed',
                    "{$path}.git",
                    "Git remains supported. Replace `git = \"...\"` with `source = \"git\"` and `url = \"...\"`.",
                );
            }
            if (!array_key_exists('source', $declaration)) {
                $help = array_key_exists('path', $declaration)
                    ? 'Add `source = "path"` to this dependency declaration.'
                    : 'Declare `source = "path"` or `source = "git"` explicitly.';
                throw $this->error('B0330', 'Dependency Source Must Be Declared', $path, $help);
            }
            $sourceKind = $this->requireString($declaration, 'source', $path);
            if (!in_array($sourceKind, ['path', 'git'], true)) {
                throw $this->error(
                    'B0394',
                    'Dependency Source Is Unsupported',
                    "{$path}.source",
                    "Source transport `{$sourceKind}` is not implemented; this toolchain supports `path` and `git`.",
                );
            }

            $version = null;
            if (array_key_exists('version', $declaration)) {
                $expression = $this->requireString($declaration, 'version', $path);
                try {
                    $version = PackageVersionConstraint::parse($expression);
                } catch (UnexpectedValueException) {
                    throw $this->error(
                        'B0331',
                        'Dependency Version Constraint Is Invalid',
                        "{$path}.version",
                        "Constraint `{$expression}` must be exact SemVer, caret, tilde, or a comparator conjunction.",
                    );
                }
            }

            if ($sourceKind === 'path') {
                if (array_key_exists('url', $declaration)) {
                    throw $this->error(
                        'B0330',
                        'Dependency Source Modes Conflict',
                        "{$path}.url",
                        'A path source uses `path`, not `url`.',
                    );
                }
                foreach (['rev', 'tag', 'branch'] as $selector) {
                    if (array_key_exists($selector, $declaration)) {
                        throw $this->error(
                            'B0330',
                            'Dependency Source Modes Conflict',
                            "{$path}.{$selector}",
                            'Git selectors cannot be used with a path dependency.',
                        );
                    }
                }
                $sourcePath = $this->requireString($declaration, 'path', $path);
                $this->assertDependencyPath($sourcePath, "{$path}.path");
                $source = new PathDependencySource(str_replace('\\', '/', $sourcePath));
            } else {
                if (array_key_exists('path', $declaration)) {
                    throw $this->error(
                        'B0330',
                        'Dependency Source Modes Conflict',
                        "{$path}.path",
                        'A Git source uses `url`, not `path`.',
                    );
                }
                if (!str_contains($package, '/')) {
                    throw $this->error(
                        'B0330',
                        'Dependency Declaration Is Invalid',
                        $path,
                        'Git dependencies require a scoped `vendor/package` identity.',
                    );
                }
                if (!array_key_exists('url', $declaration)) {
                    throw $this->error(
                        'B0332',
                        'Git Source URL Is Missing',
                        "{$path}.url",
                        'A dependency with `source = "git"` requires `url = "..."`.',
                    );
                }
                $url = $this->requireString($declaration, 'url', $path);
                try {
                    $url = GitUrl::canonicalize($url);
                } catch (UnexpectedValueException $error) {
                    $title = str_contains($error->getMessage(), 'credentials')
                        ? 'Git Source Contains Credentials'
                        : 'Git Source URL Is Invalid';
                    throw $this->error('B0332', $title, "{$path}.url", "Git URL `{$url}` is not permitted.");
                }
                $selectors = array_values(array_filter(
                    ['rev', 'tag', 'branch'],
                    static fn (string $selector): bool => array_key_exists($selector, $declaration),
                ));
                if (count($selectors) !== 1) {
                    throw $this->error(
                        'B0333',
                        $selectors === [] ? 'Git Selector Is Missing' : 'Git Selectors Conflict',
                        $path,
                        'Declare exactly one Git selector: `rev`, `tag`, or `branch`.',
                    );
                }
                $selectorKind = $selectors[0];
                $value = $this->requireString($declaration, $selectorKind, $path);
                try {
                    $selector = GitSelector::parse($selectorKind, $value);
                } catch (UnexpectedValueException) {
                    $title = match ($selectorKind) {
                        'rev' => 'Git Revision Is Invalid',
                        'tag' => 'Git Tag Is Invalid',
                        default => 'Git Branch Is Invalid',
                    };
                    throw $this->error(
                        'B0333',
                        $title,
                        "{$path}.{$selectorKind}",
                        "Git {$selectorKind} `{$value}` is invalid.",
                    );
                }
                $source = new GitDependencySource($url, $selector);
            }

            $dependencies[$package] = new DependencyDeclaration($package, $source, $version, $kind);
        }
        ksort($dependencies, SORT_STRING);

        return $dependencies;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, ProcessorDeclaration>
     */
    private function processors(array $values): array
    {
        if (!array_key_exists('processors', $values)) {
            return [];
        }
        $table = $this->requireTable($values, 'processors');
        $processors = [];
        foreach ($table as $package => $raw) {
            $path = "processors.{$package}";
            $this->assertDependencyPackageName($package, $path);
            if (!is_array($raw) || array_is_list($raw)) {
                throw $this->wrongType($path, 'an inline processor table', $raw);
            }
            $declaration = $this->stringKeyedTable($raw, $path);
            $this->rejectUnknown(
                $declaration,
                ['source', 'path', 'url', 'git', 'rev', 'tag', 'branch', 'version', 'binary', 'attributes'],
                $path,
            );
            $dependency = $this->dependencies(
                ['processors' => [$package => array_diff_key($declaration, ['binary' => true, 'attributes' => true])]],
                'processors',
                DependencyKind::Processor,
            )[$package];
            $binary = $this->requireString($declaration, 'binary', $path);
            if (!$this->isSlug($binary)) {
                throw $this->error(
                    'B0395',
                    'Processor Declaration Is Invalid',
                    "{$path}.binary",
                    "Processor binary `{$binary}` must be a declared filesystem-safe target name.",
                );
            }
            if (!array_key_exists('attributes', $declaration)) {
                throw $this->missing("{$path}.attributes");
            }
            $attributes = $this->stringList($declaration['attributes'], "{$path}.attributes");
            if ($attributes === []) {
                throw $this->error(
                    'B0396',
                    'Processor Attribute Filter Is Empty',
                    "{$path}.attributes",
                    'A processor must declare at least one exact canonical attribute name.',
                );
            }
            $seen = [];
            foreach ($attributes as $attribute) {
                if ($attribute === '' || str_contains($attribute, '*') || str_contains($attribute, '?')) {
                    throw $this->error(
                        'B0395',
                        'Processor Declaration Is Invalid',
                        "{$path}.attributes",
                        'Processor attributes must be exact canonical Doria names; wildcards are not accepted.',
                    );
                }
                if (isset($seen[$attribute])) {
                    throw $this->error(
                        'B0395',
                        'Processor Declaration Is Invalid',
                        "{$path}.attributes",
                        "Attribute `{$attribute}` is declared more than once.",
                    );
                }
                $seen[$attribute] = true;
            }
            sort($attributes, SORT_STRING);
            /** @var non-empty-list<string> $attributes */
            $processors[$package] = new ProcessorDeclaration($dependency, $binary, $attributes);
        }
        ksort($processors, SORT_STRING);

        return $processors;
    }

    private function assertDependencyPackageName(string $package, string $path): void
    {
        try {
            PackageIdentity::compilerIdentity($package);
        } catch (UnexpectedValueException) {
            throw $this->error(
                'B0330',
                'Dependency Declaration Is Invalid',
                $path,
                "Dependency key `{$package}` must be a lowercase package name.",
            );
        }
    }

    private function assertDependencyPath(string $value, string $path): void
    {
        $normalized = str_replace('\\', '/', $value);
        if ($normalized === ''
            || str_contains($normalized, "\0")
            || preg_match('/[\x01-\x1f\x7f]/', $normalized) === 1
            || str_starts_with($normalized, '/')
            || str_starts_with($normalized, '//')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $normalized) === 1
        ) {
            throw $this->error(
                'B0330',
                'Dependency Declaration Is Invalid',
                $path,
                "Path `{$value}` must be relative to the declaring package manifest.",
            );
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $allowed
     */
    private function rejectUnknown(array $values, array $allowed, string $parent): void
    {
        foreach (array_keys($values) as $field) {
            if (!in_array($field, $allowed, true)) {
                $path = $parent === '' ? $field : "{$parent}.{$field}";
                throw $this->error(
                    'B0304',
                    'Manifest Field Is Unknown',
                    $path,
                    "Field `{$path}` is not part of this manifest schema.",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function requireTable(array $values, string $key, string $parent = ''): array
    {
        $path = $parent === '' ? $key : "{$parent}.{$key}";
        if (!array_key_exists($key, $values)) {
            throw $this->missing($path);
        }
        $value = $values[$key];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->wrongType($path, 'a TOML table', $value);
        }

        return $this->stringKeyedTable($value, $path);
    }

    /** @param array<string, mixed> $values */
    private function requireString(array $values, string $key, string $parent = ''): string
    {
        $path = $parent === '' ? $key : "{$parent}.{$key}";
        if (!array_key_exists($key, $values)) {
            throw $this->missing($path);
        }
        $value = $values[$key];
        if (!is_string($value)) {
            throw $this->wrongType($path, 'a string', $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requireInt(array $values, string $key): int
    {
        if (!array_key_exists($key, $values)) {
            throw $this->missing($key);
        }
        $value = $values[$key];
        if (!is_int($value)) {
            throw $this->wrongType($key, 'an integer', $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requireBool(array $values, string $key, string $parent = ''): bool
    {
        $path = $parent === '' ? $key : "{$parent}.{$key}";
        if (!array_key_exists($key, $values)) {
            throw $this->missing($path);
        }
        $value = $values[$key];
        if (!is_bool($value)) {
            throw $this->wrongType($path, 'a TOML boolean', $value);
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($path, 'an array of strings', $value);
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->wrongType($path, 'an array of strings', $item);
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @return non-empty-list<string> */
    private function nonEmptyStringList(mixed $value, string $path): array
    {
        $strings = $this->stringList($value, $path);
        if ($strings === []) {
            throw $this->error(
                'B0312',
                'Autoload Mapping Is Invalid',
                $path,
                'The include pattern list must not be empty.',
            );
        }

        return $strings;
    }

    /**
     * TOML tables are string-keyed. Rebuild the array after runtime validation
     * so this invariant is explicit at the untyped parser boundary.
     *
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyedTable(array $values, string $path): array
    {
        $table = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw $this->wrongType($path, 'a TOML table with string keys', $values);
            }
            $table[$key] = $value;
        }

        return $table;
    }

    private function pattern(string $pattern, string $path): string
    {
        $normalized = str_replace('\\', '/', $pattern);
        if ($normalized === ''
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
            || preg_match('/[{}\[\]!$]/', $normalized) === 1
            || preg_match('/[@+?!*]\(/', $normalized) === 1
        ) {
            throw $this->error(
                'B0312',
                'Autoload Mapping Is Invalid',
                $path,
                "Pattern `{$pattern}` is outside Baton's deterministic `*`, `?`, and `**` language.",
            );
        }

        return $normalized;
    }

    private function workspacePattern(string $pattern, string $path): string
    {
        $normalized = $this->pattern($pattern, $path);
        if ($normalized === '.' || str_ends_with($normalized, '/')) {
            throw $this->error(
                'B0397',
                'Workspace Member Pattern Is Invalid',
                $path,
                "Pattern `{$pattern}` must select member directories relative to the workspace root.",
            );
        }

        return $normalized;
    }

    private function assertEntryPath(string $entry, string $path): void
    {
        $this->assertRelativePath($entry, $path, 'Entry Source Escapes Project');
        if (!str_ends_with(strtolower($entry), '.doria')) {
            throw $this->error(
                'B0310',
                'Binary Target Entry Is Invalid',
                $path,
                "Entry `{$entry}` must identify a `.doria` source file.",
            );
        }
    }

    private function assertRelativePath(
        string $value,
        string $path,
        string $heading,
        bool $allowDot = false,
    ): void {
        $normalized = str_replace('\\', '/', $value);
        if ($normalized === ''
            || (!$allowDot && $normalized === '.')
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || str_starts_with($normalized, '//')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)
        ) {
            throw $this->error(
                'B0313',
                $heading,
                $path,
                "Path `{$value}` must be a contained project-relative path.",
            );
        }
    }

    private function normalizeMappingPath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');

        return $normalized === '' || $normalized === '.' ? '.' : $normalized . '/';
    }

    private function targetName(string $name): string
    {
        if (!$this->isSlug($name)) {
            throw $this->error(
                'B0310',
                'Target Name Is Invalid',
                'targets',
                "Target name `{$name}` must be a filesystem-safe lowercase slug.",
            );
        }

        return $name;
    }

    private function isSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/D', $value) === 1;
    }

    private function isStrictSemver(string $version): bool
    {
        if (preg_match(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
                . '(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
                . '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?'
                . '(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D',
            $version,
        ) !== 1) {
            return false;
        }

        try {
            (new VersionParser())->normalize($version);
        } catch (UnexpectedValueException) {
            return false;
        }

        return true;
    }

    private function isNamespacePrefix(string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        if (str_starts_with($prefix, '\\') || !str_ends_with($prefix, '\\')) {
            return false;
        }
        $segments = explode('\\', substr($prefix, 0, -1));

        return array_filter(
            $segments,
            static fn (string $segment): bool => preg_match(
                '/^[A-Z][a-z0-9]*(?:[A-Z][a-z0-9]+)*$/D',
                $segment,
            ) !== 1,
        ) === [];
    }

    private function missing(string $path): BatonError
    {
        return $this->error(
            'B0302',
            'Required Manifest Field Is Missing',
            $path,
            "Missing required field `{$path}`.",
        );
    }

    private function wrongType(string $path, string $expected, mixed $value): BatonError
    {
        return $this->error(
            'B0302',
            'Manifest Field Has Wrong Type',
            $path,
            "Field `{$path}` must be {$expected}; found " . get_debug_type($value) . '.',
        );
    }

    private function error(string $code, string $heading, string $path, string $body): BatonError
    {
        $location = $this->locations?->describe($this->manifestPath, $path)
            ?? ($path === '' ? $this->manifestPath : "{$this->manifestPath}\nField: {$path}");

        return new BatonError(
            $code,
            $heading,
            "{$location}\n{$body}",
            ['Correct Baton.toml, then validate the project again:'],
            ['baton check'],
        );
    }
}
