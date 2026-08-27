<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $path) use ($root, &$failures): string {
    $contents = @file_get_contents($root . '/' . $path);
    if (!is_string($contents)) {
        $failures[] = "{$path}: required file is missing or unreadable";
        return '';
    }

    return $contents;
};

$require = static function (string $path, string $contents, array $needles) use (&$failures): void {
    foreach ($needles as $needle) {
        if (!str_contains($contents, $needle)) {
            $failures[] = "{$path}: missing Stage 33 Slice 1 contract `{$needle}`";
        }
    }
};

$loaderPath = 'src/Manifest/ManifestLoader.php';
$selectorPath = 'src/Manifest/TargetSelector.php';
$targetOptionsPath = 'src/Commands/TargetOptions.php';
$discoveryPath = 'src/Source/SourceDiscovery.php';
$scannerPath = 'src/Source/DirectoryScanner.php';
$generatedPath = 'src/Source/GeneratedSourceInput.php';
$planPath = 'src/Build/BuildPlanBuilder.php';
$layoutPath = 'src/Build/BuildLayout.php';
$servicePath = 'src/Build/Schema2BuildService.php';
$checkPath = 'src/Commands/CheckCommand.php';
$buildPath = 'src/Commands/BuildCommand.php';
$templatePath = 'templates/project/Baton.toml';
$testsPath = 'tests/Integration/Schema2CommandTest.php';
$manifestTestsPath = 'tests/Unit/ManifestLoaderTest.php';
$discoveryTestsPath = 'tests/Unit/SourceDiscoveryTest.php';
$composerPath = 'composer.json';
$developmentPath = 'docs/baton-php-development-plan.md';

$loader = $read($loaderPath);
$selector = $read($selectorPath);
$targetOptions = $read($targetOptionsPath);
$discovery = $read($discoveryPath);
$scanner = $read($scannerPath);
$generated = $read($generatedPath);
$plan = $read($planPath);
$layout = $read($layoutPath);
$service = $read($servicePath);
$check = $read($checkPath);
$build = $read($buildPath);
$template = $read($templatePath);
$tests = $read($testsPath);
$manifestTests = $read($manifestTestsPath);
$discoveryTests = $read($discoveryTestsPath);
$composer = $read($composerPath);
$development = $read($developmentPath);

$require($loaderPath, $loader, [
    'use PhpCollective\\Toml\\Toml;',
    'Toml::tryParse(',
    'TomlVersion::V10',
    'private const SCHEMA_1_TOP_LEVEL',
    'private const SCHEMA_2_TOP_LEVEL',
    'rejectUnknown(',
    'Local Package Must Be Non-Publishable',
    "\$compilerIdentity = 'local/' . \$name;",
    'Synthetic Local Vendor Is Reserved',
    'targets.library',
    'targets.binary',
    "autoloadMappings(\$values, 'autoload', 'main')",
    "autoloadMappings(\$values, 'autoload-dev', 'development')",
    'Dependencies Are Not Available In This Slice',
    'Processors Are Not Available In This Slice',
    'Workspaces Are Not Available In This Slice',
]);
$require($selectorPath, $selector, [
    '`--binary <name>` and `--library` are mutually exclusive.',
    'Target Selection Is Ambiguous',
    'Library Target Cannot Be Run',
]);
$require($targetOptionsPath, $targetOptions, ["'binary'", "'library'"]);
if (str_contains($targetOptions, "'target'")) {
    $failures[] = "{$targetOptionsPath}: generic --target option was added";
}
$require($discoveryPath, $discovery, [
    'without parsing Doria',
    "'main'",
    "'development'",
    "'generated'",
    'usort(',
    'portable',
]);
$require($scannerPath, $scanner, ['realpath(', 'visited', 'is_link(', 'Symlink']);
$require($generatedPath, $generated, ['generatedFor', 'contentHash', 'contents', 'existingPath']);
$require($planPath, $plan, [
    "'schemaVersion' => 1",
    "\$activeScopes = ['main']",
    "'dependencies' => []",
    "'generatedFor'",
]);
$require($layoutPath, $layout, [
    "'build-plan.json'",
    "'build.json'",
    'Managed Build Path Escapes Project',
]);
$require($servicePath, $service, [
    "['check', '--build-plan'",
    "['compile', '--build-plan'",
    '!$context->selected->isBinary()',
    'removePrevious(',
]);
$require($checkPath, $check, ["['check', \$manifest->entry]", "['check', '--build-plan'"]);
$require($buildPath, $build, ["['compile', \$manifest->entry, '--target', 'native']", 'new Schema2BuildService()']);
$require($templatePath, $template, [
    'manifest-version = 2',
    'edition = "2026"',
    'publishable = false',
    '[autoload.namespaces]',
]);
$require($manifestTestsPath, $manifestTests, [
    'schemaOneUnsupportedFields',
    'Manifest Field Is Unknown',
    'Local Package Must Be Non-Publishable',
    'Synthetic Local Vendor Is Reserved',
]);
$require($discoveryTestsPath, $discoveryTests, [
    'testDiscoveryIsDeterministicScopedAndTargetSpecific',
    'testSymlinkEscapeIsRejected',
    'testGeneratedBoundaryValidatesHashAndScopeWithoutWritingFiles',
]);
$require($testsPath, $tests, [
    "['check', '--build-plan'",
    "['build', '--binary'",
    "['build', '--library'",
    'assertFileDoesNotExist',
    'artifact',
]);
$require($composerPath, $composer, [
    'php-collective/toml',
    'composer/semver',
    'check:stage33-slice1',
]);
$require($developmentPath, $development, [
    '### Stage 33 Slice 1 - Complete',
    '### Stage 33 Slice 2 - Next',
    'Stage 33 is in progress, not complete',
    'Pre-Stage-45 Doria-native',
]);

foreach (['DependencyResolver', 'WorkspaceDiscovery', 'ProcessorExecutor', 'TestDiscovery'] as $forbiddenClass) {
    $matches = glob($root . '/src/**/' . $forbiddenClass . '.php');
    if (is_array($matches) && $matches !== []) {
        $failures[] = "src: Stage 33 Slice 1 added forbidden {$forbiddenClass}";
    }
}
if (is_file($root . '/Baton.lock')) {
    $failures[] = 'Baton.lock: Slice 1 generated a repository lockfile';
}

if ($failures !== []) {
    fwrite(STDERR, "Stage 33 Slice 1 manifest and inventory check failed:\n- "
        . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Stage 33 Slice 1 manifest and inventory check passed\n");
