<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\LockFileFactory;
use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\TargetSelector;
use Doria\Baton\Processor\GeneratedSourceRegistry;
use Doria\Baton\Processor\ProcessorSourceIdentity;
use Doria\Baton\Project\ProjectDocumentBuilder;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Tests\TestCase;
use Doria\Baton\Workspace\ProjectSelection;

final class ProjectDocumentTest extends TestCase
{
    public function testProjectDocumentSupportsLocklessPackagesWithoutDependencies(): void
    {
        $root = $this->temporaryDirectory('lockless project document');
        $this->write($root . '/src/BorderPart.doria', "namespace Acme\\Rendering;\nenum BorderPart {}\n");
        $this->write($root . '/src/WindowStyles.doria', "namespace Acme\\Rendering;\nclass WindowStyles {}\n");
        $this->write($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/app"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "app"
[autoload.namespaces]
"Acme\\" = "src/"
TOML);
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);

        $document = (new ProjectDocumentBuilder())->build(
            new ProjectSelection($root, $root, $manifest, null, false),
            true,
            NetworkPolicy::Offline,
        );

        self::assertFileDoesNotExist($root . '/Baton.lock');

        $fingerprints = $document['fingerprints'];
        self::assertIsArray($fingerprints);
        self::assertSame(hash('sha256', 'null'), $fingerprints['lock']);

        $packages = $document['packages'];
        self::assertIsArray($packages);
        $package = $packages[0];
        self::assertIsArray($package);
        $sources = $package['sources'];
        self::assertIsArray($sources);

        $identities = [];
        foreach ($sources as $source) {
            self::assertIsArray($source);
            self::assertIsString($source['identity']);
            $identities[] = $source['identity'];
        }

