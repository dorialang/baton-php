<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

use Doria\Baton\Diagnostics\BatonError;

final class MetadataReader
{
    private const TEST_ORIGINS = ['attribute', 'behavioral'];
    private const TEST_SPELLINGS = ['it', 'test'];
    private const TEST_SHAPE_ISSUES = [
        'targetIsNotCallable',
        'callableIsNotAFunction',
        'functionIsGeneric',
        'functionHasParameters',
        'functionDoesNotReturnVoid',
        'unsupportedAccess',
    ];

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
        return new MetadataDocumentV2(
            $document['edition'],
            $document['compilerRevision'],
            $document['graphFingerprint'],
            $this->object($document['selectedTarget'] ?? null),
            $this->objects($document['sources'] ?? null),
            $this->objects($document['attributeClasses'] ?? null),
            $this->objects($document['applications'] ?? null),
            $this->callables($document['callables'] ?? null),
        );
    }

    public function schema3(string $json): MetadataDocumentV3
    {
        $document = $this->decode($json);
        $this->keys($document, [
            'schemaVersion', 'edition', 'compilerRevision', 'graphFingerprint',
            'selectedTarget', 'packages', 'sources', 'attributeClasses', 'applications', 'callables',
            'testSuites', 'tests',
        ]);
        if (($document['schemaVersion'] ?? null) !== 3
            || !is_string($document['edition'] ?? null)
            || !is_string($document['compilerRevision'] ?? null)
            || !is_string($document['graphFingerprint'] ?? null)
        ) {
            throw $this->invalid('Metadata schema identity fields are invalid.');
        }

        $packageFacts = $this->packageFacts($document['packages'] ?? null);
        $sourceFacts = $this->sourceFacts($document['sources'] ?? null, $packageFacts);
        $selectedTarget = $this->strictTarget($document['selectedTarget'] ?? null, $packageFacts, $sourceFacts);
        $attributeClasses = $this->strictAttributeClasses($document['attributeClasses'] ?? null, $sourceFacts);
        $applications = $this->strictApplications(
            $document['applications'] ?? null,
            $packageFacts,
            $sourceFacts,
            $attributeClasses,
        );
        $callables = $this->callables($document['callables'] ?? null);
        $callableFacts = [];
        foreach ($callables as $callable) {
            if (isset($callableFacts[$callable->identity])) {
                throw $this->invalid('Metadata contains a duplicate callable identity.');
            }
            $callableFacts[$callable->identity] = $callable;
        }

        $suites = [];
        $suiteFacts = [];
        foreach ($this->objects($document['testSuites'] ?? null) as $suite) {
            $this->keys($suite, [
                'identity', 'displayName', 'pathSegments', 'package', 'source', 'parentSuite',
                'authoredOrdinal', 'location', 'callNameLocation', 'descriptionLocation',
            ]);
            foreach (['identity', 'displayName', 'package', 'source'] as $field) {
                if (!is_string($suite[$field] ?? null) || $suite[$field] === '') {
                    throw $this->invalid("Test suite field `{$field}` is invalid.");
                }
            }
            $parentSuite = $suite['parentSuite'] ?? null;
            if (!(is_string($parentSuite) || $parentSuite === null)
                || !is_int($suite['authoredOrdinal'] ?? null)
                || $suite['authoredOrdinal'] < 0
            ) {
                throw $this->invalid('Test suite relationship or ordinal fields are invalid.');
            }
            $identity = $suite['identity'];
            if (isset($suiteFacts[$identity])) {
                throw $this->invalid('Metadata contains a duplicate test-suite identity.');
            }
            $this->knownPackageAndSource($suite['package'], $suite['source'], $packageFacts, $sourceFacts);
            $metadataSuite = new MetadataTestSuite(
                $identity,
                $suite['displayName'],
                $this->nonemptyStrings($suite['pathSegments'] ?? null, 'Test suite path segments are invalid.'),
                $suite['package'],
                $suite['source'],
                $parentSuite,
                $suite['authoredOrdinal'],
                $this->location($suite['location'] ?? null),
                $this->location($suite['callNameLocation'] ?? null),
                $this->location($suite['descriptionLocation'] ?? null),
            );
            $this->testLocationsMatch($metadataSuite, $metadataSuite->source, $sourceFacts);
            $suites[] = $metadataSuite;
            $suiteFacts[$identity] = $metadataSuite;
        }
        foreach ($suites as $suite) {
            if ($suite->parentSuite === null) {
                if (count($suite->pathSegments) !== 1) {
                    throw $this->invalid('A root test suite has an invalid path.');
                }
            } else {
                $parent = $suiteFacts[$suite->parentSuite] ?? null;
                if (!$parent instanceof MetadataTestSuite
                    || $parent->package !== $suite->package
                    || $parent->source !== $suite->source
                    || array_slice($suite->pathSegments, 0, -1) !== $parent->pathSegments
                ) {
                    throw $this->invalid('A test suite has an invalid parent-suite reference.');
                }
            }
            if ($suite->displayName !== implode(' > ', $suite->pathSegments)) {
                throw $this->invalid('A test suite display name does not match its path.');
            }
            $seen = [];
            $current = $suite;
            while ($current->parentSuite !== null) {
                if (isset($seen[$current->identity])) {
                    throw $this->invalid('Metadata contains a cycle in test-suite parents.');
                }
                $seen[$current->identity] = true;
                $parent = $suiteFacts[$current->parentSuite] ?? null;
                if (!$parent instanceof MetadataTestSuite) {
                    break;
                }
                $current = $parent;
            }
        }

        $tests = [];
        $testFacts = [];
        foreach ($this->objects($document['tests'] ?? null) as $test) {
            $this->keys($test, [
                'identity', 'displayName', 'pathSegments', 'origin', 'authoredSpelling', 'package',
                'source', 'suite', 'target', 'callable', 'executable', 'shapeIssue', 'authoredOrdinal',
                'location', 'callNameLocation', 'descriptionLocation',
            ]);
            foreach (['identity', 'displayName', 'origin', 'package', 'source', 'target'] as $field) {
                if (!is_string($test[$field] ?? null) || $test[$field] === '') {
                    throw $this->invalid("Test field `{$field}` is invalid.");
                }
            }
            if (!in_array($test['origin'], self::TEST_ORIGINS, true)) {
                throw $this->invalid('Test origin is invalid.');
            }
            $spelling = $test['authoredSpelling'] ?? null;
            if (!($spelling === null || (is_string($spelling) && in_array($spelling, self::TEST_SPELLINGS, true)))) {
                throw $this->invalid('Test authored spelling is invalid.');
            }
            $shapeIssue = $test['shapeIssue'] ?? null;
            if (!($shapeIssue === null
                || (is_string($shapeIssue) && in_array($shapeIssue, self::TEST_SHAPE_ISSUES, true)))
            ) {
                throw $this->invalid('Test shape issue is invalid.');
            }
            $suiteIdentity = $test['suite'] ?? null;
            if (!(is_string($suiteIdentity) || $suiteIdentity === null)
                || !is_bool($test['executable'] ?? null)
                || !is_int($test['authoredOrdinal'] ?? null)
                || $test['authoredOrdinal'] < 0
            ) {
                throw $this->invalid('Test relationship, execution, or ordinal fields are invalid.');
            }
            $identity = $test['identity'];
            if (isset($testFacts[$identity])) {
                throw $this->invalid('Metadata contains a duplicate test identity.');
            }
            $this->knownPackageAndSource($test['package'], $test['source'], $packageFacts, $sourceFacts);
            $callable = $this->testCallable($test['callable'] ?? null);
            $metadataTest = new MetadataTest(
                $identity,
                $test['displayName'],
                $this->nonemptyStrings($test['pathSegments'] ?? null, 'Test path segments are invalid.'),
                $test['origin'],
                $spelling,
                $test['package'],
                $test['source'],
                $suiteIdentity,
                $test['target'],
                $callable,
                $test['executable'],
                $shapeIssue,
                $test['authoredOrdinal'],
                $this->location($test['location'] ?? null),
                $this->location($test['callNameLocation'] ?? null),
                $this->location($test['descriptionLocation'] ?? null),
            );
            $this->testLocationsMatch($metadataTest, $metadataTest->source, $sourceFacts);
            if ($metadataTest->suite !== null) {
                $suite = $suiteFacts[$metadataTest->suite] ?? null;
                if (!$suite instanceof MetadataTestSuite
                    || $suite->package !== $metadataTest->package
                    || $suite->source !== $metadataTest->source
                    || array_slice($metadataTest->pathSegments, 0, -1) !== $suite->pathSegments
                ) {
                    throw $this->invalid('A test has an invalid suite reference.');
                }
            } elseif (count($metadataTest->pathSegments) !== 1) {
                throw $this->invalid('A root test has an invalid path.');
            }
            if ($callable !== null) {
                $referenced = $callableFacts[$callable->identity] ?? null;
                if (!$referenced instanceof MetadataCallable
                    || $referenced->canonicalName !== $callable->canonicalName
                    || $referenced->package !== $metadataTest->package
                    || $referenced->source !== $metadataTest->source
                ) {
                    throw $this->invalid('A test has an invalid callable reference.');
                }
            }
            if ($metadataTest->executable !== ($callable !== null && $shapeIssue === null)) {
                throw $this->invalid('Test executable and shape fields are inconsistent.');
            }
            if (($metadataTest->origin === 'attribute') !== ($spelling === null)) {
                throw $this->invalid('Test origin and authored spelling are inconsistent.');
            }
            if ($metadataTest->displayName !== implode(' > ', $metadataTest->pathSegments)) {
                throw $this->invalid('A test display name does not match its path.');
            }
            $tests[] = $metadataTest;
            $testFacts[$identity] = true;
        }

        return new MetadataDocumentV3(
            $document['edition'],
            $document['compilerRevision'],
            $document['graphFingerprint'],
            $selectedTarget,
            $this->objects($document['sources'] ?? null),
            $attributeClasses,
            $applications,
            $callables,
            $suites,
            $tests,
        );
    }

    /** @return list<MetadataCallable> */
    private function callables(mixed $value): array
    {
        $callables = [];
        foreach ($this->objects($value) as $callable) {
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

        return $callables;
    }

    private function location(mixed $value): MetadataLocation
    {
        $location = $this->object($value);
        $this->keys($location, ['source', 'displayPath', 'byteStart', 'byteEnd']);
        if (!is_string($location['source'] ?? null)
            || !is_string($location['displayPath'] ?? null)
            || !is_int($location['byteStart'] ?? null)
            || !is_int($location['byteEnd'] ?? null)
            || $location['byteStart'] < 0
            || $location['byteEnd'] < $location['byteStart']
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

    /** @return array<string, true> */
    private function packageFacts(mixed $value): array
    {
        $facts = [];
        foreach ($this->objects($value) as $package) {
            $this->keys($package, ['identity']);
            $identity = $package['identity'] ?? null;
            if (!is_string($identity) || $identity === '' || isset($facts[$identity])) {
                throw $this->invalid('Metadata package identities are invalid or duplicated.');
            }
            $facts[$identity] = true;
        }

        return $facts;
    }

    /** @param array<string, true> $packages
     *  @return array<string, array{package: string, byteLength: int}>
     */
    private function sourceFacts(mixed $value, array $packages): array
    {
        $facts = [];
        foreach ($this->objects($value) as $source) {
            $this->keys($source, ['identity', 'package', 'displayPath', 'byteLength']);
            $identity = $source['identity'] ?? null;
            $package = $source['package'] ?? null;
            $displayPath = $source['displayPath'] ?? null;
            if (!is_string($identity) || $identity === ''
                || !is_string($package) || $package === ''
                || !is_string($displayPath) || $displayPath === ''
            ) {
                throw $this->invalid('Metadata source identity, package, or display path is invalid.');
            }
            if (!is_int($source['byteLength'] ?? null) || $source['byteLength'] < 0
                || !isset($packages[$package]) || isset($facts[$identity])
            ) {
                throw $this->invalid('Metadata source package, length, or identity is invalid.');
            }
            $facts[$identity] = [
                'package' => $package,
                'byteLength' => $source['byteLength'],
            ];
        }

        return $facts;
    }

    /**
     * @param array<string, true> $packages
     * @param array<string, array{package: string, byteLength: int}> $sources
     * @return array<string, mixed>
     */
    private function strictTarget(mixed $value, array $packages, array $sources): array
    {
        $target = $this->object($value);
        $this->keys($target, ['package', 'kind', 'entrySource']);
        $package = $target['package'] ?? null;
        $kind = $target['kind'] ?? null;
        $entrySource = $target['entrySource'] ?? null;
        if (!is_string($package) || !isset($packages[$package])
            || !is_string($kind) || !in_array($kind, ['binary', 'library'], true)
            || !(is_string($entrySource) || $entrySource === null)
            || ($entrySource !== null && ($sources[$entrySource]['package'] ?? null) !== $package)
        ) {
            throw $this->invalid('Metadata selected target is invalid.');
        }

        return $target;
    }

    /**
     * @param array<string, array{package: string, byteLength: int}> $sources
     * @return list<array<string, mixed>>
     */
    private function strictAttributeClasses(mixed $value, array $sources): array
    {
        $classes = $this->objects($value);
        $identities = [];
        foreach ($classes as $class) {
            $this->keys($class, ['identity', 'canonicalName', 'package', 'source', 'parameters', 'location']);
            foreach (['identity', 'canonicalName', 'package'] as $field) {
                if (!is_string($class[$field] ?? null) || $class[$field] === '') {
                    throw $this->invalid("Metadata attribute-class field `{$field}` is invalid.");
                }
            }
            $identity = $class['identity'];
            $source = $class['source'] ?? null;
            if (isset($identities[$identity]) || !(is_string($source) || $source === null)) {
                throw $this->invalid('Metadata attribute-class identity or source is invalid.');
            }
            foreach ($this->objects($class['parameters'] ?? null) as $parameter) {
                $this->keys($parameter, ['index', 'name', 'type', 'hasDefault']);
                if (!is_int($parameter['index'] ?? null) || $parameter['index'] < 0
                    || !is_string($parameter['name'] ?? null) || $parameter['name'] === ''
                    || !is_string($parameter['type'] ?? null) || $parameter['type'] === ''
                    || !is_bool($parameter['hasDefault'] ?? null)
                ) {
                    throw $this->invalid('Metadata attribute-class parameter is invalid.');
                }
            }
            $locationValue = $class['location'] ?? null;
            if ($source === null) {
                if ($locationValue !== null) {
                    throw $this->invalid('Compiler-known attribute metadata cannot carry a source location.');
                }
            } else {
                if (!isset($sources[$source])) {
                    throw $this->invalid('Metadata attribute class references an unknown source.');
                }
                $this->locationMatches($this->location($locationValue), $source, $sources);
            }
            $identities[$identity] = true;
        }

        return $classes;
    }

    /**
     * @param array<string, true> $packages
     * @param array<string, array{package: string, byteLength: int}> $sources
     * @param list<array<string, mixed>> $attributeClasses
     * @return list<array<string, mixed>>
     */
    private function strictApplications(
        mixed $value,
        array $packages,
        array $sources,
        array $attributeClasses,
    ): array {
        $knownAttributes = [];
        foreach ($attributeClasses as $class) {
            if (is_string($class['identity'] ?? null)) {
                $knownAttributes[$class['identity']] = true;
            }
        }
        $applications = $this->objects($value);
        $identities = [];
        foreach ($applications as $application) {
            $this->keys($application, [
                'identity', 'attributeClass', 'target', 'source', 'package', 'groupOrdinal',
                'applicationOrdinal', 'authoredArguments', 'boundArguments', 'location',
            ]);
            foreach (['identity', 'attributeClass', 'target', 'source', 'package'] as $field) {
                if (!is_string($application[$field] ?? null) || $application[$field] === '') {
                    throw $this->invalid("Metadata application field `{$field}` is invalid.");
                }
            }
            $identity = $application['identity'];
            $attribute = $application['attributeClass'];
            $source = $application['source'];
            $package = $application['package'];
            if (isset($identities[$identity]) || !isset($knownAttributes[$attribute])
                || !is_int($application['groupOrdinal'] ?? null) || $application['groupOrdinal'] < 0
                || !is_int($application['applicationOrdinal'] ?? null) || $application['applicationOrdinal'] < 0
            ) {
                throw $this->invalid('Metadata application identity, attribute, or ordinal is invalid.');
            }
            $this->knownPackageAndSource($package, $source, $packages, $sources);
            $this->locationMatches($this->location($application['location'] ?? null), $source, $sources);
            foreach ($this->objects($application['authoredArguments'] ?? null) as $argument) {
                $this->keys($argument, ['index', 'name', 'boundParameterIndex']);
                $name = $argument['name'] ?? null;
                if (!is_int($argument['index'] ?? null) || $argument['index'] < 0
                    || !(is_string($name) || $name === null)
                    || !is_int($argument['boundParameterIndex'] ?? null) || $argument['boundParameterIndex'] < 0
                ) {
                    throw $this->invalid('Metadata authored attribute argument is invalid.');
                }
            }
            foreach ($this->objects($application['boundArguments'] ?? null) as $argument) {
                $this->keys($argument, [
                    'parameterIndex', 'parameterName', 'type', 'value', 'defaulted', 'authoredArgumentIndex',
                ]);
                $authoredIndex = $argument['authoredArgumentIndex'] ?? null;
                if (!is_int($argument['parameterIndex'] ?? null) || $argument['parameterIndex'] < 0
                    || !is_string($argument['parameterName'] ?? null) || $argument['parameterName'] === ''
                    || !is_string($argument['type'] ?? null) || $argument['type'] === ''
                    || !is_bool($argument['defaulted'] ?? null)
                    || !(is_int($authoredIndex) || $authoredIndex === null)
                    || (is_int($authoredIndex) && $authoredIndex < 0)
                ) {
                    throw $this->invalid('Metadata bound attribute argument is invalid.');
                }
                $this->strictValue($argument['value'] ?? null);
            }
            $identities[$identity] = true;
        }

        return $applications;
    }

    private function strictValue(mixed $value): void
    {
        $value = $this->object($value);
        $kind = $value['kind'] ?? null;
        if (!is_string($kind)) {
            throw $this->invalid('Metadata attribute value kind is invalid.');
        }
        $expected = match ($kind) {
            'integer', 'float', 'string' => ['kind', 'type', 'value'],
            'bool' => ['kind', 'type', 'value'],
            'null' => ['kind', 'type'],
            'enum' => ['kind', 'type', 'case'],
            'payloadEnum' => ['kind', 'type', 'case', 'fields'],
            default => throw $this->invalid('Metadata attribute value kind is unknown.'),
        };
        $this->keys($value, $expected);
        if (!is_string($value['type'] ?? null) || $value['type'] === '') {
            throw $this->invalid('Metadata attribute value type is invalid.');
        }
        if (in_array($kind, ['integer', 'float', 'string'], true)
            && !is_string($value['value'] ?? null)
        ) {
            throw $this->invalid('Metadata scalar attribute value is invalid.');
        }
        if ($kind === 'bool' && !is_bool($value['value'] ?? null)) {
            throw $this->invalid('Metadata bool attribute value is invalid.');
        }
        if (in_array($kind, ['enum', 'payloadEnum'], true)
            && (!is_string($value['case'] ?? null) || $value['case'] === '')
        ) {
            throw $this->invalid('Metadata enum attribute value is invalid.');
        }
        if ($kind === 'payloadEnum') {
            foreach ($this->objects($value['fields'] ?? null) as $field) {
                $this->strictValue($field);
            }
        }
    }

    /**
     * @param array<string, true> $packages
     * @param array<string, array{package: string, byteLength: int}> $sources
     */
    private function knownPackageAndSource(string $package, string $source, array $packages, array $sources): void
    {
        if (!isset($packages[$package])) {
            throw $this->invalid("Metadata references unknown package `{$package}`.");
        }
        if (!isset($sources[$source])) {
            throw $this->invalid("Metadata references unknown source `{$source}`.");
        }
        if ($sources[$source]['package'] !== $package) {
            throw $this->invalid("Metadata source `{$source}` does not belong to package `{$package}`.");
        }
    }

    /** @param array<string, array{package: string, byteLength: int}> $sources */
    private function locationMatches(MetadataLocation $location, string $source, array $sources): void
    {
        if ($location->source !== $source || $location->byteEnd > ($sources[$source]['byteLength'] ?? -1)) {
            throw $this->invalid('Metadata location does not match its source.');
        }
    }

    /** @param array<string, array{package: string, byteLength: int}> $sources */
    private function testLocationsMatch(MetadataTestSuite|MetadataTest $record, string $source, array $sources): void
    {
        $this->locationMatches($record->location, $source, $sources);
        $this->locationMatches($record->callNameLocation, $source, $sources);
        $this->locationMatches($record->descriptionLocation, $source, $sources);
    }

    private function testCallable(mixed $value): ?MetadataTestCallable
    {
        if ($value === null) {
            return null;
        }
        $callable = $this->object($value);
        $this->keys($callable, ['identity', 'canonicalName']);
        if (!is_string($callable['identity'] ?? null) || $callable['identity'] === ''
            || !is_string($callable['canonicalName'] ?? null) || $callable['canonicalName'] === ''
        ) {
            throw $this->invalid('Test callable fields are invalid.');
        }

        return new MetadataTestCallable($callable['identity'], $callable['canonicalName']);
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

    /** @return list<string> */
    private function nonemptyStrings(mixed $value, string $message): array
    {
        $strings = $this->strings($value);
        if ($strings === [] || in_array('', $strings, true)) {
            throw $this->invalid($message);
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
