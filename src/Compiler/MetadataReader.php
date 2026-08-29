<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

use Doria\Baton\Diagnostics\BatonError;

final class MetadataReader
{
    public function schema2(string $json): MetadataDocumentV2
    {
        $document = $this->decode($json);
        $this->keys($document, [
            'schemaVersion', 'edition', 'compilerRevision', 'graphFingerprint',
            'selectedTarget', 'packages', 'sources', 'attributeClasses', 'applications', 'callables',
        ]);
        if (($document['schemaVersion'] ?? null) !== 2
            || !is_string($document['edition'] ?? null)
            || !is_string($document['compilerRevision'] ?? null)
            || !is_string($document['graphFingerprint'] ?? null)
        ) {
            throw $this->invalid('Metadata schema identity fields are invalid.');
        }
        $callables = [];
        foreach ($this->objects($document['callables'] ?? null) as $callable) {
            $this->keys($callable, [
                'identity', 'canonicalName', 'kind', 'package', 'source', 'access',
                'genericParameterCount', 'parameters', 'returnType', 'requiredEffects',
                'ambientEffects', 'location',
            ]);
            $parameters = [];
            foreach ($this->objects($callable['parameters'] ?? null) as $parameter) {
                $this->keys($parameter, ['index', 'name', 'type', 'ownership']);
                if (!is_int($parameter['index'] ?? null)
                    || !is_string($parameter['name'] ?? null)
                    || !is_string($parameter['type'] ?? null)
                    || !is_string($parameter['ownership'] ?? null)
                ) {
                    throw $this->invalid('Callable parameter fields are invalid.');
                }
                $parameters[] = [
                    'index' => $parameter['index'],
                    'name' => $parameter['name'],
                    'type' => $parameter['type'],
                    'ownership' => $parameter['ownership'],
                ];
            }
            $location = $this->location($callable['location'] ?? null);
            $required = $this->strings($callable['requiredEffects'] ?? null);
            $ambient = $this->strings($callable['ambientEffects'] ?? null);
            foreach (['identity', 'canonicalName', 'kind', 'package', 'source', 'access', 'returnType'] as $field) {
                if (!is_string($callable[$field] ?? null)) {
                    throw $this->invalid("Callable field `{$field}` is invalid.");
                }
            }
            if (!is_int($callable['genericParameterCount'] ?? null)) {
                throw $this->invalid('Callable generic arity is invalid.');
            }
            $callables[] = new MetadataCallable(
                $callable['identity'],
                $callable['canonicalName'],
                $callable['kind'],
                $callable['package'],
                $callable['source'],
                $callable['access'],
                $callable['genericParameterCount'],
                $parameters,
                $callable['returnType'],
                $required,
                $ambient,
                $location,
            );
        }

        return new MetadataDocumentV2(
            $document['edition'],
            $document['compilerRevision'],
            $document['graphFingerprint'],
            $this->object($document['selectedTarget'] ?? null),
            $this->objects($document['sources'] ?? null),
            $this->objects($document['attributeClasses'] ?? null),
            $this->objects($document['applications'] ?? null),
            $callables,
        );
    }

    private function location(mixed $value): MetadataLocation
    {
        $location = $this->object($value);
        $this->keys($location, ['source', 'displayPath', 'byteStart', 'byteEnd']);
        if (!is_string($location['source'] ?? null)
            || !is_string($location['displayPath'] ?? null)
            || !is_int($location['byteStart'] ?? null)
            || !is_int($location['byteEnd'] ?? null)
        ) {
            throw $this->invalid('Metadata location is invalid.');
        }

        return new MetadataLocation(
            $location['source'],
            $location['displayPath'],
            $location['byteStart'],
            $location['byteEnd'],
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            return $this->object(json_decode($json, true, 256, JSON_THROW_ON_ERROR));
        } catch (\JsonException $error) {
            throw $this->invalid($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw $this->invalid('Expected a JSON object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw $this->invalid('Expected string JSON object keys.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function objects(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid('Expected a JSON array.');
        }

        return array_map(fn (mixed $item): array => $this->object($item), $value);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->invalid('Expected a JSON string array.');
        }
        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw $this->invalid('Expected a JSON string array.');
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $expected
     */
    private function keys(array $object, array $expected): void
    {
        $actual = array_keys($object);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw $this->invalid('Metadata object contains missing or unknown fields.');
        }
    }

    private function invalid(string $detail): BatonError
    {
        return new BatonError('B0420', 'Compiler Metadata Is Invalid', $detail);
    }
}
