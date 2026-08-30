<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Build\BuildPlanBuilder;
use Doria\Baton\Build\BuildPlanWriter;
use Doria\Baton\Manifest\AutoloadConfiguration;
use Doria\Baton\Manifest\BinaryTarget;
use Doria\Baton\Manifest\NamespaceMapping;
use Doria\Baton\Manifest\PackageDefinition;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Manifest\SourcePatternSet;
use Doria\Baton\Manifest\TargetCollection;
use Doria\Baton\Source\DiscoveredSource;
use Doria\Baton\Source\SourceInventory;
use Doria\Baton\Tests\TestCase;

final class BuildPlanTest extends TestCase
{
    public function testCompilerSchemaOnePlanIsStableCompleteAndPortable(): void
    {
        $root = $this->temporaryDirectory('build plan');
        $mapping = new NamespaceMapping(
            'Acme\\Blog\\',
            'src/',
            'main',
            new SourcePatternSet(['**/*.doria'], []),
        );
        $developmentMapping = new NamespaceMapping(
            'Acme\\Blog\\Tests\\',
            'tests/',
            'development',
            new SourcePatternSet(['**/*.doria'], []),
        );
        $manifest = new Schema2Manifest(
            new PackageDefinition('acme/blog', 'acme/blog', '1.0.0', '2026', true),
            new TargetCollection(null, [new BinaryTarget('web', 'src/web.doria')]),
            new AutoloadConfiguration([$mapping], [$developmentMapping]),
        );
        $inventory = new SourceInventory([
            new DiscoveredSource('tests/PostTest.doria', '/private/tests/PostTest.doria', 'development', 'autoload', null, $developmentMapping),
            new DiscoveredSource('src/web.doria', '/private/src/web.doria', 'main', 'entry', null, null),
            new DiscoveredSource('src/Domain/Post.doria', '/private/src/Domain/Post.doria', 'main', 'autoload', null, $mapping),
        ]);

        $plan = (new BuildPlanBuilder())->build(
            $root,
            $manifest,
            new SelectedPackageTarget($manifest->targets->binaries[0]),
            $inventory,
            'release',
        );
        $json = $plan->json();
        self::assertStringEndsWith("\n", $json);
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document['selectedTarget']);
        self::assertIsArray($document['compiler']);
        self::assertIsArray($document['packages']);
        self::assertIsArray($document['packages'][0]);
        self::assertIsArray($document['packages'][0]['sources']);
        self::assertSame(1, $document['schemaVersion']);
        self::assertSame('acme/blog:src/web.doria', $document['selectedTarget']['entrySource']);
        self::assertSame(['main'], $document['selectedTarget']['activeScopes']);
        self::assertSame('release', $document['compiler']['nativeProfile']);
        self::assertSame([], $document['packages'][0]['dependencies']);
        self::assertSame(
            ['src/Domain/Post.doria', 'src/web.doria'],
            array_column($document['packages'][0]['sources'], 'path'),
        );
        self::assertSame($root, $document['packages'][0]['root']);
        self::assertStringNotContainsString('/private/src', $json);

        $path = $root . '/build-plan.json';
        $written = (new BuildPlanWriter())->write($plan, $path);
        self::assertSame(hash('sha256', $json), $written->sha256);
        self::assertSame($json, file_get_contents($path));
        self::assertSame($json, $plan->json());
    }

    public function testLocalIdentityAndGeneratedScopeAreRepresentedWithoutNewSchemaFields(): void
    {
        $root = $this->temporaryDirectory('local generated plan');
        $manifest = new Schema2Manifest(
            new PackageDefinition('hello', 'local/hello', '0.1.0', '2026', false),
            new TargetCollection(null, [new BinaryTarget('hello', 'src/main.doria')]),
            new AutoloadConfiguration([], []),
        );
        $inventory = new SourceInventory([
            new DiscoveredSource('src/main.doria', '', 'main', 'entry', null, null),
            new DiscoveredSource('build/generated/routes.doria', '', 'generated', 'generated', 'main', null),
        ]);
        $plan = (new BuildPlanBuilder())->build(
            $root,
            $manifest,
            new SelectedPackageTarget($manifest->targets->binaries[0]),
            $inventory,
            'fast',
        );
        $document = json_decode($plan->json(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['selectedTarget']);
        self::assertIsArray($document['packages']);
        self::assertIsArray($document['packages'][0]);
        self::assertIsArray($document['packages'][0]['sources']);
        self::assertIsArray($document['packages'][0]['sources'][0]);
        self::assertIsArray($document['compiler']);

        self::assertSame('local/hello', $document['rootPackage']);
        self::assertSame(['main', 'generated'], $document['selectedTarget']['activeScopes']);
        self::assertSame('local/hello:build/generated/routes.doria', $document['packages'][0]['sources'][0]['identity']);
        self::assertSame('main', $document['packages'][0]['sources'][0]['generatedFor']);
        self::assertSame(['target', 'nativeProfile', 'targetTriple'], array_keys($document['compiler']));
    }
}
