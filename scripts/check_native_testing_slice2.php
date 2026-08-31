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
            $failures[] = "{$path}: missing retained Slice-2 contract `{$needle}`";
        }
    }
};

$require('src/Testing/TestPackageRunner.php', [
    "['metadata', '--schema-version', '3'",
    'foreach ($tests as $test)',
    '$test->requiredEffects',
    '$this->renderOutput($output, $result)',
]);
$require('tests/Integration/RealCompilerSchema2Test.php', [
    'expect(42)->toEqual(42)',
    'expect(41)->toEqual(42)',
    'assertHelper()',
    'fail("explicit assertion failure")',
    "['test', '--release'",
    "['test', '--workspace'",
    'packageAnswer())->toEqual(dependencyAnswer())',
    'continues later tests',
]);
$require('docs/testing.md', [
    'never parses Doria source',
    'matcher semantics',
    'assertion effects remain compiler-owned',
    'Metadata schema 3 remains unchanged',
]);

$discovery = $read('src/Testing/TestDiscovery.php');
foreach (['file_get_contents(', 'preg_match(', 'preg_match_all('] as $forbidden) {
    if (str_contains($discovery, $forbidden)) {
        $failures[] = "src/Testing/TestDiscovery.php: source parsing remains forbidden `{$forbidden}`";
    }
}
$runner = $read('src/Testing/TestPackageRunner.php');
foreach (['json_decode($result->process->stderr', 'preg_match($result->process->stderr'] as $forbidden) {
    if (str_contains($runner, $forbidden)) {
        $failures[] = "src/Testing/TestPackageRunner.php: human stderr classification remains forbidden `{$forbidden}`";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Native testing Slice 2 retention check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Native testing Slice 2 retention check passed\n");
