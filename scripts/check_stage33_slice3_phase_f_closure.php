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
$require = static function (string $path, array $needles) use ($read, &$failures): void {
    $contents = $read($path);
    foreach ($needles as $needle) {
        if (!str_contains($contents, $needle)) {
            $failures[] = "{$path}: missing Slice 3 executable contract `{$needle}`";
        }
    }
};

$require('src/Application.php', ['new TestCommand()', 'new TreeCommand()', 'new WhyCommand()', 'new ProjectCommand()']);
$require('src/Manifest/ManifestLoader.php', ['dev-dependencies', 'processors', 'workspace', 'Dependency Source Is Unsupported', 'Git Source Locator Spelling Has Changed']);
$require('src/Workspace/WorkspaceDiscovery.php', [
    'Nested Workspace Is Not Supported In The Initial Model',
    'Workspace Member Is Ambiguous',
    'Composable nested workspace roots require a later decision',
    'realpath(',
]);
$require('src/Dependency/WorkspaceLockFileStore.php', ["schemaVersion'] ?? null) !== 2", 'AtomicFileWriter']);
$require('src/Build/AtomicFileWriter.php', ['file_put_contents(', 'LOCK_EX', 'rename(', '.tmp']);
$require('src/Dependency/LockFileStore.php', ["schemaVersion'] ?? null) !== 1"]);
$require('src/Testing/TestPackageRunner.php', ["['metadata', '--schema-version', '3'", 'BoundedProcessRunner', 'dispatcher']);
$require('src/Testing/TestDiscovery.php', ['MetadataDocumentV3', '$metadata->tests', '$test->displayName']);
$require('src/Processor/ProcessorPipeline.php', [
    'private const PROTOCOL = 1',
    "['metadata', '--schema-version', '2'",
    'TIMEOUT_SECONDS = 300.0',
    'STDOUT_LIMIT = 67_108_864',
    'GeneratedSourceRegistry',
    'A successful empty processor result replaces output from an older request.',
]);
$require('src/Processor/GeneratedSourceRegistry.php', ['requireValid(', 'Project Generated Sources Are Stale', 'hash_file(']);
$require('src/Commands/TreeCommand.php', ['LockedGraphLoader', 'development', '(repeated)']);
$require('src/Commands/WhyCommand.php', ['find(', 'Dependency Is Unknown', 'Dependency Is Not Active']);
$require('src/Commands/ProjectCommand.php', ['ProjectDocumentBuilder', '`baton project` currently requires `--json`.']);
$require('src/Project/ProjectDocumentBuilder.php', ['GeneratedSourceRegistry', 'ActivePackageResolver', 'toolingBuildPlan', 'fingerprints']);
$require('src/Inventory/ManagedInventoryStore.php', ['inventory.json', 'compilerRevision', 'buildPlanSha256', 'recordProcessors', 'recordTests']);
$require('docs/processors.md', ['does not provide a processor sandbox', 'never trigger a recursive processor pass', 'Offline mode never builds or executes']);
$require('docs/baton-php-development-plan.md', [
    '### Stage 33 Slice 3 - Complete',
    'Stage 33 and Phase F are complete',
    '### Native Testing Foundation Slice 1 - Complete',
    '### Native Testing Foundation Slice 2 - Complete',
    '### Native Testing Foundation Slice 3 - Complete',
    'Foundation is complete and Stage 34',
    'Stage 34 single class inheritance',
    'is next',
]);
$require('README.md', ['Stage 33 is complete', 'mandatory Pre-Stage-45']);

$application = $read('src/Application.php');
foreach (['test', 'tree', 'why', 'project'] as $command) {
    if (str_contains($application, "new StageGatedCommand('{$command}'")) {
        $failures[] = "src/Application.php: real command `{$command}` remains stage-gated";
    }
}
foreach (['src/Dependency/RegistryResolver.php', 'src/Dependency/ArchiveResolver.php'] as $forbidden) {
    if (is_file($root . '/' . $forbidden)) {
        $failures[] = "{$forbidden}: registry/archive sources are outside Stage 33";
    }
}
$processor = $read('src/Processor/ProcessorPipeline.php');
if (str_contains(strtolower($processor), 'recursive processor')) {
    $failures[] = 'src/Processor/ProcessorPipeline.php: recursive processor execution was introduced';
}

if ($failures !== []) {
    fwrite(STDERR, "Stage 33 Slice 3 closure check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 33 Slice 3 closure check passed\n");
