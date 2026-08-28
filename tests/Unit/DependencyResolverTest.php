<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Build\BuildPlanBuilder;
use Doria\Baton\Dependency\DependencyCache;
use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\GitTransport;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Tests\TestCase;

final class DependencyResolverTest extends TestCase
{
    public function testRecursivePathGraphUsesOnePackageNodeAndPreservesDirectEdges(): void
    {
        $workspace = $this->temporaryDirectory('path graph');
        $leaf = $this->package($workspace, 'leaf', 'acme/leaf');
        $left = $this->package($workspace, 'left', 'acme/left', ['acme/leaf' => '../leaf']);
        $right = $this->package($workspace, 'right', 'acme/right', ['acme/leaf' => '../leaf']);
        $root = $this->package($workspace, 'root', 'acme/root', [
            'acme/right' => '../right',
            'acme/left' => '../left',
        ], binary: true);

        $manifest = $this->manifest($root);
        $graph = $this->resolver($workspace)->resolveFresh($root, $manifest, NetworkPolicy::Offline);
        self::assertSame(['acme/leaf', 'acme/left', 'acme/right'], array_keys($graph->packages));
        self::assertSame($leaf, $graph->packages['acme/leaf']->source->root);
        self::assertSame($left, $graph->packages['acme/left']->source->root);

        $selected = new SelectedPackageTarget($manifest->targets->binaries[0]);
        $inventory = (new SourceDiscovery($root))->discover($manifest, $selected);
        $plan = (new BuildPlanBuilder())->build($root, $manifest, $selected, $inventory, 'fast', $graph);
        $document = json_decode($plan->json(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['packages']);
        $packages = [];
        foreach ($document['packages'] as $package) {
            self::assertIsArray($package);
            $identity = $package['identity'] ?? null;
            self::assertIsString($identity);
            $packages[$identity] = $package;
        }
        $rootDependencies = $packages['acme/root']['dependencies'] ?? null;
        $leftDependencies = $packages['acme/left']['dependencies'] ?? null;
        $leafDependencies = $packages['acme/leaf']['dependencies'] ?? null;
        self::assertIsArray($rootDependencies);
        self::assertIsArray($leftDependencies);
        self::assertIsArray($leafDependencies);
        self::assertSame(['acme/left', 'acme/right'], array_column($rootDependencies, 'package'));
        self::assertSame(['acme/leaf'], array_column($leftDependencies, 'package'));
        self::assertSame([], $leafDependencies);
        self::assertStringNotContainsString('bin/tool.doria', json_encode($packages['acme/left'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('tests/', json_encode($packages['acme/left'], JSON_THROW_ON_ERROR));
    }

    public function testUnscopedPathPackageReceivesItsSyntheticCompilerIdentity(): void
    {
        $workspace = $this->temporaryDirectory('local package graph');
        $this->package($workspace, 'support', 'support', publishable: false);
        $root = $this->package($workspace, 'root', 'acme/root', ['support' => '../support']);
        $graph = $this->resolver($workspace)->resolveFresh($root, $this->manifest($root), NetworkPolicy::Offline);

        self::assertSame('local/support', $graph->packages['support']->manifest->package->compilerIdentity);
    }

    public function testDependencyCycleReportsTheCompleteCycleAndSourceKinds(): void
    {
        $workspace = $this->temporaryDirectory('dependency cycle');
        $this->package($workspace, 'a', 'acme/a', ['acme/b' => '../b']);
        $this->package($workspace, 'b', 'acme/b', ['acme/a' => '../a']);
        $root = $this->package($workspace, 'root', 'acme/root', ['acme/a' => '../a']);

        try {
            $this->resolver($workspace)->resolveFresh($root, $this->manifest($root), NetworkPolicy::Offline);
            self::fail('Cycle should be rejected.');
        } catch (BatonError $error) {
            self::assertSame('Dependency Cycle Was Found', $error->heading);
            self::assertStringContainsString('acme/a -> acme/b -> acme/a', $error->body);
            self::assertStringContainsString('path', $error->body);
        }
    }

    public function testSourceSubstitutionAndEveryContributingChainAreReported(): void
    {
        $workspace = $this->temporaryDirectory('source substitution');
        $this->package($workspace, 'shared-one', 'acme/shared');
        $this->package($workspace, 'shared-two', 'acme/shared');
        $this->package($workspace, 'left', 'acme/left', ['acme/shared' => '../shared-one']);
        $this->package($workspace, 'middle', 'acme/middle', ['acme/shared' => '../shared-one']);
        $this->package($workspace, 'right', 'acme/right', ['acme/shared' => '../shared-two']);
        $root = $this->package($workspace, 'root', 'acme/root', [
            'acme/left' => '../left',
            'acme/middle' => '../middle',
            'acme/right' => '../right',
        ]);

        try {
            $this->resolver($workspace)->resolveFresh($root, $this->manifest($root), NetworkPolicy::Offline);
            self::fail('Source substitution should be rejected.');
        } catch (BatonError $error) {
            self::assertSame('Dependency Source Substitution Was Found', $error->heading);
            self::assertStringContainsString('acme/root -> acme/left -> acme/shared', $error->body);
            self::assertStringContainsString('acme/root -> acme/middle -> acme/shared', $error->body);
            self::assertStringContainsString('acme/root -> acme/right -> acme/shared', $error->body);
        }
    }

    public function testDependencyIdentityVersionSchemaAndLibraryAreValidated(): void
    {
        $workspace = $this->temporaryDirectory('dependency validation');
        $dependency = $this->package($workspace, 'dependency', 'acme/actual', version: '2.0.0');
        $root = $this->package($workspace, 'root', 'acme/root', ['acme/expected' => '../dependency']);
        $this->expectResolverError($root, $workspace, 'Dependency Package Name Does Not Match');

        $this->replaceManifestName($dependency, 'acme/expected');
        $this->replaceDependency($root, '"acme/expected" = { path = "../dependency" }', '"acme/expected" = { path = "../dependency", version = "^1.0" }');
        $this->expectResolverError($root, $workspace, 'Dependency Version Does Not Match');

        $this->replaceDependency($root, '"acme/expected" = { path = "../dependency", version = "^1.0" }', '"acme/expected" = { path = "../dependency" }');
        $contents = (string) file_get_contents($dependency . '/Baton.toml');
        $contents = preg_replace(
            '/\[targets\.library\]\nname = "[^"]+"/',
            "kind = \"binary\"\nentry = \"src/Library.doria\"",
            $contents,
            1,
        );
        self::assertIsString($contents);
        self::assertNotFalse(file_put_contents($dependency . '/Baton.toml', $contents));
        $this->expectResolverError($root, $workspace, 'Dependency Package Requires A Library Target');
    }

    private function expectResolverError(string $root, string $cache, string $heading): void
    {
        try {
            $this->resolver($cache)->resolveFresh($root, $this->manifest($root), NetworkPolicy::Offline);
            self::fail("Expected {$heading}.");
        } catch (BatonError $error) {
            self::assertSame($heading, $error->heading);
        }
    }

    private function resolver(string $workspace): DependencyResolver
    {
        return new DependencyResolver(
            new class implements GitTransport {
                public function executable(): ?string { return null; }
                public function version(): ?string { return null; }
                public function resolve(GitDependencySource $source, NetworkPolicy $network, DependencyCache $cache, bool $refresh): string
                {
                    throw new \LogicException('Path-only test invoked Git resolution.');
                }
                public function checkout(string $url, string $commit, NetworkPolicy $network, DependencyCache $cache): string
                {
                    throw new \LogicException('Path-only test invoked Git checkout.');
                }
            },
            new DependencyCache($workspace . '/cache'),
        );
    }

    /**
     * @param array<string, string> $dependencies
     */
    private function package(
        string $workspace,
        string $directory,
        string $name,
        array $dependencies = [],
        bool $publishable = true,
        bool $binary = false,
        string $version = '1.0.0',
    ): string {
        $root = $workspace . '/' . $directory;
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/src/Library.doria', "function value(): int { return 1; }\n"));
        self::assertNotFalse(file_put_contents($root . '/tests/LibraryTest.doria', "function testValue(): void {}\n"));
        if ($binary) {
            self::assertTrue(mkdir($root . '/bin', 0o755, true));
            self::assertNotFalse(file_put_contents($root . '/bin/tool.doria', "function main(): void {}\n"));
        }
        $short = substr($name, strrpos($name, '/') === false ? 0 : strrpos($name, '/') + 1);
        $publishability = $publishable ? '' : "publishable = false\n";
        $target = "[targets.library]\nname = \"{$short}\"\n";
        if ($binary) {
            $target .= "\n[[targets.binary]]\nname = \"tool\"\nentry = \"bin/tool.doria\"\n";
        }
        $dependencyText = '';
        if ($dependencies !== []) {
            $dependencyText = "\n[dependencies]\n";
            foreach ($dependencies as $package => $path) {
                $dependencyText .= '"' . $package . '" = { path = "' . $path . '" }' . "\n";
            }
        }
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<TOML
manifest-version = 2

[package]
name = "{$name}"
version = "{$version}"
edition = "2026"
{$publishability}
{$target}
[autoload.namespaces]
"" = "src/"

[autoload-dev.namespaces]
"Tests\\\\" = "tests/"
{$dependencyText}
TOML));

        return (string) realpath($root);
    }

    private function manifest(string $root): Schema2Manifest
    {
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);

        return $manifest;
    }

    private function replaceManifestName(string $root, string $name): void
    {
        $contents = (string) file_get_contents($root . '/Baton.toml');
        $contents = preg_replace('/name = "acme\/actual"/', 'name = "' . $name . '"', $contents, 1);
        self::assertIsString($contents);
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', $contents));
    }

    private function replaceDependency(string $root, string $from, string $to): void
    {
        $contents = (string) file_get_contents($root . '/Baton.toml');
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', str_replace($from, $to, $contents)));
    }
}
