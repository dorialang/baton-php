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
}
