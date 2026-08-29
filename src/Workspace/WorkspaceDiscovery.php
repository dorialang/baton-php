<?php

declare(strict_types=1);

namespace Doria\Baton\Workspace;

use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;
use Doria\Baton\Source\PatternMatcher;

final class WorkspaceDiscovery
{
    public function discover(
        string $workspaceRoot,
        Schema2Manifest|WorkspaceManifest $rootManifest,
    ): WorkspaceContext {
        $root = realpath($workspaceRoot);
        if ($root === false) {
            throw $this->error('Workspace Member Escapes Root', 'The workspace root cannot be canonicalized.');
        }
        $definition = $rootManifest instanceof Schema2Manifest
            ? $rootManifest->workspace
            : $rootManifest->workspace;
        if ($definition === null) {
            throw new \LogicException('Workspace discovery requires a workspace declaration.');
        }

        $members = [];
        $canonicalRoots = [];
        $compilerIdentities = [];
        if ($rootManifest instanceof Schema2Manifest) {
            $this->register(
                new WorkspaceMember(
                    $root,
                    '.',
                    $root . DIRECTORY_SEPARATOR . 'Baton.toml',
                    $rootManifest,
                ),
                $members,
                $canonicalRoots,
                $compilerIdentities,
            );
        }

        $matches = $this->matchedDirectories($root, $definition->members);
        foreach ($matches as $relative => $patternIndexes) {
            if (count($patternIndexes) !== 1) {
                throw $this->error(
                    'Workspace Member Is Ambiguous',
                    "Workspace member `{$relative}` is selected by more than one member pattern.",
                    'B0399',
                );
            }
            $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $canonical = realpath($candidate);
            if ($canonical === false || !$this->contained($root, $canonical)) {
                throw $this->error(
                    'Workspace Member Escapes Root',
                    "Workspace member `{$relative}` must remain inside the workspace root after canonicalization.",
                    'B0400',
                );
            }
            $manifestPath = $canonical . DIRECTORY_SEPARATOR . 'Baton.toml';
            if (!is_file($manifestPath)) {
                throw $this->error(
                    'Workspace Member Is Missing',
                    "Selected directory `{$relative}` does not contain Baton.toml.",
                    'B0401',
                );
            }
            if (is_file($canonical . DIRECTORY_SEPARATOR . LockFileStore::FILE)) {
                throw $this->error(
                    'Member Lock Conflicts With Workspace Lock',
                    "Workspace member `{$relative}` contains Baton.lock; one lock belongs at the workspace root.",
                    'B0402',
                );
            }
            $manifest = (new ManifestLoader())->load($canonical);
            if ($manifest instanceof Manifest) {
                throw $this->error(
                    'Workspace Member Requires Schema 2',
                    "Workspace member `{$relative}` uses manifest schema 1.",
                    'B0403',
                );
            }
            if ($manifest instanceof WorkspaceManifest || $manifest->workspace !== null) {
                throw $this->error(
                    'Nested Workspace Is Not Supported In The Initial Model',
                    "Workspace member `{$relative}` declares `[workspace]`. Packages may be nested at any directory depth. "
                        . 'Composable nested workspace roots require a later decision defining lock authority, member visibility, command selection, and graph composition.',
                    'B0404',
                );
            }
            $this->register(
                new WorkspaceMember($canonical, $relative, $manifestPath, $manifest),
                $members,
                $canonicalRoots,
                $compilerIdentities,
            );
        }

        if ($members === []) {
            throw $this->error('Workspace Members Are Missing', 'A virtual workspace must contain at least one member.', 'B0391');
        }
        ksort($members, SORT_STRING);

        return new WorkspaceContext($root, $rootManifest, $members);
    }

    /**
     * @param list<string> $patterns
     * @return array<string, list<int>>
     */
    private function matchedDirectories(string $root, array $patterns): array
    {
        $matcher = new PatternMatcher();
        $matches = [];
        /** @var list<array{string, string, list<string>}> $stack */
        $stack = [[$root, '', []]];
        $visited = [];
        while ($stack !== []) {
            $current = array_pop($stack);
            $directory = $current[0];
            $relative = $current[1];
            $ancestors = $current[2];
            $canonical = realpath($directory);
            $matchingPatterns = [];
            if ($relative !== '') {
                foreach ($patterns as $index => $pattern) {
                    if ($matcher->matches($pattern, $relative)) {
                        $matchingPatterns[] = $index;
                    }
                }
            }
            if ($canonical === false || !$this->contained($root, $canonical)) {
                if ($matchingPatterns !== []) {
                    throw $this->error(
                        'Workspace Member Escapes Root',
                        "Workspace member `{$relative}` must remain inside the workspace root after canonicalization.",
                        'B0400',
                    );
                }
                continue;
            }
            if (in_array($canonical, $ancestors, true)) {
                throw $this->error(
                    'Workspace Member Pattern Is Invalid',
                    "Workspace traversal encountered a symbolic-link cycle at `{$relative}`.",
                    'B0399',
                );
            }
            if ($matchingPatterns !== []) {
                $matches[$relative] = $matchingPatterns;
            }
            if (isset($visited[$canonical])) {
                continue;
            }
            $visited[$canonical] = true;
            $entries = @scandir($directory);
            if ($entries === false) {
                continue;
            }
            rsort($entries, SORT_STRING);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, ['.git', 'build', 'vendor'], true)) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($path)) {
                    continue;
                }
                $child = $relative === '' ? $entry : "{$relative}/{$entry}";
                $stack[] = [$path, $child, [...$ancestors, $canonical]];
            }
        }
        ksort($matches, SORT_STRING);

        return $matches;
    }

    /**
     * @param array<string, WorkspaceMember> $members
     * @param array<string, string>          $canonicalRoots
     * @param array<string, string>          $compilerIdentities
     */
    private function register(
        WorkspaceMember $member,
        array &$members,
        array &$canonicalRoots,
        array &$compilerIdentities,
    ): void {
        $package = $member->manifest->package->name;
        $compiler = $member->manifest->package->compilerIdentity;
        if (isset($members[$package])) {
            throw $this->error(
                'Workspace Member Package Is Duplicated',
                "Package identity `{$package}` is used by `{$members[$package]->relativePath}` and `{$member->relativePath}`.",
                'B0405',
            );
        }
        if (isset($compilerIdentities[$compiler])) {
            throw $this->error(
                'Workspace Member Package Is Duplicated',
                "Compiler package identity `{$compiler}` is used by more than one member.",
                'B0405',
            );
        }
        if (isset($canonicalRoots[$member->root])) {
            throw $this->error(
                'Workspace Member Is Ambiguous',
                "Member paths `{$canonicalRoots[$member->root]}` and `{$member->relativePath}` resolve to the same directory.",
                'B0399',
            );
        }
        $members[$package] = $member;
        $canonicalRoots[$member->root] = $member->relativePath;
        $compilerIdentities[$compiler] = $member->relativePath;
    }

    private function contained(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function error(string $heading, string $body, string $code = 'B0400'): BatonError
    {
        return new BatonError($code, $heading, $body);
    }
}
