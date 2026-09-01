<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\TargetSelector;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceDiscovery;
use Doria\Baton\Tests\TestCase;

final class SourceDiscoveryTest extends TestCase
{
    public function testDiscoveryIsDeterministicScopedAndTargetSpecific(): void
    {
        $root = $this->project();
        $this->write($root, 'src/Zeta.doria');
        $this->write($root, 'src/Domain/Alpha.doria');
        $this->write($root, 'src/web.doria');
        $this->write($root, 'src/worker.doria');
        $this->write($root, 'src/ignored.txt');
        $this->write($root, 'src/Fixtures/Hidden.doria');
        $this->write($root, 'tests/PostTest.doria');
        $this->write($root, 'build/generated/NotHandwritten.doria');

        $manifest = $this->manifest($root);
        $selected = (new TargetSelector())->select($manifest, 'web', false, 'build');
        $inventory = (new SourceDiscovery($root))->discover($manifest, $selected);

        self::assertSame(
            [
                'src/Domain/Alpha.doria',
                'src/Zeta.doria',
                'src/web.doria',
                'tests/PostTest.doria',
            ],
            array_map(static fn ($source): string => $source->relativePath, $inventory->sources),
        );
        self::assertSame(
            ['main', 'main', 'main', 'development'],
            array_map(static fn ($source): string => $source->scope, $inventory->sources),
        );
        self::assertSame('entry', $inventory->sources[2]->origin);
        self::assertSame('autoload', $inventory->sources[3]->origin);
    }

    public function testLibraryExcludesAllBinaryEntries(): void
    {
        $root = $this->project();
        $this->write($root, 'src/Domain/Shared.doria');
        $this->write($root, 'src/web.doria');
        $this->write($root, 'src/worker.doria');
        $this->write($root, 'tests/PostTest.doria');
        $manifest = $this->manifest($root);
        $selected = (new TargetSelector())->select($manifest, null, true, 'build');

        $inventory = (new SourceDiscovery($root))->discover($manifest, $selected);

        self::assertSame(
            ['src/Domain/Shared.doria', 'tests/PostTest.doria'],
            array_map(static fn ($source): string => $source->relativePath, $inventory->sources),
        );
    }

    public function testToolingDiscoveryIncludesEveryBinaryEntry(): void
    {
        $root = $this->project();
        $this->write($root, 'src/Domain/Shared.doria');
        $this->write($root, 'src/web.doria');
        $this->write($root, 'src/worker.doria');
        $this->write($root, 'tests/PostTest.doria');

        $inventory = (new SourceDiscovery($root))->discoverForTooling($this->manifest($root));

        self::assertSame(
            ['src/Domain/Shared.doria', 'src/web.doria', 'src/worker.doria', 'tests/PostTest.doria'],
            array_map(static fn ($source): string => $source->relativePath, $inventory->sources),
        );
        self::assertSame(
            ['autoload', 'entry', 'entry', 'autoload'],
            array_map(static fn ($source): string => $source->origin, $inventory->sources),
        );
    }

