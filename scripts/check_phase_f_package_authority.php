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
            $failures[] = "{$path}: missing Phase F authority `{$needle}`";
        }
    }
};

$require('docs/phase-f-package-and-dependency-model.md', [
    'Schema 1 means one binary',
    'Doria programs do not load source files while running',
    'Baton does not parse Doria declarations',
    'one version per package identity',
    'source transports are path and Git',
    'Baton.lock',
    'global content-addressed cache',
    'Stage 33 Slices 1, 2, and 3 are complete',
    'Stage 34 single class inheritance is next',
    'Pre-Stage-45',
]);
$require('README.md', ['Stage 33 is complete', 'schema-1', 'workspaces', 'processors', 'project --json']);
$require('SECURITY.md', ['no processor sandbox', 'Offline mode', 'argument vectors']);
$require('docs/project-manifest.md', ['source = "path"', 'source = "git"', '[workspace]']);
$require('docs/architecture.md', ['PHP implementation is intentionally lean and disposable', 'mandatory Pre-Stage-45']);

if (is_file($root . '/Baton.lock')) {
    $failures[] = 'Baton.lock: repository source must not contain a project lock';
}
if ($failures !== []) {
    fwrite(STDERR, "Phase F package authority check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Phase F package authority check passed\n");
