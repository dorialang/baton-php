<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Compiler\MetadataCallable;
use Doria\Baton\Compiler\MetadataDocumentV3;
use Doria\Baton\Compiler\MetadataLocation;
use Doria\Baton\Compiler\MetadataTest;
use Doria\Baton\Compiler\MetadataTestCallable;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Testing\TestDiscovery;
use Doria\Baton\Tests\TestCase;

final class TestDiscoveryTest extends TestCase
{
    public function testDiscoversUnifiedTestsInDeterministicOrderAndFiltersByDisplayName(): void
    {
        $callables = [
            $this->callable('call:zeta', 'Acme\\Tests\\zeta', requiredEffects: ['IOError']),
            $this->callable('call:alpha-late', 'Acme\\Tests\\__doria_test_late', source: 'pkg:src/Z.doria'),
            $this->callable('call:alpha-early', 'Acme\\Tests\\__doria_test_early', source: 'pkg:src/A.doria'),
            $this->callable('other:test', 'Other\\Tests\\ignored', package: 'other/package'),
        ];
        $tests = [
            $this->test('test:zeta', 'zeta', $callables[0]),
            $this->test('test:alpha-late', 'Suite > alpha', $callables[1], source: 'pkg:src/Z.doria'),
            $this->test('test:alpha-early', 'Suite > alpha', $callables[2], source: 'pkg:src/A.doria'),
            $this->test('other:test', 'ignored', $callables[3], package: 'other/package'),
        ];

        $all = (new TestDiscovery())->discover($this->metadata($callables, $tests), 'acme/package', null);
        self::assertSame(
            ['test:alpha-early', 'test:alpha-late', 'test:zeta'],
            array_column($all, 'identity'),
        );
        self::assertSame('Acme\\Tests\\__doria_test_early', $all[0]->callableCanonicalName);
        self::assertSame(['IOError'], $all[2]->requiredEffects);

        $filtered = (new TestDiscovery())->discover(
            $this->metadata($callables, $tests),
            'acme/package',
            'Suite > alpha',
        );
        self::assertSame(['test:alpha-early', 'test:alpha-late'], array_column($filtered, 'identity'));
        self::assertSame([], (new TestDiscovery())->discover(
            $this->metadata($callables, $tests),
            'acme/package',
            'SUITE',
        ));
    }

    public function testRejectsAnExecutableTestWithAnUnresolvedCallableAtItsLocation(): void
    {
        $callable = $this->callable('missing:test', 'Acme\\Tests\\missing');
        $metadata = $this->metadata([], [$this->test('test:missing', 'missing', $callable)]);

        try {
            (new TestDiscovery())->discover($metadata, 'acme/package', null);
            self::fail('An unresolved executable test callable must be rejected.');
        } catch (BatonError $error) {
            self::assertSame('B0421', $error->diagnosticCode);
            self::assertSame('Test Metadata Is Invalid', $error->heading);
            self::assertStringContainsString('src/Tests.doria:byte 12', $error->body);
        }
    }

    public function testPreservesEveryCompilerProvidedNonExecutableShapeIssue(): void
    {
        $issues = [
            'targetIsNotCallable',
            'callableIsNotAFunction',
            'functionIsGeneric',
            'functionHasParameters',
            'functionDoesNotReturnVoid',
            'unsupportedAccess',
        ];
        foreach ($issues as $issue) {
            $callable = $this->callable("call:{$issue}", "Acme\\Tests\\{$issue}");
            $test = $this->test("test:{$issue}", $issue, $callable, executable: false, shapeIssue: $issue);
            try {
                (new TestDiscovery())->discover($this->metadata([$callable], [$test]), 'acme/package', null);
                self::fail("The {$issue} test shape must be rejected.");
            } catch (BatonError $error) {
                self::assertSame('B0421', $error->diagnosticCode);
                self::assertSame('Test Function Is Not Executable', $error->heading);
            }
        }
    }

    /**
     * @param list<MetadataCallable> $callables
     * @param list<MetadataTest> $tests
     */
    private function metadata(array $callables, array $tests): MetadataDocumentV3
    {
        $sources = [];
        foreach ($callables as $callable) {
            $sources[$callable->source] = $this->source($callable->source, $callable->package);
        }
        foreach ($tests as $test) {
            $sources[$test->source] = $this->source($test->source, $test->package);
        }

        return new MetadataDocumentV3(
            '2026',
            'compiler-revision',
            str_repeat('a', 64),
            ['package' => 'acme/package'],
            array_values($sources),
            [],
            [],
            $callables,
            [],
            $tests,
        );
    }

    private function test(
        string $identity,
        string $displayName,
        MetadataCallable $callable,
        string $source = 'pkg:src/Tests.doria',
        string $package = 'acme/package',
        bool $executable = true,
        ?string $shapeIssue = null,
    ): MetadataTest {
        $location = new MetadataLocation($source, 'src/Tests.doria', 12, 16);

        return new MetadataTest(
            $identity,
            $displayName,
            [$displayName],
            'attribute',
            null,
            $package,
            $source,
            null,
            $callable->identity,
            new MetadataTestCallable($callable->identity, $callable->canonicalName),
            $executable,
            $shapeIssue,
            0,
            $location,
            $location,
            $location,
        );
    }

    /** @param list<string> $requiredEffects */
    private function callable(
        string $identity,
        string $canonicalName,
        string $package = 'acme/package',
        string $source = 'pkg:src/Tests.doria',
        array $requiredEffects = [],
    ): MetadataCallable {
        return new MetadataCallable(
            $identity,
            $canonicalName,
            'function',
            $package,
            $source,
            'internal',
            0,
            [],
            'void',
            $requiredEffects,
            ['ConsoleIO'],
            new MetadataLocation($source, 'src/Tests.doria', 12, 16),
        );
    }

    /** @return array{identity: string, package: string, displayPath: string, byteLength: int} */
    private function source(string $identity, string $package): array
    {
        return [
            'identity' => $identity,
            'package' => $package,
            'displayPath' => 'src/Tests.doria',
            'byteLength' => 100,
        ];
    }
}
