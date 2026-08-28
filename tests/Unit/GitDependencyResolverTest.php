<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Dependency\DependencyCache;
use Doria\Baton\Dependency\DependencyResolver;
use Doria\Baton\Dependency\GitTransport;
use Doria\Baton\Dependency\LockFileFactory;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Tests\TestCase;

final class GitDependencyResolverTest extends TestCase
{
    public function testLockedInstallUsesExactCommitAfterBranchMoves(): void
    {
        $workspace = $this->temporaryDirectory('git locked install');
        $first = $this->package($workspace, 'first', 'acme/library', '1.0.0');
        $second = $this->package($workspace, 'second', 'acme/library', '1.1.0');
        $root = $this->root($workspace, ['acme/library' => $this->git('library')]);
        $transport = new FakeGitTransport();
        $transport->publish($this->url('library'), 'branch:main', $this->commit('1'), $first);
        $resolver = $this->resolver($workspace, $transport);

        $fresh = $resolver->resolveFresh($root, $this->manifest($root), NetworkPolicy::Online);
        $lock = (new LockFileFactory())->fromGraph($fresh);
        $transport->publish($this->url('library'), 'branch:main', $this->commit('2'), $second);
        $resolutions = $transport->resolutionCount;

        $locked = $resolver->resolveLocked($root, $this->manifest($root), $lock, NetworkPolicy::Offline);

        self::assertSame($resolutions, $transport->resolutionCount);
        self::assertSame($this->commit('1'), $locked->packages['acme/library']->source->commit);
        self::assertSame('1.0.0', $locked->packages['acme/library']->manifest->package->version);
    }

    public function testSelectedUpdateMovesOnlySelectedGitPackages(): void
    {
        $workspace = $this->temporaryDirectory('git selected update');
        $a1 = $this->package($workspace, 'a1', 'acme/a', '1.0.0');
        $a2 = $this->package($workspace, 'a2', 'acme/a', '1.1.0');
        $b1 = $this->package($workspace, 'b1', 'acme/b', '1.0.0');
        $b2 = $this->package($workspace, 'b2', 'acme/b', '1.1.0');
        $root = $this->root($workspace, [
            'acme/a' => $this->git('a'),
            'acme/b' => $this->git('b'),
        ]);
        $transport = new FakeGitTransport();
        $transport->publish($this->url('a'), 'branch:main', $this->commit('1'), $a1);
        $transport->publish($this->url('b'), 'branch:main', $this->commit('2'), $b1);
        $resolver = $this->resolver($workspace, $transport);
        $initial = $resolver->resolveFresh($root, $this->manifest($root), NetworkPolicy::Online);
        $lock = (new LockFileFactory())->fromGraph($initial);
        $transport->publish($this->url('a'), 'branch:main', $this->commit('3'), $a2);
        $transport->publish($this->url('b'), 'branch:main', $this->commit('4'), $b2);

        $updated = $resolver->resolveFresh(
            $root,
            $this->manifest($root),
            NetworkPolicy::Online,
            $lock,
            ['acme/a'],
        );

        self::assertSame($this->commit('3'), $updated->packages['acme/a']->source->commit);
        self::assertSame($this->commit('2'), $updated->packages['acme/b']->source->commit);
    }

    public function testSelectedUpdateReportsWhenAnUnselectedPinMustMove(): void
    {
        $workspace = $this->temporaryDirectory('git broader update');
        $b1 = $this->package($workspace, 'b1', 'acme/b', '1.0.0');
        $a1 = $this->package($workspace, 'a1', 'acme/a', '1.0.0', ['acme/b' => '^1.0']);
        $a2 = $this->package($workspace, 'a2', 'acme/a', '1.1.0', ['acme/b' => '^2.0']);
        $root = $this->root($workspace, ['acme/a' => $this->git('a')]);
        $transport = new FakeGitTransport();
        $transport->publish($this->url('a'), 'branch:main', $this->commit('1'), $a1);
        $transport->publish($this->url('b'), 'branch:main', $this->commit('2'), $b1);
        $resolver = $this->resolver($workspace, $transport);
        $initial = $resolver->resolveFresh($root, $this->manifest($root), NetworkPolicy::Online);
        $lock = (new LockFileFactory())->fromGraph($initial);
        $transport->publish($this->url('a'), 'branch:main', $this->commit('3'), $a2);

        try {
            $resolver->resolveFresh($root, $this->manifest($root), NetworkPolicy::Online, $lock, ['acme/a']);
            self::fail('The unselected acme/b pin should prevent the selected update.');
        } catch (BatonError $error) {
            self::assertSame('B0384', $error->diagnosticCode);
            self::assertSame('Dependency Update Requires A Broader Update', $error->heading);
            self::assertStringContainsString('acme/b', $error->body);
        }
    }

