<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Dependency\LockFile;
use Doria\Baton\Dependency\LockFileStore;
use Doria\Baton\Dependency\LockedDependency;
use Doria\Baton\Dependency\LockedPackage;
use Doria\Baton\Dependency\ManifestFingerprint;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @phpstan-type LockEdge array{package: string, kind: string, constraint: string|null}
 * @phpstan-type PathSource array{kind: string, path: string}
 * @phpstan-type GitSource array{kind: string, url: string, selector: array{kind: string, value: string}, commit: string}
 * @phpstan-type LockPackage array{package: string, compilerPackage: string, version: string, manifestFingerprint: string, source: PathSource|GitSource, dependencies: list<LockEdge>}
 * @phpstan-type LockDocument array{schemaVersion: int, root: array{package: string, compilerPackage: string, version: string, manifestFingerprint: string, dependencies: list<LockEdge>}, packages: list<LockPackage>}
 */
final class LockFileTest extends TestCase
{
    public function testCanonicalLockContainsConstraintsAndStableOrdering(): void
    {
        $lock = $this->lock();
        $json = $lock->json();
        self::assertStringEndsWith("\n", $json);
        self::assertSame($json, $lock->json());
        self::assertLessThan(strpos($json, '"acme/zulu"'), strpos($json, '"acme/alpha"'));

        $root = $this->temporaryDirectory('lock canonical');
        $sha = (new LockFileStore())->write($root, $lock);
        self::assertSame(hash('sha256', $json), $sha);
        self::assertSame($json, file_get_contents($root . '/Baton.lock'));
        $loaded = (new LockFileStore())->require($root);
        self::assertSame('^1.0', $loaded->rootDependencies[0]->constraint);
        self::assertNull($loaded->packages['acme/alpha']->dependencies[0]->constraint);
    }

