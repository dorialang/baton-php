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
            $failures[] = "{$path}: missing Slice-3 contract `{$needle}`";
        }
    }
};

$require('src/Testing/RuntimeOutcomeReader.php', [
    'MAX_RECORD_BYTES = 8_388_608',
    'DORIAO2',
    'DORIAO3',
    'DORIAO4',
    "'CollectionContains'",
    "'CollectionEmpty'",
    "'CollectionCount'",
    "'DictionaryHasKey'",
    "'DictionaryHasValue'",
    "'Throws'",
    'Runtime outcome has unknown magic.',
    'Runtime outcome contains trailing bytes.',
]);
$require('src/Testing/RuntimeOutcomeChannel.php', [
    "'outcomes'",
    "hash('sha256', \$identity)",
    'random_bytes(12)',
    "'DORIA_RUNTIME_OUTCOME_V2' => \$this->path",
    "'DORIA_RUNTIME_OUTCOME_V3' => \$this->path",
    "'DORIA_RUNTIME_OUTCOME_V4' => \$this->path",
    '$this->remove();',
]);
$require('src/Testing/TestOutcomeCategory.php', [
    "case Passed = 'Passed'",
    "case AssertionFailed = 'Assertion Failed'",
    "case UnexpectedCheckedError = 'Unexpected Checked Error'",
    "case FatalPanic = 'Fatal Panic'",
    "case AbnormalProcessFailure = 'Abnormal Process Failure'",
]);
$require('src/Testing/TestExecutionResult.php', [
    '$process->timedOut',
    '$process->outputLimitStream',
    '$process->signaled',
    '$outcomeError !== null',
    '$process->exitCode !== $outcome->processStatus',
]);
$require('src/Testing/TestPackageRunner.php', [
    'outcomeCategoryVocabularyVersion',
    'compilerRevisionExpected',
    'suitePathIdentities',
    'authoredOrdinal',
    'str_starts_with($leaf, \'__doria_test_\')',
    'RuntimeOutcomeChannel',
]);
$require('src/Commands/TestCommand.php', [
    'No Tests Match The Filter',
    'Test Summary',
    'Assertion Failed',
    'Unexpected Checked Error',
    'Fatal Panic',
    'Abnormal Process Failure',
]);
$require('tests/Integration/RealCompilerSchema2Test.php', [
    'toHaveCount',
    'toContain',
    'toThrow',
    'No Tests Match The Filter',
    "assertStringNotContainsString('__doria_test_'",
    'outcomeCategoryVocabularyVersion',
]);
foreach ([
    'README.md',
    'docs/testing.md',
    'docs/architecture.md',
    'docs/project-inventory.md',
    'docs/incremental-inventory.md',
    'docs/phase-f-package-and-dependency-model.md',
] as $path) {
    $require($path, ['Native Testing Foundation']);
}
$require('CHANGELOG.md', ['Native Testing Slice 3']);
$require('SECURITY.md', ['DORIAO2/DORIAO3/DORIAO4 records']);
$require('docs/testing.md', [
    'DORIAO2',
    'DORIAO3',
    'DORIAO4',
    'exactly one category',
    'No Tests Match The Filter',
    'Stage 34 single class inheritance is next',
]);

$runner = $read('src/Testing/TestPackageRunner.php');
foreach (['file_get_contents($test->source', 'preg_match($result->process->stderr', 'json_decode($result->process->stderr'] as $forbidden) {
    if (str_contains($runner, $forbidden)) {
        $failures[] = "src/Testing/TestPackageRunner.php: forbidden semantic or stderr parsing `{$forbidden}`";
    }
}
$inventoryDocs = preg_replace('/\s+/', ' ', $read('docs/incremental-inventory.md')) ?? '';
foreach (['Raw outcome records', 'user output', 'Error object data', 'source contents'] as $privacyFact) {
    if (!str_contains($inventoryDocs, $privacyFact)) {
        $failures[] = "docs/incremental-inventory.md: missing private-inventory exclusion `{$privacyFact}`";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Native testing Slice 3 check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Native testing Slice 3 Baton closure check passed\n");