    private function resolver(string $workspace, GitTransport $transport): DependencyResolver
    {
        return new DependencyResolver($transport, new DependencyCache($workspace . '/cache'));
    }

    /** @param array<string, string> $dependencies */
    private function package(
        string $workspace,
        string $directory,
        string $name,
        string $version,
        array $dependencies = [],
    ): string {
        $root = $workspace . '/' . $directory;
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/src/Library.doria', "class Library {}\n"));
        $dependencyTable = '';
        if ($dependencies !== []) {
            $dependencyTable = "\n[dependencies]\n";
            foreach ($dependencies as $dependency => $constraint) {
                $short = substr($dependency, strrpos($dependency, '/') + 1);
                $dependencyTable .= '"' . $dependency . '" = ' . $this->git($short, $constraint) . "\n";
            }
        }
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<TOML
manifest-version = 2
[package]
name = "{$name}"
version = "{$version}"
edition = "2026"
[targets.library]
name = "library"
[autoload.namespaces]
"" = "src/"
{$dependencyTable}
TOML));

        return $root;
    }

    /** @param array<string, string> $dependencies */
    private function root(string $workspace, array $dependencies): string
    {
        $root = $workspace . '/root';
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/src/main.doria', "function main(): void {}\n"));
        $table = "[dependencies]\n";
        foreach ($dependencies as $package => $declaration) {
            $table .= '"' . $package . '" = ' . $declaration . "\n";
        }
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<TOML
manifest-version = 2
[package]
name = "acme/root"
version = "1.0.0"
edition = "2026"
[[targets.binary]]
name = "root"
entry = "src/main.doria"
[autoload.namespaces]
"" = "src/"
{$table}
TOML));

        return $root;
    }

    private function git(string $name, ?string $version = null): string
    {
        $constraint = $version === null ? '' : ', version = "' . $version . '"';

        return '{ git = "' . $this->url($name) . '", branch = "main"' . $constraint . ' }';
    }

    private function url(string $name): string
    {
        return "https://example.test/acme/{$name}.git";
    }

    private function commit(string $digit): string
    {
        return str_repeat($digit, 40);
    }

    private function manifest(string $root): Schema2Manifest
    {
        $manifest = (new ManifestLoader())->load($root);
        self::assertInstanceOf(Schema2Manifest::class, $manifest);

        return $manifest;
    }
}

final class FakeGitTransport implements GitTransport
{
    /** @var array<string, string> */
    private array $references = [];

    /** @var array<string, string> */
    private array $checkouts = [];

    public int $resolutionCount = 0;

    public function publish(string $url, string $selector, string $commit, string $checkout): void
    {
        $this->references[$url . "\0" . $selector] = $commit;
        $this->checkouts[$url . "\0" . $commit] = $checkout;
    }

    public function executable(): string
    {
        return '/fixture/git';
    }

    public function version(): string
    {
        return 'git version fixture';
    }

    public function resolve(
        GitDependencySource $source,
        NetworkPolicy $network,
        DependencyCache $cache,
        bool $refresh,
    ): string {
        unset($cache, $refresh);
        if ($network === NetworkPolicy::Offline) {
            throw new \LogicException('Offline resolution attempted network access.');
        }
        ++$this->resolutionCount;

        return $this->references[$source->url . "\0" . $source->selector->kind . ':' . $source->selector->value];
    }

    public function checkout(
        string $url,
        string $commit,
        NetworkPolicy $network,
        DependencyCache $cache,
    ): string {
        unset($network, $cache);

        return $this->checkouts[$url . "\0" . $commit];
    }
}
