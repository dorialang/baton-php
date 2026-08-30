<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\DependencyOperations;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Dependency\WorkspaceLockFileFactory;
use Doria\Baton\Dependency\WorkspaceLockFileStore;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;
use Doria\Baton\Tests\TestCase;
use Doria\Baton\Workspace\ProjectEnvironmentFactory;
use Doria\Baton\Workspace\ProjectSelector;
use Doria\Baton\Workspace\WorkspaceDiscovery;

final class WorkspaceTest extends TestCase
{
    public function testVirtualWorkspaceResolvesOneGraphAndRoundTripsStrictSchemaTwoLock(): void
    {
        $root = $this->temporaryDirectory('virtual workspace');
        $this->write($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[workspace]
members = ["apps/*", "packages/*"]
TOML);
        $this->package($root . '/packages/core', 'acme/core');
        $this->package(
            $root . '/apps/web',
            'acme/web',
            '"acme/core" = { source = "path", path = "../../packages/core", version = "^1.0" }',
        );
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(WorkspaceManifest::class, $manifest);
        $workspace = (new WorkspaceDiscovery())->discover($root, $manifest);
        self::assertSame(['acme/core', 'acme/web'], array_keys($workspace->members));

        $graph = (new DependencyResolver())->resolveWorkspace($workspace, NetworkPolicy::Offline);
        $lock = (new WorkspaceLockFileFactory())->fromGraph($graph);
        $bytes = $lock->json();
        self::assertStringContainsString('"schemaVersion": 2', $bytes);
        self::assertStringContainsString('"kind": "workspace"', $bytes);
        self::assertStringNotContainsString($root, $bytes);
        (new WorkspaceLockFileStore())->write($root, $lock);
        $loaded = (new WorkspaceLockFileStore())->require($root);
        self::assertSame($bytes, $loaded->json());

        $locked = (new DependencyResolver())->resolveWorkspace(
            $workspace,
            NetworkPolicy::Offline,
            $loaded,
            true,
        );
        self::assertSame(['acme/core', 'acme/web'], array_keys($locked->packages));
    }

    public function testPackageBearingRootIsImplicitAndSelectionUsesCurrentOrExplicitMember(): void
    {
        $root = $this->temporaryDirectory('package workspace');
        $this->package($root, 'acme/root', workspace: '["packages/*"]');
        $member = $root . '/packages/core';
        $this->package($member, 'acme/core');

        $environment = (new ProjectEnvironmentFactory())->create($member);
        $current = (new ProjectSelector())->select($environment, null, false, false, 'build');
        self::assertInstanceOf(Schema2Manifest::class, $current->manifest);
        self::assertSame('acme/core', $current->manifest->package->name);
        $explicit = (new ProjectSelector())->select($environment, 'acme/root', false, false, 'build');
        self::assertSame($root, $explicit->projectRoot);
        $aggregate = (new ProjectSelector())->select($environment, null, true, true, 'check');
        self::assertTrue($aggregate->aggregate);
        self::assertNull($aggregate->manifest);
    }

    public function testVirtualWorkspaceRequiresSelectionAndNestedWorkspaceDiagnosticSaysDeferred(): void
    {
        $root = $this->temporaryDirectory('workspace diagnostics');
        $this->write($root . '/Baton.toml', "manifest-version = 2\n[workspace]\nmembers = [\"packages/*\"]\n");
        $member = $root . '/packages/core';
        $this->package($member, 'acme/core', workspace: '[]');

        try {
            (new ProjectEnvironmentFactory())->create($root);
            self::fail('Nested workspace should fail discovery.');
        } catch (BatonError $error) {
            self::assertSame('Nested Workspace Is Not Supported In The Initial Model', $error->heading);
            self::assertStringContainsString('Composable nested workspace roots require a later decision', $error->body);
        }
    }

