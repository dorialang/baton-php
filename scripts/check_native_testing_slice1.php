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
            $failures[] = "{$path}: missing native-testing contract `{$needle}`";
        }
    }
};

$require('src/Compiler/MetadataReader.php', [
    'function schema3(',
    "'testSuites', 'tests'",
    'duplicate test identity',
    'invalid callable reference',
    'invalid suite reference',
]);
$require('src/Testing/TestPackageRunner.php', [
    "['metadata', '--schema-version', '3'",
    '$test->identity',
    '$test->displayName',
    '$test->callableCanonicalName',
    '$test->requiredEffects',
]);
$require('src/Testing/TestDiscovery.php', [
    'MetadataDocumentV3',
    '$metadata->tests',
    "str_contains(\$test->displayName, \$filter)",
]);
$require('src/Testing/ExecutableTest.php', [
    'displayName',
    'callableIdentity',
    'callableCanonicalName',
    'pathSegments',
    'ambientEffects',
]);
$require('src/Processor/ProcessorPipeline.php', ["['metadata', '--schema-version', '2'"]);
$require('docs/testing.md', [
    '--schema-version 3',
    'behavioral `describe`/`it`/`test`',
    'stable compiler test',
    'never parses Doria source',
]);

$discovery = $read('src/Testing/TestDiscovery.php');
foreach (['file_get_contents(', 'preg_match(', 'preg_match_all(', "'describe'", '"describe"', "'it'", '"it"'] as $forbidden) {
    if (str_contains($discovery, $forbidden)) {
        $failures[] = "src/Testing/TestDiscovery.php: source-level test parsing marker is forbidden `{$forbidden}`";
    }
}
$runner = $read('src/Testing/TestPackageRunner.php');
if (str_contains($runner, "['metadata', '--schema-version', '2'")) {
    $failures[] = 'src/Testing/TestPackageRunner.php: test discovery regressed to metadata schema 2';
}
if (str_contains($runner, 'compiler-known:Test')) {
    $failures[] = 'src/Testing/TestPackageRunner.php: runner must consume unified compiler tests rather than attribute applications';
}

if ($failures !== []) {
    fwrite(STDERR, "Native testing Slice 1 check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Native testing Slice 1 check passed\n");
