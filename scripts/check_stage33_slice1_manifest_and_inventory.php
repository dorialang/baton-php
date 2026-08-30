<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$require = static function (string $path, array $needles) use ($root, &$failures): void {
    $contents = @file_get_contents($root . '/' . $path);
    if (!is_string($contents)) {
        $failures[] = "{$path}: required file is missing or unreadable";
        return;
    }
    foreach ($needles as $needle) {
        if (!str_contains($contents, $needle)) {
            $failures[] = "{$path}: missing retained Slice 1 contract `{$needle}`";
        }
    }
};

$require('src/Manifest/ManifestLoader.php', [
    'Toml::tryParse(',
    'private const SCHEMA_1_TOP_LEVEL',
    'private const SCHEMA_2_TOP_LEVEL',
    'Local Package Must Be Non-Publishable',
    'targets.library',
    'targets.binary',
    "autoloadMappings(\$values, 'autoload', 'main')",
    "autoloadMappings(\$values, 'autoload-dev', 'development')",
]);
$require('src/Source/SourceDiscovery.php', ['without parsing Doria', "'main'", "'development'", "'generated'", 'usort(']);
$require('src/Source/GeneratedSourceInput.php', ['generatedFor', 'contentHash', 'producer', 'owner']);
$require('src/Build/BuildPlanBuilder.php', ["'schemaVersion' => 1", "'dependencies' => \$dependencies", "'generatedFor'"]);
$require('src/Build/BuildLayout.php', ["'build-plan.json'", "'build.json'", 'Managed Build Path Escapes Project']);
$require('templates/project/Baton.toml', ['manifest-version = 2', 'edition = "2026"', 'publishable = false', '[autoload.namespaces]']);
$require('docs/baton-php-development-plan.md', [
    '### Stage 33 Slice 1 - Complete',
    '### Stage 33 Slice 2 - Complete',
    '### Stage 33 Slice 3 - Complete',
    'Pre-Stage-45 Doria-native',
]);

if (is_file($root . '/Baton.lock')) {
    $failures[] = 'Baton.lock: this repository must not carry a project lockfile';
}
if ($failures !== []) {
    fwrite(STDERR, "Stage 33 Slice 1 retention check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 33 Slice 1 retention check passed\n");
