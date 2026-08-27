<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ManifestLoaderTest extends TestCase
{
    public function testSchemaOneRetainsItsExactHistoricalModel(): void
    {
        $manifest = $this->load(<<<'TOML'
manifest-version = 1

[package]
name = "hello-doria"
version = "1.2.3-preview.1"
kind = "binary"
entry = "src/main.doria"
TOML);

        self::assertInstanceOf(Manifest::class, $manifest);
        self::assertSame('hello-doria', $manifest->name);
        self::assertSame('1.2.3-preview.1', $manifest->version);
        self::assertSame('src/main.doria', $manifest->entry);
    }

    #[DataProvider('schemaOneUnsupportedFields')]
    public function testSchemaOneDoesNotAcquireSchemaTwoFields(string $field): void
    {
        $this->expectBatonError('Manifest Field Is Unknown');
        $this->load(<<<TOML
manifest-version = 1
{$field}

[package]
name = "hello"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
TOML);
    }

    /** @return iterable<string, array{string}> */
    public static function schemaOneUnsupportedFields(): iterable
    {
        yield 'edition' => ['edition = "2026"'];
        yield 'publishability' => ['publishable = false'];
        yield 'autoload' => ["\n[autoload.namespaces]\n\"\" = \"src/\""];
        yield 'targets' => ["\n[targets.library]\nname = \"hello\""];
    }

    public function testLocalAndScopedSchemaTwoIdentitiesAreTyped(): void
    {
        $local = $this->load($this->schema2Package('hello', 'publishable = false'));
        self::assertInstanceOf(Schema2Manifest::class, $local);
        self::assertFalse($local->package->publishable);
        self::assertSame('local/hello', $local->package->compilerIdentity);
        self::assertSame('hello', $local->targets->binaries[0]->targetName);

        $scoped = $this->load($this->schema2Package('acme/blog'));
        self::assertInstanceOf(Schema2Manifest::class, $scoped);
        self::assertTrue($scoped->package->publishable);
        self::assertSame('acme/blog', $scoped->package->compilerIdentity);
        self::assertSame('blog', $scoped->targets->binaries[0]->targetName);
    }

    public function testExplicitTargetsAndAutoloadMappingsAreTyped(): void
    {
        $manifest = $this->load(<<<'TOML'
manifest-version = 2

[package]
name = "acme/blog"
version = "1.4.2-rc.1+build.5"
edition = "2026"
publishable = false

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
TOML);

        self::assertInstanceOf(Schema2Manifest::class, $manifest);
        self::assertFalse($manifest->package->publishable);
        self::assertSame('blog', $manifest->targets->library?->targetName);
        self::assertSame(['web', 'worker'], $manifest->targets->binaryNames());
        self::assertSame('main', $manifest->autoload->main[0]->scope);
        self::assertSame(['**/Fixtures/**'], $manifest->autoload->main[0]->patterns->exclude);
        self::assertSame('development', $manifest->autoload->development[0]->scope);
    }

    #[DataProvider('invalidSchemaTwoManifests')]
    public function testSchemaTwoRejectsInvalidOrPrematureSurface(string $fragment, string $heading): void
    {
        $this->expectBatonError($heading);
        $this->load($fragment);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidSchemaTwoManifests(): iterable
    {
        yield 'local missing opt-out' => [self::schema2('hello'), 'Local Package Must Be Non-Publishable'];
        yield 'local publishable' => [self::schema2('hello', 'publishable = true'), 'Local Package Must Be Non-Publishable'];
        yield 'publishable wrong type' => [self::schema2('acme/blog', 'publishable = "false"'), 'Manifest Field Has Wrong Type'];
        yield 'reserved local vendor' => [self::schema2('local/example'), 'Synthetic Local Vendor Is Reserved'];
        yield 'invalid scoped name' => [self::schema2('Acme/blog'), 'Package Identity Is Invalid'];
        yield 'invalid semver' => [self::schema2('acme/blog', '', '1.2'), 'Package Version Is Invalid'];
        yield 'integer edition' => [str_replace('edition = "2026"', 'edition = 2026', self::schema2('acme/blog')), 'Manifest Field Has Wrong Type'];
        yield 'future edition' => [str_replace('edition = "2026"', 'edition = "2027"', self::schema2('acme/blog')), 'Doria Edition Is Unsupported'];
        yield 'unknown package field' => [str_replace('edition = "2026"', "edition = \"2026\"\ncolour = \"green\"", self::schema2('acme/blog')), 'Manifest Field Is Unknown'];
        yield 'dependency table' => [self::schema2('acme/blog') . "\n[dependencies]\n", 'Dependencies Are Not Available In This Slice'];
        yield 'development dependency table' => [self::schema2('acme/blog') . "\n[dev-dependencies]\n", 'Development Dependencies Are Not Available In This Slice'];
        yield 'processors table' => [self::schema2('acme/blog') . "\n[processors]\n", 'Processors Are Not Available In This Slice'];
        yield 'workspace table' => [self::schema2('acme/blog') . "\n[workspace]\n", 'Workspaces Are Not Available In This Slice'];
        yield 'target mode conflict' => [self::schema2('acme/blog') . "\n[targets.library]\nname = \"blog\"\n", 'Package Target Modes Conflict'];
        yield 'unfolded acronym namespace' => [str_replace('"" = "src/"', '"Doria\\\\Std\\\\IO\\\\" = "src/"', self::schema2('acme/blog')), 'Namespace Mapping Prefix Is Invalid'];
    }

    public function testMalformedTomlReportsManifestLineAndColumnWithoutParserInternals(): void
    {
        try {
            $this->load("manifest-version = 2\n[package\n");
            self::fail('Malformed TOML should fail.');
        } catch (BatonError $error) {
            self::assertSame('Project Manifest TOML Is Invalid', $error->heading);
            self::assertMatchesRegularExpression('/Baton\.toml:\d+:\d+/', $error->body);
            self::assertStringNotContainsString('PhpCollective', $error->body);
        }
    }

    public function testSchemaTwoRejectsAnEarlyLockfileWithoutAffectingSchemaOne(): void
    {
        $root = $this->temporaryDirectory('manifest lock');
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', $this->schema2Package('hello', 'publishable = false')));
        self::assertNotFalse(file_put_contents($root . '/Baton.lock', "not authoritative\n"));

        $this->expectBatonError('Baton Lock Is Not Available In This Slice');
        (new ManifestLoader())->load($root);
    }

    private function load(string $contents): Manifest|Schema2Manifest
    {
        $root = $this->temporaryDirectory('manifest');
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', $contents));

        return (new ManifestLoader())->load($root);
    }

    private function expectBatonError(string $heading): void
    {
        $this->expectException(BatonError::class);
        $this->expectExceptionMessage($heading);
    }

    private function schema2Package(string $name, string $publishability = ''): string
    {
        return self::schema2($name, $publishability);
    }

    private static function schema2(string $name, string $publishability = '', string $version = '1.0.0'): string
    {
        $publishability = $publishability === '' ? '' : "{$publishability}\n";

        return <<<TOML
manifest-version = 2

[package]
name = "{$name}"
version = "{$version}"
edition = "2026"
{$publishability}kind = "binary"
entry = "src/main.doria"

[autoload.namespaces]
"" = "src/"
TOML;
    }
}
