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
    $contents = preg_replace('/\s+/', ' ', $read($path)) ?? '';
    foreach ($needles as $needle) {
        $needle = preg_replace('/\s+/', ' ', $needle) ?? $needle;
        if (!str_contains($contents, $needle)) {
            $failures[] = "{$path}: missing Slice-2 boundary `{$needle}`";
        }
    }
};
$requireAny = static function (string $path, array $needles) use ($read, &$failures): void {
    $contents = preg_replace('/\s+/', ' ', $read($path)) ?? '';
    foreach ($needles as $needle) {
        if (str_contains($contents, $needle)) {
            return;
        }
    }
    $failures[] = "{$path}: missing Slice-2 status (`" . implode('`, `', $needles) . '`)';
};

$require('src/Testing/TestPackageRunner.php', [
    "['metadata', '--schema-version', '3'",
    'foreach ($tests as $test)',
    '$result[\'exitCode\'] === 0',
    'FAIL {$manifest->package->name} {$test->displayName}',
    '$this->renderOutput($output, $test, $result)',
    '$test->requiredEffects',
]);
$require('tests/Integration/RealCompilerSchema2Test.php', [
    'expect(42)->toEqual(42)',
    'expect(41)->toEqual(42)',
    'assertHelper()',
    'fail("explicit assertion failure")',
    'Error[R1001]: Assertion Failed',
    'throws AssertionError',
    "['test', '--release'",
    "['test', '--workspace'",
    'packageAnswer())->toEqual(dependencyAnswer())',
    'continues later tests',
]);
foreach ([
    'README.md',
    'CHANGELOG.md',
    'docs/testing.md',
    'docs/architecture.md',
    'docs/project-inventory.md',
    'docs/incremental-inventory.md',
    'docs/phase-f-package-and-dependency-model.md',
] as $path) {
    $requireAny($path, ['Slice 2', 'Slice-2', 'Slices 1 and 2']);
    $require($path, ['Slice 3']);
}
$require('docs/testing.md', [
    'does not parse Doria source',
    'does not parse Doria source or assertion stderr',
    'generic `FAIL`',
    'metadata schema 3 remains unchanged',
    'without `throws AssertionError`',
]);

$runner = $read('src/Testing/TestPackageRunner.php');
foreach (['DORIAO4', 'Assertion Failed', 'runtimeAssertion', 'json_decode($result[\'stderr\']'] as $forbidden) {
    if (str_contains($runner, $forbidden)) {
        $failures[] = "src/Testing/TestPackageRunner.php: Slice 3 assertion classification leaked into Slice 2 `{$forbidden}`";
    }
}
$discovery = $read('src/Testing/TestDiscovery.php');
foreach (['file_get_contents(', 'preg_match(', 'preg_match_all('] as $forbidden) {
    if (str_contains($discovery, $forbidden)) {
        $failures[] = "src/Testing/TestDiscovery.php: source parsing remains forbidden `{$forbidden}`";
    }
}
$docs = $read('docs/testing.md');
foreach (['toThrow', 'toHaveCount', 'toHaveKey', 'toHaveValue'] as $premature) {
    if (str_contains($docs, "`{$premature}` is implemented")) {
        $failures[] = "docs/testing.md: Slice 3 matcher is claimed early `{$premature}`";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Native testing Slice 2 check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Native testing Slice 2 Baton boundary check passed\n");
