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
support = { path = "../support" }
"acme/database" = { path = "../database", version = "^2.0" }
"acme/revision" = { git = "HTTPS://CODE.EXAMPLE.COM/acme/revision.git/", rev = "1a2b3c4" }
"acme/tagged" = { git = "https://code.example.com/acme/tagged.git", tag = "v1.4.0", version = "^1.4" }
"acme/branch" = { git = "ssh://git@CODE.EXAMPLE.COM/acme/branch.git", branch = "release/1.x" }
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
        yield 'unknown field' => ['"acme/x" = { path = "../x", optional = true }', 'Manifest Field Is Unknown'];
        yield 'missing source' => ['"acme/x" = { version = "^1.0" }', 'Dependency Source Is Missing'];
        yield 'source conflict' => ['"acme/x" = { path = "../x", git = "https://example.com/x", rev = "abcdef0" }', 'Dependency Source Modes Conflict'];
        yield 'missing selector' => ['"acme/x" = { git = "https://example.com/x" }', 'Git Selector Is Missing'];
        yield 'two selectors' => ['"acme/x" = { git = "https://example.com/x", rev = "abcdef0", tag = "v1" }', 'Git Selectors Conflict'];
        yield 'three selectors' => ['"acme/x" = { git = "https://example.com/x", rev = "abcdef0", tag = "v1", branch = "main" }', 'Git Selectors Conflict'];
        yield 'path wrong type' => ['"acme/x" = { path = 1 }', 'Manifest Field Has Wrong Type'];
        yield 'git wrong type' => ['"acme/x" = { git = 1, rev = "abcdef0" }', 'Manifest Field Has Wrong Type'];
        yield 'version wrong type' => ['"acme/x" = { path = "../x", version = 1 }', 'Manifest Field Has Wrong Type'];
        yield 'selector wrong type' => ['"acme/x" = { git = "https://example.com/x", rev = 1 }', 'Manifest Field Has Wrong Type'];
        yield 'unscoped Git' => ['support = { git = "https://example.com/support", rev = "abcdef0" }', 'Dependency Declaration Is Invalid'];
        yield 'reserved local identity' => ['"local/support" = { path = "../support" }', 'Dependency Declaration Is Invalid'];
        yield 'absolute path' => ['"acme/x" = { path = "/tmp/x" }', 'Dependency Declaration Is Invalid'];
        yield 'credential URL' => ['"acme/x" = { git = "https://user:secret@example.com/x", rev = "abcdef0" }', 'Git Source Contains Credentials'];
        yield 'query URL' => ['"acme/x" = { git = "https://example.com/x?token=secret", rev = "abcdef0" }', 'Git Source URL Is Invalid'];
        yield 'file URL' => ['"acme/x" = { git = "file:///tmp/x", rev = "abcdef0" }', 'Git Source URL Is Invalid'];
        yield 'SCP URL' => ['"acme/x" = { git = "git@example.com:x", rev = "abcdef0" }', 'Git Source URL Is Invalid'];
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
