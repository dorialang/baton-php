<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Compiler\MetadataReader;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Tests\TestCase;

final class MetadataReaderTest extends TestCase
{
    public function testReadsStrictSchemaTwoCallableFacts(): void
    {
        $document = (new MetadataReader())->schema2($this->json());

        self::assertSame('compiler-revision', $document->compilerRevision);
        self::assertCount(1, $document->callables);
        $callable = $document->callables[0];
        self::assertSame('acme/app:function:run', $callable->identity);
        self::assertSame('take', $callable->parameters[0]['ownership']);
        self::assertSame(['IOError'], $callable->requiredEffects);
        self::assertSame(['ConsoleIO'], $callable->ambientEffects);
        self::assertSame(18, $callable->location->byteEnd);
    }

    public function testRejectsUnknownSchemaAndNestedCallableFields(): void
    {
        $unknownSchema = json_decode($this->json(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($unknownSchema);
        $unknownSchema['schemaVersion'] = 1;
        $this->assertInvalid(json_encode($unknownSchema, JSON_THROW_ON_ERROR), 'Metadata schema identity fields are invalid.');

        $unknownField = json_decode($this->json(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($unknownField);
        self::assertIsArray($unknownField['callables']);
        self::assertIsArray($unknownField['callables'][0]);
        $unknownField['callables'][0]['backendSymbol'] = '__doria_run';
        $this->assertInvalid(json_encode($unknownField, JSON_THROW_ON_ERROR), 'missing or unknown fields');
    }

    public function testRejectsMalformedParameterAndLocationFacts(): void
    {
        $parameter = json_decode($this->json(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($parameter);
        self::assertIsArray($parameter['callables']);
        self::assertIsArray($parameter['callables'][0]);
        self::assertIsArray($parameter['callables'][0]['parameters']);
        self::assertIsArray($parameter['callables'][0]['parameters'][0]);
        $parameter['callables'][0]['parameters'][0]['index'] = 'zero';
        $this->assertInvalid(json_encode($parameter, JSON_THROW_ON_ERROR), 'Callable parameter fields are invalid.');

        $location = json_decode($this->json(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($location);
        self::assertIsArray($location['callables']);
        self::assertIsArray($location['callables'][0]);
        self::assertIsArray($location['callables'][0]['location']);
        $location['callables'][0]['location']['byteEnd'] = null;
        $this->assertInvalid(json_encode($location, JSON_THROW_ON_ERROR), 'Metadata location is invalid.');
    }

    public function testReadsStrictSchemaThreeSuitesTestsAndCallableReferences(): void
    {
        $document = (new MetadataReader())->schema3($this->json3());

        self::assertCount(1, $document->testSuites);
        self::assertSame(['shopping cart'], $document->testSuites[0]->pathSegments);
        self::assertCount(1, $document->tests);
        self::assertSame('shopping cart > adds an item', $document->tests[0]->displayName);
        self::assertSame('behavioral', $document->tests[0]->origin);
        self::assertSame('it', $document->tests[0]->authoredSpelling);
        self::assertSame('acme/app:function:run', $document->tests[0]->callable?->identity);
    }

    public function testSchemaThreeRejectsUnknownEnumsFieldsDuplicatesAndBrokenReferences(): void
    {
        $this->assertInvalid3(
            $this->withTestField($this->document3(), 'backendSymbol', '__test'),
            'missing or unknown fields',
        );
        $this->assertInvalid3(
            $this->withTestField($this->document3(), 'origin', 'runtime'),
            'Test origin is invalid.',
        );
        $this->assertInvalid3(
            $this->withTestField($this->document3(), 'shapeIssue', 'mystery'),
            'Test shape issue is invalid.',
        );
        $this->assertInvalid3(
            $this->withTestField($this->document3(), 'suite', 'suite:missing'),
            'invalid suite reference',
        );
        $this->assertInvalid3(
            $this->withCallableName($this->document3(), 'Acme\\Tests\\invented'),
            'invalid callable reference',
        );
        $this->assertInvalid3($this->withDuplicateTest($this->document3()), 'duplicate test identity');
    }

    /** @param array<string, mixed> $document */
    private function assertInvalid3(array $document, string $detail): void
    {
        try {
            (new MetadataReader())->schema3(json_encode($document, JSON_THROW_ON_ERROR));
            self::fail('Invalid schema-3 metadata must be rejected.');
        } catch (BatonError $error) {
            self::assertSame('B0420', $error->diagnosticCode);
            self::assertStringContainsString($detail, $error->body);
        }
    }

    /** @param array<string, mixed> $document
     *  @return array<string, mixed>
     */
    private function withTestField(array $document, string $field, mixed $value): array
    {
        $tests = $document['tests'] ?? null;
        if (!is_array($tests) || !array_is_list($tests) || !is_array($tests[0] ?? null)) {
            throw new \LogicException('Schema-3 test fixture is malformed.');
        }
        $tests[0][$field] = $value;
        $document['tests'] = $tests;

        return $document;
    }

    /** @param array<string, mixed> $document
     *  @return array<string, mixed>
     */
    private function withCallableName(array $document, string $name): array
    {
        $tests = $document['tests'] ?? null;
        if (!is_array($tests) || !array_is_list($tests) || !is_array($tests[0] ?? null)) {
            throw new \LogicException('Schema-3 test fixture is malformed.');
        }
        $callable = $tests[0]['callable'] ?? null;
        if (!is_array($callable) || array_is_list($callable)) {
            throw new \LogicException('Schema-3 callable fixture is malformed.');
        }
        $callable['canonicalName'] = $name;
        $tests[0]['callable'] = $callable;
        $document['tests'] = $tests;

        return $document;
    }

    /** @param array<string, mixed> $document
     *  @return array<string, mixed>
     */
    private function withDuplicateTest(array $document): array
    {
        $tests = $document['tests'] ?? null;
        if (!is_array($tests) || !array_is_list($tests) || !is_array($tests[0] ?? null)) {
            throw new \LogicException('Schema-3 test fixture is malformed.');
        }
        $tests[] = $tests[0];
        $document['tests'] = $tests;

        return $document;
    }

    private function assertInvalid(string $json, string $detail): void
    {
        try {
            (new MetadataReader())->schema2($json);
            self::fail('Invalid metadata must be rejected.');
        } catch (BatonError $error) {
            self::assertSame('B0420', $error->diagnosticCode);
            self::assertSame('Compiler Metadata Is Invalid', $error->heading);
            self::assertStringContainsString($detail, $error->body);
        }
    }

    private function json(): string
    {
        return json_encode([
            'schemaVersion' => 2,
            'edition' => '2026',
            'compilerRevision' => 'compiler-revision',
            'graphFingerprint' => str_repeat('a', 64),
            'selectedTarget' => ['package' => 'acme/app'],
            'packages' => [],
            'sources' => [],
            'attributeClasses' => [],
            'applications' => [],
            'callables' => [[
                'identity' => 'acme/app:function:run',
                'canonicalName' => 'Acme\\App\\run',
                'kind' => 'function',
                'package' => 'acme/app',
                'source' => 'acme/app:src/run.doria',
                'access' => 'internal',
                'genericParameterCount' => 0,
                'parameters' => [[
                    'index' => 0,
                    'name' => 'value',
                    'type' => 'string',
                    'ownership' => 'take',
                ]],
                'returnType' => 'void',
                'requiredEffects' => ['IOError'],
                'ambientEffects' => ['ConsoleIO'],
                'location' => [
                    'source' => 'acme/app:src/run.doria',
                    'displayPath' => 'src/run.doria',
                    'byteStart' => 4,
                    'byteEnd' => 18,
                ],
            ]],
        ], JSON_THROW_ON_ERROR) . "\n";
    }

    private function json3(): string
    {
        return json_encode($this->document3(), JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string, mixed> */
    private function document3(): array
    {
        /** @var array<string, mixed> $document */
        $document = json_decode($this->json(), true, flags: JSON_THROW_ON_ERROR);
        $document['schemaVersion'] = 3;
        $document['selectedTarget'] = [
            'package' => 'acme/app',
            'kind' => 'library',
            'entrySource' => null,
        ];
        $document['packages'] = [['identity' => 'acme/app']];
        $document['sources'] = [[
            'identity' => 'acme/app:src/run.doria',
            'package' => 'acme/app',
            'displayPath' => 'src/run.doria',
            'byteLength' => 100,
        ]];
        $location = [
            'source' => 'acme/app:src/run.doria',
            'displayPath' => 'src/run.doria',
            'byteStart' => 4,
            'byteEnd' => 18,
        ];
        $document['testSuites'] = [[
            'identity' => 'suite:cart',
            'displayName' => 'shopping cart',
            'pathSegments' => ['shopping cart'],
            'package' => 'acme/app',
            'source' => 'acme/app:src/run.doria',
            'parentSuite' => null,
            'authoredOrdinal' => 0,
            'location' => $location,
            'callNameLocation' => $location,
            'descriptionLocation' => $location,
        ]];
        $document['tests'] = [[
            'identity' => 'test:add-item',
            'displayName' => 'shopping cart > adds an item',
            'pathSegments' => ['shopping cart', 'adds an item'],
            'origin' => 'behavioral',
            'authoredSpelling' => 'it',
            'package' => 'acme/app',
            'source' => 'acme/app:src/run.doria',
            'suite' => 'suite:cart',
            'target' => 'acme/app:function:run',
            'callable' => [
                'identity' => 'acme/app:function:run',
                'canonicalName' => 'Acme\\App\\run',
            ],
            'executable' => true,
            'shapeIssue' => null,
            'authoredOrdinal' => 1,
            'location' => $location,
            'callNameLocation' => $location,
            'descriptionLocation' => $location,
        ]];

        return $document;
    }
}