        self::assertSame(
            ['acme/app:src/BorderPart.doria', 'acme/app:src/WindowStyles.doria'],
            $identities,
        );
    }

    public function testProjectDocumentIncludesBinaryEntriesInTheToolingGraph(): void
    {
        $root = $this->temporaryDirectory('binary project document');
        $this->write($root . '/src/Core/Application.doria', "namespace Acme\\Core;\nclass Application {}\n");
        $this->write(
            $root . '/src/main.doria',
            "namespace Acme;\nuse Acme\\Core\\Application;\nfunction main(): void { new Application(); }\n",
        );
        $this->write($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/app"
version = "1.0.0"
edition = "2026"
kind = "binary"
entry = "src/main.doria"
[autoload.namespaces]
"Acme\\" = "src/"
TOML);
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);

        $document = (new ProjectDocumentBuilder())->build(
            new ProjectSelection($root, $root, $manifest, null, false),
            true,
            NetworkPolicy::Offline,
        );

        $packages = $document['packages'];
        self::assertIsArray($packages);
        $package = $packages[0];
        self::assertIsArray($package);
        $packageSources = $package['sources'];
        self::assertIsArray($packageSources);

        $plan = $document['toolingBuildPlan'];
        self::assertIsArray($plan);
        $planPackages = $plan['packages'];
        self::assertIsArray($planPackages);
        $planPackage = $planPackages[0];
        self::assertIsArray($planPackage);
        $planSources = $planPackage['sources'];
        self::assertIsArray($planSources);

        $expected = ['acme/app:src/Core/Application.doria', 'acme/app:src/main.doria'];
        self::assertSame($expected, array_column($packageSources, 'identity'));
        self::assertSame($expected, array_column($planSources, 'identity'));
    }

    public function testProjectDocumentUsesTheCompilerVisibleGraphAndGeneratedInventory(): void
    {
        $workspace = $this->temporaryDirectory('project document');
        $root = $workspace . '/app';
        $this->package($workspace . '/support', 'acme/support');
        $this->processor($workspace . '/generator');
        $this->write($root . '/src/App.doria', "class App {}\n");
        $this->write($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/app"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "app"
[autoload.namespaces]
"Acme\\App\\" = "src/"
[dependencies]
"acme/support" = { source = "path", path = "../support" }
[processors]
"acme/generator" = { source = "path", path = "../generator", binary = "generator", attributes = ["Acme\\Generate"] }
TOML);
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);
        $graph = (new DependencyResolver())->resolveFresh(
            $root,
            $manifest,
            NetworkPolicy::Offline,
            development: true,
            processors: true,
        );
        (new LockFileStore())->write($root, (new LockFileFactory())->fromGraph($graph));

        $generated = $root . '/build/generated/acme/generator/main/Generated.doria';
        $this->write($generated, "namespace Acme\\App;\nclass Generated {}\n");
        $hash = hash_file('sha256', $generated);
        self::assertIsString($hash);
        $requestHash = hash('sha256', 'project-document-request');
        $ownerInventory = (new SourceDiscovery($root))->discover(
            $manifest,
            (new TargetSelector())->select($manifest, null, true, 'check'),
        );
        $processor = $graph->packages['acme/generator'];
        (new GeneratedSourceRegistry())->replaceOwner(
            $root,
            'compiler-project-revision',
            $manifest,
            $ownerInventory,
            $graph->packages,
            [[
            'identity' => 'acme/app:build/generated/acme/generator/main/Generated.doria',
            'package' => 'acme/app',
            'processor' => 'acme/generator',
            'path' => $generated,
            'generatedFor' => 'main',
            'requestSha256' => $requestHash,
            'sha256' => $hash,
            ]],
            [[
                'processor' => 'acme/generator',
                'sourceIdentitySha256' => hash(
                    'sha256',
                    (new ProcessorSourceIdentity())->calculate($processor, 'generator'),
                ),
                'binaryTarget' => 'generator',
                'requestSha256' => $requestHash,
                'graphFingerprint' => 'project-document-graph',
            ]],
        );

        $selection = new ProjectSelection($root, $root, $manifest, null, false);
        $builder = new ProjectDocumentBuilder();
        $first = $builder->build($selection, false, NetworkPolicy::Offline);
        $second = $builder->build($selection, false, NetworkPolicy::Offline);
        self::assertSame($first, $second);
        $packages = $first['packages'];
        self::assertIsArray($packages);
        self::assertSame(['acme/app', 'acme/support'], array_column($packages, 'compilerPackage'));
        self::assertNotContains('acme/generator', array_column($packages, 'compilerPackage'));
        self::assertIsArray($packages[0]);
        self::assertSame(
            [['package' => 'acme/support', 'kind' => 'normal']],
            $packages[0]['dependencies'],
        );
        $plan = $first['toolingBuildPlan'];
        self::assertIsArray($plan);
        $planPackages = $plan['packages'];
        self::assertIsArray($planPackages);
        self::assertSame(
            ['acme/app', 'acme/support'],
            array_column($planPackages, 'identity'),
        );
        $generatedSources = $first['generatedSources'];
        self::assertIsArray($generatedSources);
        self::assertIsArray($generatedSources[0]);
        self::assertSame('compiler-project-revision', $generatedSources[0]['compilerRevision']);
        self::assertSame($hash, $generatedSources[0]['sha256']);
        $rootPlan = $planPackages[0];
        self::assertIsArray($rootPlan);
        $planSources = $rootPlan['sources'];
        self::assertIsArray($planSources);
        self::assertContains(
            'acme/app:build/generated/acme/generator/main/Generated.doria',
            array_column($planSources, 'identity'),
        );
    }

    private function package(string $root, string $name): void
    {
        $this->write($root . '/src/Library.doria', "class Library {}\n");
        $this->write($root . '/Baton.toml', <<<TOML
manifest-version = 2
[package]
name = "{$name}"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "library"
[autoload.namespaces]
"" = "src/"
TOML);
    }

    private function processor(string $root): void
    {
        $this->write($root . '/src/main.doria', "function main(): void {}\n");
        $this->write($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/generator"
version = "1.0.0"
edition = "2026"
[[targets.binary]]
name = "generator"
entry = "src/main.doria"
[autoload.namespaces]
"" = "src/"
TOML);
    }

    private function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0o755, true));
        }
        self::assertNotFalse(file_put_contents($path, $contents));
    }
}