    #[DataProvider('invalidLockMutations')]
    public function testStrictParserRejectsMalformedLockFacts(string $mutation): void
    {
        $root = $this->temporaryDirectory('invalid lock');
        $document = $this->lockDocumentFixture();
        $this->mutateLockDocument($document, $mutation);
        self::assertNotFalse(file_put_contents(
            $root . '/Baton.lock',
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        ));

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Baton Lock');
        (new LockFileStore())->require($root);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLockMutations(): iterable
    {
        foreach ([
            'schema',
            'unknown field',
            'wrong compiler identity',
            'invalid version',
            'duplicate package',
            'duplicate edge',
            'missing edge target',
            'absolute path',
            'credential Git URL',
            'short Git commit',
            'unsorted packages',
            'unsorted edges',
        ] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    public function testManifestFingerprintIgnoresCommentsButTracksResolutionFacts(): void
    {
        $root = $this->temporaryDirectory('manifest fingerprint');
        $manifest = $this->manifest($root, "# first\n");
        $fingerprints = new ManifestFingerprint();
        $first = $fingerprints->calculate($manifest);
        $commentOnly = $this->manifest($root, "# second\n\n");
        self::assertSame($first, $fingerprints->calculate($commentOnly));

        $changed = (new ManifestLoader())->loadContents(
            $root,
            str_replace('version = "^1.0"', 'version = "^2.0"', $this->manifestBytes('')),
        );
        self::assertInstanceOf(Schema2Manifest::class, $changed);
        self::assertNotSame($first, $fingerprints->calculate($changed));

        $developmentOnly = (new ManifestLoader())->loadContents(
            $root,
            str_replace('"Tests\\\\" = "tests/"', '"Tests\\\\" = "other-tests/"', $this->manifestBytes('')),
        );
        self::assertInstanceOf(Schema2Manifest::class, $developmentOnly);
        self::assertSame($first, $fingerprints->calculate($developmentOnly));
    }

    public function testCanonicalLockOrdersPackagesByCompilerIdentity(): void
    {
        $digest = str_repeat('a', 64);
        $lock = new LockFile('acme/root', 'acme/root', '1.0.0', $digest, [], [
            'alpha' => new LockedPackage('alpha', 'local/alpha', '1.0.0', $digest, ['kind' => 'path', 'path' => '../alpha'], []),
            'acme/zulu' => new LockedPackage('acme/zulu', 'acme/zulu', '1.0.0', $digest, ['kind' => 'path', 'path' => '../zulu'], []),
        ]);

        $json = $lock->json();
        self::assertLessThan(strpos($json, '"alpha"'), strpos($json, '"acme/zulu"'));
    }

    /** @return LockDocument */
    private function lockDocumentFixture(): array
    {
        return [
            'schemaVersion' => 1,
            'root' => [
                'package' => 'acme/root',
                'compilerPackage' => 'acme/root',
                'version' => '1.0.0',
                'manifestFingerprint' => str_repeat('a', 64),
                'dependencies' => [
                    ['package' => 'acme/alpha', 'kind' => 'normal', 'constraint' => '^1.0'],
                    ['package' => 'acme/zulu', 'kind' => 'normal', 'constraint' => null],
                ],
            ],
            'packages' => [
                [
                    'package' => 'acme/alpha',
                    'compilerPackage' => 'acme/alpha',
                    'version' => '1.2.0',
                    'manifestFingerprint' => str_repeat('d', 64),
                    'source' => ['kind' => 'path', 'path' => '../alpha'],
                    'dependencies' => [
                        ['package' => 'acme/zulu', 'kind' => 'normal', 'constraint' => null],
                    ],
                ],
                [
                    'package' => 'acme/zulu',
                    'compilerPackage' => 'acme/zulu',
                    'version' => '2.0.0',
                    'manifestFingerprint' => str_repeat('b', 64),
                    'source' => [
                        'kind' => 'git',
                        'url' => 'https://example.com/acme/zulu.git',
                        'selector' => ['kind' => 'tag', 'value' => 'v2.0.0'],
                        'commit' => str_repeat('c', 40),
                    ],
                    'dependencies' => [],
                ],
            ],
        ];
    }

    /**
     * @param LockDocument $lock
     * @param-out array<array-key, mixed> $lock
     */
    private function mutateLockDocument(array &$lock, string $mutation): void
    {
        switch ($mutation) {
            case 'schema':
                $lock['schemaVersion'] = 2;
                break;
            case 'unknown field':
                $lock['surprise'] = true;
                break;
            case 'wrong compiler identity':
                $lock['root']['compilerPackage'] = 'other/root';
                break;
            case 'invalid version':
                $lock['root']['version'] = '2026.3';
                break;
            case 'duplicate package':
                $lock['packages'][] = $lock['packages'][0];
                break;
            case 'duplicate edge':
                $lock['root']['dependencies'][] = $lock['root']['dependencies'][0];
                break;
            case 'missing edge target':
                $lock['root']['dependencies'][0]['package'] = 'acme/missing';
                break;
            case 'absolute path':
                $lock['packages'][0]['source'] = ['kind' => 'path', 'path' => '/tmp/alpha'];
                break;
            case 'credential Git URL':
                $lock['packages'][1]['source'] = [
                    'kind' => 'git',
                    'url' => 'https://user:secret@example.com/zulu',
                    'selector' => ['kind' => 'tag', 'value' => 'v2.0.0'],
                    'commit' => str_repeat('c', 40),
                ];
                break;
            case 'short Git commit':
                $lock['packages'][1]['source'] = [
                    'kind' => 'git',
                    'url' => 'https://example.com/acme/zulu.git',
                    'selector' => ['kind' => 'tag', 'value' => 'v2.0.0'],
                    'commit' => 'abcdef0',
                ];
                break;
            case 'unsorted packages':
                $lock['packages'] = array_reverse($lock['packages']);
                break;
            case 'unsorted edges':
                $lock['root']['dependencies'] = array_reverse($lock['root']['dependencies']);
                break;
        }
    }

    private function lock(): LockFile
    {
        $digest = str_repeat('a', 64);

        return new LockFile(
            'acme/root',
            'acme/root',
            '1.0.0',
            $digest,
            [
                new LockedDependency('acme/zulu', null),
                new LockedDependency('acme/alpha', '^1.0'),
            ],
            [
                'acme/zulu' => new LockedPackage(
                    'acme/zulu',
                    'acme/zulu',
                    '2.0.0',
                    str_repeat('b', 64),
                    [
                        'kind' => 'git',
                        'url' => 'https://example.com/acme/zulu.git',
                        'selector' => ['kind' => 'tag', 'value' => 'v2.0.0'],
                        'commit' => str_repeat('c', 40),
                    ],
                    [],
                ),
                'acme/alpha' => new LockedPackage(
                    'acme/alpha',
                    'acme/alpha',
                    '1.2.0',
                    str_repeat('d', 64),
                    ['kind' => 'path', 'path' => '../alpha'],
                    [new LockedDependency('acme/zulu', null)],
                ),
            ],
        );
    }

    private function manifest(string $root, string $comment): Schema2Manifest
    {
        $manifest = (new ManifestLoader())->loadContents($root, $this->manifestBytes($comment));
        self::assertInstanceOf(Schema2Manifest::class, $manifest);

        return $manifest;
    }

    private function manifestBytes(string $comment): string
    {
        return <<<TOML
{$comment}manifest-version = 2
[package]
name = "acme/root"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "root"
[autoload.namespaces]
"" = "src/"
[dependencies]
"acme/alpha" = { source = "path", path = "../alpha", version = "^1.0" }
TOML;
    }
}
