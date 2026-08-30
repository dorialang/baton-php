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
            $failures[] = "{$path}: missing retained Slice 2 contract `{$needle}`";
        }
    }
};

$require('src/Manifest/ManifestLoader.php', [
    'private function dependencies(',
    'Dependency Source Must Be Declared',
    'Git Source Locator Spelling Has Changed',
    'source = "git"',
    'url = "..."',
]);
$require('src/Manifest/PackageVersionConstraint.php', ['Composer\\Semver\\Semver', 'Semver::satisfies(']);
$require('src/Dependency/DependencyResolver.php', ['Dependency Source Substitution Was Found', 'Dependency Cycle Was Found', 'resolveLocked(', 'resolveWorkspace(']);
$require('src/Dependency/LockFileStore.php', ["['schemaVersion']", 'unknown or missing fields', 'fflush(', 'fsync(', 'rename(']);
$require('src/Dependency/GitClient.php', ['GIT_TERMINAL_PROMPT', 'GIT_CONFIG_NOSYSTEM', 'GIT_LFS_SKIP_SMUDGE']);
$require('src/Dependency/DependencyCache.php', ["hash('sha256'", 'checkouts', 'locks']);
$require('src/Application.php', ['new InstallCommand()', 'new AddCommand()', 'new RemoveCommand()', 'new UpdateCommand()', 'new FetchCommand()']);
$require('docs/dependencies.md', ['source = "path"', 'source = "git"', 'url =', 'baton tree', 'baton why']);
$require('docs/lockfile.md', ['Standalone schema 1', 'Workspaces use schema 2']);

foreach (['src/Dependency/RegistryResolver.php', 'src/Dependency/ArchiveResolver.php'] as $forbidden) {
    if (is_file($root . '/' . $forbidden)) {
        $failures[] = "{$forbidden}: unapproved dependency transport exists";
    }
}
if (is_dir($root . '/vendor/doria-packages')) {
    $failures[] = 'vendor/doria-packages: project-local dependency store is forbidden';
}
if ($failures !== []) {
    fwrite(STDERR, "Stage 33 Slice 2 retention check failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "Stage 33 Slice 2 retention check passed\n");
