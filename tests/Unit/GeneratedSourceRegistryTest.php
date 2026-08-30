<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Dependency\ManifestFingerprint;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Dependency\ResolvedPackageSource;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\TargetSelector;
use Doria\Baton\Processor\GeneratedSourceRegistry;
use Doria\Baton\Processor\ProcessorSourceIdentity;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Tests\TestCase;

final class GeneratedSourceRegistryTest extends TestCase
{
    public function testValidGeneratedSourcesAndEmptyProcessorRunsAreRecordedDeterministically(): void
    {
        [$root, $owner, $packages] = $this->ownerPackage();
        $generated = $root . '/build/generated/acme/generator/main/Generated.doria';
        $this->write($generated, "class Generated {}\n");
        $hash = hash_file('sha256', $generated);
        self::assertIsString($hash);
        $requestHash = hash('sha256', 'request');
        $registry = new GeneratedSourceRegistry();
        $registry->replaceOwner($root, 'compiler-test-revision', $owner->manifest, $owner->inventory, $packages, [[
            'identity' => 'acme/app:build/generated/acme/generator/main/Generated.doria',
            'package' => 'acme/app',
            'processor' => 'acme/generator',
            'path' => $generated,
            'generatedFor' => 'main',
            'requestSha256' => $requestHash,
            'sha256' => $hash,
        ]], [$this->processorRun($packages['acme/generator'], $requestHash)]);

        $first = $registry->requireValid($root, ['acme/app' => $owner], $packages);
        $bytes = file_get_contents($root . '/build/.baton/generated-sources.json');
        self::assertIsString($bytes);
        self::assertSame('compiler-test-revision', $first['compilerRevision']);
        self::assertCount(1, $first['inputs']['acme/app']);
        self::assertSame(
            'build/generated/acme/generator/main/Generated.doria',
            $first['inputs']['acme/app'][0]->relativePath,
        );

        $registry->replaceOwner(
            $root,
            'compiler-test-revision',
            $owner->manifest,
            $owner->inventory,
            $packages,
            [],
            [],
        );
        $emptyBytes = file_get_contents($root . '/build/.baton/generated-sources.json');
        self::assertIsString($emptyBytes);
        $empty = $registry->requireValid($root, ['acme/app' => $owner], $packages);
        self::assertSame([], $empty['sources']);
        $registry->replaceOwner(
            $root,
            'compiler-test-revision',
            $owner->manifest,
            $owner->inventory,
            $packages,
            [],
            [],
        );
        self::assertSame($emptyBytes, file_get_contents($root . '/build/.baton/generated-sources.json'));
        self::assertNotSame($bytes, $emptyBytes);
    }

    public function testRegistryRejectsAValidlyHashedPathOutsideItsOwningPackage(): void
    {
        [$root, $owner, $packages] = $this->ownerPackage();
        $outside = $this->temporaryDirectory('outside generated source') . '/Escaped.doria';
        $this->write($outside, "class Escaped {}\n");
        $hash = hash_file('sha256', $outside);
        self::assertIsString($hash);
        $requestHash = hash('sha256', 'request');
        $registry = new GeneratedSourceRegistry();
        $registry->replaceOwner($root, 'compiler-test-revision', $owner->manifest, $owner->inventory, $packages, [[
            'identity' => 'acme/app:build/generated/acme/generator/main/Escaped.doria',
            'package' => 'acme/app',
            'processor' => 'acme/generator',
            'path' => $outside,
            'generatedFor' => 'main',
            'requestSha256' => $requestHash,
            'sha256' => $hash,
        ]], [$this->processorRun($packages['acme/generator'], $requestHash)]);

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Project Generated Sources Are Stale');
        $registry->requireValid($root, ['acme/app' => $owner], $packages);
    }

    public function testPublicationRebuildsCorruptDisposableRegistryState(): void
    {
        [$root, $owner, $packages] = $this->ownerPackage();
        $path = $root . '/build/.baton/generated-sources.json';
        $this->write($path, '{"oldPrivateShape":true}');
        $registry = new GeneratedSourceRegistry();

        $registry->replaceOwner(
            $root,
            'compiler-test-revision',
            $owner->manifest,
            $owner->inventory,
            $packages,
            [],
            [],
        );

        $result = $registry->requireValid($root, ['acme/app' => $owner], $packages);
        self::assertSame('compiler-test-revision', $result['compilerRevision']);
        self::assertSame([], $result['sources']);
    }

