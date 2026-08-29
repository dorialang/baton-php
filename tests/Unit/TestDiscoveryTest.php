<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Compiler\MetadataCallable;
use Doria\Baton\Compiler\MetadataDocumentV2;
use Doria\Baton\Compiler\MetadataLocation;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Testing\TestDiscovery;
use Doria\Baton\Tests\TestCase;

final class TestDiscoveryTest extends TestCase
{
    public function testDiscoversInternalTestsInDeterministicOrderAndFiltersByCanonicalName(): void
    {
        $metadata = $this->metadata(
            [
                $this->callable('test:zeta', 'Acme\\Tests\\zeta', access: 'internal', byteStart: 40),
                $this->callable('test:alpha-late', 'Acme\\Tests\\alpha', source: 'pkg:src/Z.doria', byteStart: 20),
                $this->callable('test:alpha-early', 'Acme\\Tests\\alpha', source: 'pkg:src/A.doria', byteStart: 10),
                $this->callable('other:test', 'Other\\Tests\\ignored', package: 'other/package'),
            ],
            [
                $this->application('test:zeta'),
                $this->application('test:alpha-late'),
                $this->application('test:alpha-early'),
                $this->application('other:test', package: 'other/package'),
                $this->application('test:zeta', attribute: 'Acme\\NotTest'),
            ],
        );

        $all = (new TestDiscovery())->discover($metadata, 'acme/package', null);
        self::assertSame(
            ['test:alpha-early', 'test:alpha-late', 'test:zeta'],
            array_column($all, 'identity'),
        );
        self::assertSame(['IOError'], $all[2]->requiredEffects);

        $filtered = (new TestDiscovery())->discover($metadata, 'acme/package', 'zeta');
        self::assertSame(['test:zeta'], array_column($filtered, 'identity'));
        self::assertSame([], (new TestDiscovery())->discover($metadata, 'acme/package', 'ZETA'));
    }

    public function testRejectsUnresolvedTestTargetsAtTheMetadataLocation(): void
    {
        $metadata = $this->metadata([], [$this->application('missing:test')]);

        try {
            (new TestDiscovery())->discover($metadata, 'acme/package', null);
            self::fail('An unresolved #[Test] target must be rejected.');
        } catch (BatonError $error) {
            self::assertSame('B0421', $error->diagnosticCode);
            self::assertSame('Test Attribute Target Is Unresolved', $error->heading);
            self::assertStringContainsString('src/Tests.doria:byte 12', $error->body);
        }
    }

    public function testRejectsEveryNonExecutableCallableShape(): void
    {
        $invalid = [
            'method' => $this->callable('test:method', 'Acme\\Tests\\method', kind: 'method'),
            'generic' => $this->callable('test:generic', 'Acme\\Tests\\generic', genericParameterCount: 1),
            'parameter' => $this->callable('test:parameter', 'Acme\\Tests\\parameter', parameters: [[
                'index' => 0,
                'name' => 'value',
                'type' => 'int',
                'ownership' => 'borrow',
            ]]),
            'return' => $this->callable('test:return', 'Acme\\Tests\\returnsInt', returnType: 'int'),
            'package' => $this->callable('test:package', 'Acme\\Tests\\foreign', package: 'other/package'),
        ];

        foreach ($invalid as $label => $callable) {
            try {
                (new TestDiscovery())->discover(
                    $this->metadata([$callable], [$this->application($callable->identity)]),
                    'acme/package',
                    null,
                );
                self::fail("The {$label} test shape must be rejected.");
            } catch (BatonError $error) {
                self::assertSame('B0421', $error->diagnosticCode);
                self::assertSame('Test Function Is Not Executable', $error->heading);
            }
        }
    }

    /**
     * @param list<MetadataCallable> $callables
     * @param list<array<string, mixed>> $applications
     */
    private function metadata(array $callables, array $applications): MetadataDocumentV2
    {
        return new MetadataDocumentV2(
            '2026',
            'compiler-revision',
            str_repeat('a', 64),
            ['package' => 'acme/package'],
            [],
            [],
            $applications,
            $callables,
        );
    }

    /** @return array<string, mixed> */
    private function application(
        string $target,
        string $package = 'acme/package',
        string $attribute = 'compiler-known:Test',
    ): array {
        return [
            'attributeClass' => $attribute,
            'package' => $package,
            'target' => $target,
            'location' => [
                'displayPath' => 'src/Tests.doria',
                'byteStart' => 12,
            ],
        ];
    }

    /** @param list<array{index: int, name: string, type: string, ownership: string}> $parameters */
    private function callable(
        string $identity,
        string $canonicalName,
        string $kind = 'function',
        string $package = 'acme/package',
        string $source = 'pkg:src/Tests.doria',
        string $access = 'external',
        int $genericParameterCount = 0,
        array $parameters = [],
        string $returnType = 'void',
        int $byteStart = 12,
    ): MetadataCallable {
        return new MetadataCallable(
            $identity,
            $canonicalName,
            $kind,
            $package,
            $source,
            $access,
            $genericParameterCount,
            $parameters,
            $returnType,
            ['IOError'],
            ['ConsoleIO'],
            new MetadataLocation($source, 'src/Tests.doria', $byteStart, $byteStart + 4),
        );
    }
}