    public function testDuplicateCanonicalSourceAndScopeConflictAreRejected(): void
    {
        $root = $this->temporaryDirectory('duplicate source');
        $this->write($root, 'src/Shared.doria');
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        if (!@symlink('../src/Shared.doria', $root . '/tests/Shared.doria')) {
            self::markTestSkipped('This host cannot create source symlinks.');
        }
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "local-project"
version = "1.0.0"
edition = "2026"
publishable = false
[targets.library]
name = "library"
[autoload.namespaces]
"" = "src/"
[autoload-dev.namespaces]
"Tests\\" = "tests/"
TOML));
        $manifest = $this->manifest($root);

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Source Has Conflicting Scopes');
        (new SourceDiscovery($root))->discover(
            $manifest,
            (new TargetSelector())->select($manifest, null, true, 'check'),
        );
    }

    public function testSymlinkEscapeIsRejected(): void
    {
        $root = $this->temporaryDirectory('symlink escape');
        $outside = $this->temporaryDirectory('outside source');
        $this->write($outside, 'Escaped.doria');
        if (!@symlink($outside, $root . '/src')) {
            self::markTestSkipped('This host cannot create directory symlinks.');
        }
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', $this->libraryManifest()));
        $manifest = $this->manifest($root);

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Autoload Symlink Escapes Project');
        (new SourceDiscovery($root))->discover(
            $manifest,
            (new TargetSelector())->select($manifest, null, true, 'check'),
        );
    }

    public function testGeneratedBoundaryValidatesHashAndScopeWithoutWritingFiles(): void
    {
        $root = $this->project();
        $this->write($root, 'src/Shared.doria');
        $this->write($root, 'src/web.doria');
        $this->write($root, 'src/worker.doria');
        $manifest = $this->manifest($root);
        $contents = "namespace Generated;\nclass Routes {}\n";
        $generated = new GeneratedSourceInput(
            'build/generated/routes.doria',
            'main',
            $contents,
            null,
            hash('sha256', $contents),
        );

        $inventory = (new SourceDiscovery($root))->discover(
            $manifest,
            (new TargetSelector())->select($manifest, 'web', false, 'check'),
            [$generated],
        );
        $generatedSources = array_values(array_filter(
            $inventory->sources,
            static fn ($source): bool => $source->scope === 'generated',
        ));
        self::assertCount(1, $generatedSources);
        self::assertSame('main', $generatedSources[0]->generatedFor);
        self::assertFileDoesNotExist($root . '/build/generated/routes.doria');

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Generated Source Input Is Invalid');
        (new SourceDiscovery($root))->discover(
            $manifest,
            (new TargetSelector())->select($manifest, 'web', false, 'check'),
            [new GeneratedSourceInput('build/generated/bad.doria', 'main', 'x', null, str_repeat('0', 64))],
        );
    }

    public function testGeneratedBoundaryRejectsAbsoluteDriveUrlAndTraversalPaths(): void
    {
        $root = $this->project();
        $this->write($root, 'src/web.doria');
        $this->write($root, 'src/worker.doria');
        $manifest = $this->manifest($root);
        $selected = (new TargetSelector())->select($manifest, 'web', false, 'check');

        foreach (['/absolute.doria', 'C:\\absolute.doria', 'https://example.test/a.doria', '../up.doria'] as $path) {
            try {
                (new SourceDiscovery($root))->discover(
                    $manifest,
                    $selected,
                    [new GeneratedSourceInput($path, 'main', 'x', null, hash('sha256', 'x'))],
                );
                self::fail("Generated path {$path} should be rejected.");
            } catch (BatonError $error) {
                self::assertSame('Generated Source Input Is Invalid', $error->heading);
            }
        }
    }

    public function testPortableCaseCollisionsAreRejected(): void
    {
        $root = $this->project();
        $this->write($root, 'src/web.doria');
        $this->write($root, 'src/worker.doria');
        $this->write($root, 'src/Name.doria');
        $this->write($root, 'src/name.doria');
        $upper = @stat($root . '/src/Name.doria');
        $lower = @stat($root . '/src/name.doria');
        if (!is_file($root . '/src/Name.doria')
            || !is_file($root . '/src/name.doria')
            || realpath($root . '/src/Name.doria') === realpath($root . '/src/name.doria')
            || (is_array($upper) && is_array($lower) && $upper['ino'] === $lower['ino'])
        ) {
            self::markTestSkipped('This filesystem cannot create ASCII case-distinct source paths.');
        }

        $manifest = $this->manifest($root);
        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Source Paths Collide Across Platforms');
        (new SourceDiscovery($root))->discover(
            $manifest,
            (new TargetSelector())->select($manifest, 'web', false, 'check'),
        );
    }

    public function testLargeDeepInventoryHasStableBinaryOrdering(): void
    {
        $root = $this->project();
        $this->write($root, 'src/web.doria');
        $this->write($root, 'src/worker.doria');
        for ($index = 63; $index >= 0; $index--) {
            $this->write($root, sprintf('src/Deep/Level/Source%02d.doria', $index));
        }
        $manifest = $this->manifest($root);
        $selected = (new TargetSelector())->select($manifest, 'web', false, 'check');
        $first = (new SourceDiscovery($root))->discover($manifest, $selected);
        $second = (new SourceDiscovery($root))->discover($manifest, $selected);
        $firstPaths = array_map(static fn ($source): string => $source->relativePath, $first->sources);
        $secondPaths = array_map(static fn ($source): string => $source->relativePath, $second->sources);

        self::assertSame($firstPaths, $secondPaths);
        $sorted = $firstPaths;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $firstPaths);
        self::assertCount(65, $firstPaths);
    }

    private function project(): string
    {
        $root = $this->temporaryDirectory('source discovery');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<'TOML'
manifest-version = 2

[package]
name = "acme/blog"
version = "1.0.0"
edition = "2026"

[targets.library]
name = "blog"

[[targets.binary]]
name = "web"
entry = "src/web.doria"

[[targets.binary]]
name = "worker"
entry = "src/worker.doria"

[autoload.namespaces]
"Acme\\Blog\\" = { path = "src/", include = ["**/*.doria"], exclude = ["**/Fixtures/**"] }

[autoload-dev.namespaces]
"Acme\\Blog\\Tests\\" = "tests/"
TOML));

        return $root;
    }

    private function libraryManifest(): string
    {
        return <<<'TOML'
manifest-version = 2
[package]
name = "local-library"
version = "1.0.0"
edition = "2026"
publishable = false
[targets.library]
name = "library"
[autoload.namespaces]
"" = "src/"
TOML;
    }

    private function manifest(string $root): Schema2Manifest
    {
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);

        return $manifest;
    }

    private function write(string $root, string $relative, string $contents = "class Fixture {}\n"): void
    {
        $path = $root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0o755, true));
        }
        self::assertNotFalse(file_put_contents($path, $contents));
    }
}
