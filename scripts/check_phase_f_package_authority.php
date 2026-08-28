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
            $failures[] = "{$path}: missing Phase F contract `{$needle}`";
        }
    }
};

$modelPath = 'docs/phase-f-package-and-dependency-model.md';
$manifestPath = 'docs/project-manifest.md';
$architecturePath = 'docs/architecture.md';
$developmentPath = 'docs/baton-php-development-plan.md';
$securityPath = 'SECURITY.md';
$readmePath = 'README.md';
$model = $read($modelPath);
$manifest = $read($manifestPath);
$architecture = $read($architecturePath);
$development = $read($developmentPath);
$security = $read($securityPath);
$readme = $read($readmePath);

$require($modelPath, $model, [
    'The bootstrap continues to read historical manifest schema 1 exactly',
    'Schema 1 means one binary, one explicit entry file',
    'Stage 33 Slice 1 accepts strict schema 2',
    'manifest-version = 2',
    '[autoload.namespaces]',
    '[autoload-dev.namespaces]',
    'Doria programs do not load source files while running',
    'Every active main, development, generated',
    'Namespace directory segments match exactly',
    'one primary externally accessible type',
    'versioned JSON build plan',
    '`doriac` owns Doria parsing',
    'Baton does not parse Doria declarations',
    'Transitive dependencies are not',
    'one version per package identity',
    'normal dependency cycle',
    'first source transports are path and Git',
    'always records the exact commit',
    'Packages use SemVer',
    '`Baton.lock` is deterministic, machine-generated JSON',
    'commit the project-root lockfile',
    'build-plan facts belong in a versioned',
    'one shared dependency cache',
    'Processors are explicit',
    'No package-defined arbitrary build scripts',
    'global content-addressed cache',
    'Offline install/check/build/run never reaches the network',
    'Stage 33 Slice 1 is complete',
    'Stage 33 Slice 2 is',
    'complete: normal dependency resolution',
    'Slice 3 is next',
    'Stage 33 remains in progress, not complete',
]);

$require($manifestPath, $manifest, [
    '## Schema 1',
    '## Schema 2',
    'Schema 1 has one explicit binary entry',
    'targets, dependency tables, lockfile semantics, workspace, or processors',
]);
$require($architecturePath, $architecture, [
    'Baton discovers source through compile-time `autoload`',
    '`Baton.toml`, resolves package versions',
]);
$require($developmentPath, $development, [
    '### Stage 33 Slice 1 - Complete',
    '### Stage 33 Slice 2 - Complete',
    '### Stage 33 Slice 3 - Next',
    'Stage 33 is in progress, not complete',
]);
$require($securityPath, $security, [
    '## Accepted package-system threat model',
    'Offline mode never reaches the network',
]);
$require($readmePath, $readme, [
    'Stage 33 Slices 1 and 2 implement strict manifest schema 2',
    'Schema 1 remains exactly compatible',
    'path and Git dependency resolution',
]);

if (is_file($root . '/Baton.lock')) {
    $failures[] = 'Baton.lock: Slice 1 generated a repository lockfile';
}

foreach (['Andrew', 'Lucy', 'Masiye'] as $privateName) {
    if (str_contains($model, $privateName)) {
        $failures[] = "{$modelPath}: contains private or family name `{$privateName}`";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Phase F package authority check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Phase F package authority check passed\n");
