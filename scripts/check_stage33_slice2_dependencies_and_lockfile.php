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
            $failures[] = "{$path}: missing Stage 33 Slice 2 contract `{$needle}`";
        }
    }
};

$require('src/Manifest/ManifestLoader.php', [
    'private function dependencies(',
    "['path', 'git', 'rev', 'tag', 'branch', 'version']",
    'Git selectors cannot be used with a path dependency.',
    'Git dependencies require a scoped `vendor/package` identity.',
    'Development Dependencies Land In Stage 33 Slice 3',
]);
$require('src/Manifest/PackageVersionConstraint.php', [
    'use Composer\\Semver\\Semver;',
    'unsupported package version constraint',
    'Semver::satisfies(',
]);
$require('src/Dependency/DependencyResolver.php', [
    'Dependency Source Substitution Was Found',
    'Dependency Cycle Was Found',
    'Dependency Package Requires A Library Target',
    'Dependency Update Requires A Broader Update',
    'resolveLocked(',
]);
$require('src/Dependency/LockFileStore.php', [
    "(\$document['schemaVersion'] ?? null) !== 1",
    'unknown or missing fields',
    'Package entries must be ordered by compiler package identity.',
]);
$require('src/Dependency/LockFileStore.php', ['fflush(', 'fsync(', 'rename(']);
$require('src/Dependency/GitClient.php', [
    'GIT_TERMINAL_PROMPT',
    'GIT_CONFIG_NOSYSTEM',
    'GIT_LFS_SKIP_SMUDGE',
    'Offline Dependency Content Is Missing',
]);
$require('src/Dependency/DependencyCache.php', ['hash(\'sha256\'', 'checkouts', 'locks']);
$require('src/Dependency/CacheRootLocator.php', ['XDG_CACHE_HOME', 'LOCALAPPDATA', 'Library']);
$require('src/Application.php', [
    'new InstallCommand()',
    'new AddCommand()',
    'new RemoveCommand()',
    'new UpdateCommand()',
    'new FetchCommand()',
    "new StageGatedCommand('tree'",
    "new StageGatedCommand('why'",
]);
$require('src/Build/BuildPlanBuilder.php', [
    '$graph?->sortedPackages()',
    "'dependencies' => \$dependencies",
]);
$require('src/Build/BuildReceiptWriter.php', [
    "'lock' =>",
    "'pathDependencies' =>",
    'PathContentFingerprint',
]);
$require('docs/baton-php-development-plan.md', [
    '### Stage 33 Slice 1 - Complete',
    '### Stage 33 Slice 2 - Complete',
    '### Stage 33 Slice 3 - Next',
    'Stage 33 is in progress, not complete',
    'Pre-Stage-45 Doria-native',
]);
$require('docs/dependencies.md', ['path dependencies', 'Git package identities', '`tree`, `why`']);
$require('docs/lockfile.md', ['Schema 1', 'never update it']);
$require('docs/dependency-cache.md', ['content-addressed cache', '`vendor/` directory']);
$require('docs/offline.md', ['prohibit every network-capable Git operation']);
$require('tests/Unit/DependencyResolverTest.php', [
    'testDependencyCycleReportsTheCompleteCycleAndSourceKinds',
    'testSourceSubstitutionAndEveryContributingChainAreReported',
]);
$require('tests/Unit/GitDependencyResolverTest.php', [
    'testLockedInstallUsesExactCommitAfterBranchMoves',
    'testSelectedUpdateMovesOnlySelectedGitPackages',
]);
$require('tests/Integration/DependencyCommandTest.php', [
    'testPathDependencyLifecycleFeedsTheCompilerPlanAndReceipt',
    'testFailedAddLeavesManifestAndLockBytesUntouched',
]);

foreach ([
    'src/Dependency/RegistryResolver.php',
    'src/Dependency/ArchiveResolver.php',
    'src/Dependency/WorkspaceResolver.php',
    'src/Dependency/ProcessorExecutor.php',
    'src/Dependency/TestRunner.php',
] as $forbidden) {
    if (is_file($root . '/' . $forbidden)) {
        $failures[] = "{$forbidden}: Stage 33 Slice 3 or later capability landed early";
    }
}

if (is_dir($root . '/vendor/doria-packages')) {
    $failures[] = 'vendor/doria-packages: project-local dependency store is forbidden';
}

if ($failures !== []) {
    fwrite(STDERR, "Stage 33 Slice 2 dependency and lockfile check failed:\n- "
        . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Stage 33 Slice 2 dependency and lockfile check passed\n");