    public function testUnrelatedInvalidAncestorManifestDoesNotOverrideNearestProject(): void
    {
        $root = $this->temporaryDirectory('unrelated ancestor manifest');
        $this->write($root . '/Baton.toml', '');
        $project = $root . '/examples/tui';
        $this->package($project, 'acme/tui');

        $environment = (new ProjectEnvironmentFactory())->create($project);

        $this->assertSamePath($project, $environment->commandRoot);
        $this->assertSamePath($project, $environment->lockRoot);
        self::assertNull($environment->workspace);
    }

    public function testSelectedWorkspaceFetchTraversesOnlyTheLockedSelectedClosure(): void
    {
        $root = $this->temporaryDirectory('selected workspace fetch');
        $this->write($root . '/Baton.toml', "manifest-version = 2\n[workspace]\nmembers = [\"apps/*\"]\n");
        $this->package($root . '/external/wanted', 'acme/wanted-dependency');
        $this->package($root . '/external/unrelated', 'acme/unrelated-dependency');
        $this->package(
            $root . '/apps/wanted',
            'acme/wanted',
            '"acme/wanted-dependency" = { source = "path", path = "../../external/wanted" }',
        );
        $this->package(
            $root . '/apps/unrelated',
            'acme/unrelated',
            '"acme/unrelated-dependency" = { source = "path", path = "../../external/unrelated" }',
        );
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(WorkspaceManifest::class, $manifest);
        $workspace = (new WorkspaceDiscovery())->discover($root, $manifest);
        $graph = (new DependencyResolver())->resolveWorkspace($workspace, NetworkPolicy::Offline);
        (new WorkspaceLockFileStore())->write($root, (new WorkspaceLockFileFactory())->fromGraph($graph));

        self::assertTrue(unlink($root . '/external/unrelated/src/Library.doria'));
        self::assertTrue(unlink($root . '/external/unrelated/Baton.toml'));
        self::assertTrue(rmdir($root . '/external/unrelated/src'));
        self::assertTrue(rmdir($root . '/external/unrelated'));

        $selected = (new DependencyOperations())->fetchWorkspace(
            $workspace,
            NetworkPolicy::Offline,
            ['acme/wanted-dependency'],
        );
        self::assertSame(['acme/wanted-dependency'], array_keys($selected->packages));

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Path Dependency Could Not Be Read');
        (new DependencyOperations())->fetchWorkspace($workspace, NetworkPolicy::Offline, []);
    }

    public function testMalformedDeclaredAncestorWorkspaceRemainsAuthoritative(): void
    {
        $root = $this->temporaryDirectory('malformed ancestor workspace');
        $this->write($root . '/Baton.toml', "manifest-version = 2\n[workspace]\n");
        $project = $root . '/packages/tui';
        $this->package($project, 'acme/tui');

        try {
            (new ProjectEnvironmentFactory())->create($project);
            self::fail('The declared workspace should be validated.');
        } catch (BatonError $error) {
            self::assertSame('Workspace Members Are Missing', $error->heading);
            self::assertStringContainsString($this->nativePath($root . '/Baton.toml'), $error->body);
            self::assertStringContainsString('workspace.members', $error->body);
        }
    }

    private function package(
        string $root,
        string $name,
        string $dependency = '',
        ?string $workspace = null,
    ): void {
        if (!is_dir($root . '/src')) {
            self::assertTrue(mkdir($root . '/src', 0o755, true));
        }
        $short = substr($name, strrpos($name, '/') + 1);
        $dependencyTable = $dependency === '' ? '' : "\n[dependencies]\n{$dependency}\n";
        $workspaceTable = $workspace === null ? '' : "\n[workspace]\nmembers = {$workspace}\n";
        $this->write($root . '/Baton.toml', <<<TOML
manifest-version = 2
[package]
name = "{$name}"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "{$short}"
[autoload.namespaces]
"" = "src/"
{$dependencyTable}{$workspaceTable}
TOML);
        $this->write($root . '/src/Library.doria', "class Library {}\n");
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0o755, true));
        }
        self::assertNotFalse(file_put_contents($path, $contents));
    }
}
