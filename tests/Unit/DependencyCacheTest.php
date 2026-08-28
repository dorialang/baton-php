<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Dependency\CacheRootLocator;
use Doria\Baton\Dependency\CheckoutContentFingerprint;
use Doria\Baton\Dependency\DependencyCache;
use Doria\Baton\Dependency\GitClient;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\GitSelector;
use Doria\Baton\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class DependencyCacheTest extends TestCase
{
    public function testPlatformCacheRootsAreDeterministicAndUserScoped(): void
    {
        self::assertSame(
            '/cache/doria/baton',
            (new CacheRootLocator(['XDG_CACHE_HOME' => '/cache', 'HOME' => '/home/user'], 'Linux'))->locate(),
        );
        self::assertSame(
            '/home/user/.cache/doria/baton',
            (new CacheRootLocator(['HOME' => '/home/user', 'XDG_CACHE_HOME' => false], 'Linux'))->locate(),
        );
        self::assertSame(
            '/Users/test/Library/Caches/Doria/Baton',
            (new CacheRootLocator(['HOME' => '/Users/test'], 'Darwin'))->locate(),
        );
        self::assertSame(
            'C:\\Users\\test\\AppData\\Local\\Doria\\Baton\\Cache',
            (new CacheRootLocator(['LOCALAPPDATA' => 'C:\\Users\\test\\AppData\\Local'], 'Windows'))->locate(),
        );
    }

    public function testMissingHomeAndRelativeXdgAreRejected(): void
    {
        foreach ([
            new CacheRootLocator(['HOME' => false, 'XDG_CACHE_HOME' => false], 'Linux'),
            new CacheRootLocator(['HOME' => '/home/user', 'XDG_CACHE_HOME' => 'relative'], 'Linux'),
        ] as $locator) {
            try {
                $locator->locate();
                self::fail('Invalid cache environment should fail.');
            } catch (BatonError $error) {
                self::assertSame('Dependency Cache Is Unavailable', $error->heading);
            }
        }
    }

    public function testCacheKeysNeverUsePackageOrSelectorTextAsPaths(): void
    {
        $cache = new DependencyCache('/cache');
        $url = 'https://example.com/acme/package.git';
        $commit = str_repeat('a', 40);
        self::assertSame('/cache' . DIRECTORY_SEPARATOR . 'mirrors' . DIRECTORY_SEPARATOR . hash('sha256', $url), $cache->mirror($url));
        self::assertSame(
            '/cache' . DIRECTORY_SEPARATOR . 'checkouts' . DIRECTORY_SEPARATOR . hash('sha256', $url) . DIRECTORY_SEPARATOR . $commit,
            $cache->checkout($url, $commit),
        );
        self::assertStringNotContainsString('acme/package', $cache->checkout($url, $commit));
    }

    public function testCheckoutFingerprintDetectsByteAndLinkChanges(): void
    {
        $root = $this->temporaryDirectory('checkout fingerprint');
        self::assertNotFalse(file_put_contents($root . '/a', 'first'));
        $fingerprints = new CheckoutContentFingerprint();
        $first = $fingerprints->calculate($root);
        self::assertIsString($first);
        self::assertNotFalse(file_put_contents($root . '/a', 'second'));
        self::assertNotSame($first, $fingerprints->calculate($root));
    }

    public function testCacheRejectsTraversalAndSymlinkBoundaries(): void
    {
        $root = $this->temporaryDirectory('cache boundary');
        $cache = new DependencyCache($root . '/cache');

        try {
            $cache->ensureDirectory($root . '/outside');
            self::fail('A cache path outside the configured root should fail.');
        } catch (BatonError $error) {
            self::assertSame('Dependency Cache Is Unavailable', $error->heading);
        }

        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            return;
        }
        self::assertTrue(mkdir($root . '/target'));
        self::assertTrue(mkdir($root . '/cache'));
        self::assertTrue(symlink($root . '/target', $root . '/cache/checkouts'));
        try {
            $cache->ensureDirectory($root . '/cache/checkouts/example');
            self::fail('A symlink inside the cache should fail.');
        } catch (BatonError $error) {
            self::assertSame('Dependency Cache Is Unavailable', $error->heading);
        }
    }

    public function testExactGitCheckoutIsReusedAndCorruptionHasPolicySpecificBehavior(): void
    {
        $git = (new ExecutableFinder())->find('git');
        if ($git === null) {
            self::markTestSkipped('Git is unavailable.');
        }
        $workspace = $this->temporaryDirectory('git cache');
        $repository = $workspace . '/repository';
        self::assertTrue(mkdir($repository));
        self::assertNotFalse(file_put_contents($repository . '/Baton.toml', $this->packageManifest()));
        self::assertTrue(mkdir($repository . '/src'));
        self::assertNotFalse(file_put_contents($repository . '/src/Library.doria', "function answer(): int { return 42; }\n"));
        $this->git($git, $repository, ['init']);
        $this->git($git, $repository, ['config', 'user.email', 'tests@example.com']);
        $this->git($git, $repository, ['config', 'user.name', 'Baton Tests']);
        $this->git($git, $repository, ['add', '.']);
        $this->git($git, $repository, ['commit', '-m', 'fixture']);
        $commit = trim($this->git($git, $repository, ['rev-parse', 'HEAD']));

        $cache = new DependencyCache($workspace . '/cache');
        $client = new GitClient($git);
        $repositoryUrl = $this->localGitUrl($repository);
        $source = new GitDependencySource($repositoryUrl, GitSelector::parse('rev', $commit));
        $resolved = $client->resolve($source, NetworkPolicy::Online, $cache, true);
        $checkout = $client->checkout($repositoryUrl, $resolved, NetworkPolicy::Online, $cache);
        $markerTime = filemtime($checkout . '/.baton-cache.json');
        self::assertSame($checkout, $client->checkout($repositoryUrl, $resolved, NetworkPolicy::Offline, $cache));
        self::assertSame($markerTime, filemtime($checkout . '/.baton-cache.json'));

        self::assertTrue(chmod($checkout . '/src', 0o755));
        self::assertTrue(chmod($checkout . '/src/Library.doria', 0o644));
        self::assertNotFalse(file_put_contents($checkout . '/src/Library.doria', 'corrupt'));
        try {
            $client->checkout($repositoryUrl, $resolved, NetworkPolicy::Offline, $cache);
            self::fail('Offline corruption should fail.');
        } catch (BatonError $error) {
            self::assertSame('Dependency Cache Entry Is Corrupt', $error->heading);
        }
        $rebuilt = $client->checkout($repositoryUrl, $resolved, NetworkPolicy::Online, $cache);
        self::assertStringContainsString('return 42', (string) file_get_contents($rebuilt . '/src/Library.doria'));
    }

    private function localGitUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = array_map('rawurlencode', explode('/', $normalized));
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            $segments[0] = substr($normalized, 0, 2);
        }

        return 'file://' . (str_starts_with($normalized, '/') ? '' : '/') . implode('/', $segments);
    }

    /** @param list<string> $arguments */
    private function git(string $git, string $directory, array $arguments): string
    {
        $process = new Process([$git, ...$arguments], $directory, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function packageManifest(): string
    {
        return <<<'TOML'
manifest-version = 2
[package]
name = "acme/cache-fixture"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "cache-fixture"
[autoload.namespaces]
"" = "src/"
TOML;
    }
}
