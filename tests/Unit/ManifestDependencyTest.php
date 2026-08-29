<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\PathDependencySource;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ManifestDependencyTest extends TestCase
{
    public function testPathAndEveryGitSelectorAreParsedIntoTypedDeclarations(): void
    {
        $manifest = $this->load(<<<'TOML'
[dependencies]
support = { source = "path", path = "../support" }
"acme/database" = { source = "path", path = "../database", version = "^2.0" }
"acme/revision" = { source = "git", url = "HTTPS://CODE.EXAMPLE.COM/acme/revision.git/", rev = "1a2b3c4" }
"acme/tagged" = { source = "git", url = "https://code.example.com/acme/tagged.git", tag = "v1.4.0", version = "^1.4" }
"acme/branch" = { source = "git", url = "ssh://git@CODE.EXAMPLE.COM/acme/branch.git", branch = "release/1.x" }
TOML);

        self::assertCount(5, $manifest->dependencies);
        self::assertInstanceOf(PathDependencySource::class, $manifest->dependencies['support']->source);
        self::assertSame('^2.0', $manifest->dependencies['acme/database']->version?->expression);
        foreach (['acme/revision' => 'rev', 'acme/tagged' => 'tag', 'acme/branch' => 'branch'] as $package => $kind) {
            $source = $manifest->dependencies[$package]->source;
            self::assertInstanceOf(GitDependencySource::class, $source);
            self::assertSame($kind, $source->selector->kind);
        }
        $source = $manifest->dependencies['acme/revision']->source;
        self::assertInstanceOf(GitDependencySource::class, $source);
        self::assertSame('https://code.example.com/acme/revision.git', $source->url);
    }

    #[DataProvider('invalidDeclarations')]
    public function testInvalidDependencyDeclarationsAreRejected(string $entry, string $heading): void
    {
        try {
            $this->load("[dependencies]\n{$entry}\n");
            self::fail('Invalid dependency declaration should fail.');
        } catch (BatonError $error) {
            self::assertSame($heading, $error->heading);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'unknown field' => ['"acme/x" = { source = "path", path = "../x", optional = true }', 'Manifest Field Is Unknown'];
        yield 'missing source' => ['"acme/x" = { version = "^1.0" }', 'Dependency Source Must Be Declared'];
        yield 'legacy Git locator' => ['"acme/x" = { git = "https://example.com/x", rev = "abcdef0" }', 'Git Source Locator Spelling Has Changed'];
        yield 'path source with URL' => ['"acme/x" = { source = "path", path = "../x", url = "https://example.com/x" }', 'Dependency Source Modes Conflict'];
        yield 'Git source with path' => ['"acme/x" = { source = "git", path = "../x", url = "https://example.com/x", rev = "abcdef0" }', 'Dependency Source Modes Conflict'];
        yield 'Git missing URL' => ['"acme/x" = { source = "git", rev = "abcdef0" }', 'Git Source URL Is Missing'];
        yield 'missing selector' => ['"acme/x" = { source = "git", url = "https://example.com/x" }', 'Git Selector Is Missing'];
        yield 'two selectors' => ['"acme/x" = { source = "git", url = "https://example.com/x", rev = "abcdef0", tag = "v1" }', 'Git Selectors Conflict'];
        yield 'three selectors' => ['"acme/x" = { source = "git", url = "https://example.com/x", rev = "abcdef0", tag = "v1", branch = "main" }', 'Git Selectors Conflict'];
        yield 'path wrong type' => ['"acme/x" = { source = "path", path = 1 }', 'Manifest Field Has Wrong Type'];
        yield 'url wrong type' => ['"acme/x" = { source = "git", url = 1, rev = "abcdef0" }', 'Manifest Field Has Wrong Type'];
        yield 'version wrong type' => ['"acme/x" = { source = "path", path = "../x", version = 1 }', 'Manifest Field Has Wrong Type'];
        yield 'selector wrong type' => ['"acme/x" = { source = "git", url = "https://example.com/x", rev = 1 }', 'Manifest Field Has Wrong Type'];
        yield 'unscoped Git' => ['support = { source = "git", url = "https://example.com/support", rev = "abcdef0" }', 'Dependency Declaration Is Invalid'];
        yield 'reserved local identity' => ['"local/support" = { source = "path", path = "../support" }', 'Dependency Declaration Is Invalid'];
        yield 'absolute path' => ['"acme/x" = { source = "path", path = "/tmp/x" }', 'Dependency Declaration Is Invalid'];
        yield 'credential URL' => ['"acme/x" = { source = "git", url = "https://user:secret@example.com/x", rev = "abcdef0" }', 'Git Source Contains Credentials'];
        yield 'query URL' => ['"acme/x" = { source = "git", url = "https://example.com/x?token=secret", rev = "abcdef0" }', 'Git Source URL Is Invalid'];
        yield 'file URL' => ['"acme/x" = { source = "git", url = "file:///tmp/x", rev = "abcdef0" }', 'Git Source URL Is Invalid'];
        yield 'SCP URL' => ['"acme/x" = { source = "git", url = "git@example.com:x", rev = "abcdef0" }', 'Git Source URL Is Invalid'];
    }

    private function load(string $dependencies): Schema2Manifest
    {
        $root = $this->temporaryDirectory('dependency manifest');
        $manifest = (new ManifestLoader())->loadContents($root, $this->baseManifest() . "\n{$dependencies}");
        self::assertInstanceOf(Schema2Manifest::class, $manifest);

        return $manifest;
    }

    private function baseManifest(): string
    {
        return <<<'TOML'
manifest-version = 2

[package]
name = "acme/root"
version = "1.0.0"
edition = "2026"

[targets.library]
name = "root"

[autoload.namespaces]
"Acme\\Root\\" = "src/"
TOML;
    }
}