    public function testRegistryRejectsChangedOwnerDeclarationsAndProcessorSources(): void
    {
        [$root, $owner, $packages] = $this->ownerPackage();
        $registry = new GeneratedSourceRegistry();
        $registry->replaceOwner(
            $root,
            'compiler-test-revision',
            $owner->manifest,
            $owner->inventory,
            $packages,
            [],
            [],
        );

        $manifestBytes = (string) file_get_contents($root . '/Baton.toml');
        $this->write($root . '/Baton.toml', str_replace('Acme\\\\Generate', 'Acme\\\\Changed', $manifestBytes));
        $changedOwner = $this->resolvedOwner($root);
        try {
            $registry->requireValid($root, ['acme/app' => $changedOwner], $packages);
            self::fail('Changed processor attributes must invalidate generated output.');
        } catch (BatonError $error) {
            self::assertSame('Project Generated Sources Are Stale', $error->heading);
        }

        $registry->replaceOwner(
            $root,
            'compiler-test-revision',
            $changedOwner->manifest,
            $changedOwner->inventory,
            $packages,
            [],
            [],
        );
        $processorRoot = $packages['acme/generator']->source->root;
        $this->write($processorRoot . '/src/main.doria', "function main(): void { echo \"changed\"; }\n");
        $changedPackages = $packages;
        $changedPackages['acme/generator'] = $this->resolvedProcessor($processorRoot);

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Project Generated Sources Are Stale');
        $registry->requireValid($root, ['acme/app' => $changedOwner], $changedPackages);
    }

    /** @return array{string, ResolvedPackage, array<string, ResolvedPackage>} */
    private function ownerPackage(): array
    {
        $workspace = $this->temporaryDirectory('generated registry');
        $root = $workspace . '/app';
        $processor = $workspace . '/generator';
        $this->write($root . '/src/App.doria', "class App {}\n");
        $this->write($processor . '/src/main.doria', "function main(): void {}\n");
        $this->write($processor . '/Baton.toml', <<<'TOML'
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
        $this->write($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/app"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "app"
[autoload.namespaces]
"" = "src/"
[processors]
"acme/generator" = { source = "path", path = "../generator", binary = "generator", attributes = ["Acme\\Generate"] }
TOML);
        $owner = $this->resolvedOwner($root);
        $processorPackage = $this->resolvedProcessor($processor);

        return [$root, $owner, ['acme/generator' => $processorPackage]];
    }

    private function resolvedOwner(string $root): ResolvedPackage
    {
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);
        $inventory = (new SourceDiscovery($root))->discover(
            $manifest,
            (new TargetSelector())->select($manifest, null, true, 'check'),
        );

        return new ResolvedPackage(
            $manifest,
            new ResolvedPackageSource('workspace', $root, '.'),
            (new ManifestFingerprint())->calculate($manifest),
            $inventory,
        );
    }

    private function resolvedProcessor(string $root): ResolvedPackage
    {
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);
        $target = $manifest->targets->binary('generator');
        self::assertNotNull($target);
        $selected = new \Doria\Baton\Manifest\SelectedPackageTarget($target);
        $inventory = (new SourceDiscovery($root))->discover($manifest, $selected);

        return (new ResolvedPackage(
            $manifest,
            new ResolvedPackageSource('path', $root, '../generator'),
            (new ManifestFingerprint())->calculate($manifest),
            $inventory,
        ))->withInventory($selected, $inventory, true);
    }

    /** @return array<string, string> */
    private function processorRun(ResolvedPackage $processor, string $requestHash): array
    {
        return [
            'processor' => 'acme/generator',
            'sourceIdentitySha256' => hash(
                'sha256',
                (new ProcessorSourceIdentity())->calculate($processor, 'generator'),
            ),
            'binaryTarget' => 'generator',
            'requestSha256' => $requestHash,
            'graphFingerprint' => 'graph-fingerprint',
        ];
    }

    private function write(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0o755, true));
        }
        self::assertNotFalse(file_put_contents($path, $contents));
    }
}
