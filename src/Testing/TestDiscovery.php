<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

use Doria\Baton\Compiler\MetadataCallable;
use Doria\Baton\Compiler\MetadataDocumentV3;
use Doria\Baton\Compiler\MetadataTest;
use Doria\Baton\Compiler\MetadataTestSuite;
use Doria\Baton\Diagnostics\BatonError;

final class TestDiscovery
{
    /** @return list<ExecutableTest> */
    public function discover(
        MetadataDocumentV3 $metadata,
        string $package,
        ?string $filter,
    ): array {
        $callables = [];
        foreach ($metadata->callables as $callable) {
            $callables[$callable->identity] = $callable;
        }
        $sources = [];
        foreach ($metadata->sources as $source) {
            if (is_string($source['identity'] ?? null)) {
                $sources[$source['identity']] = true;
            }
        }
        $suites = [];
        foreach ($metadata->testSuites as $suite) {
            $suites[$suite->identity] = $suite;
        }
        $tests = [];
        $identities = [];
        foreach ($metadata->tests as $test) {
            if ($test->package !== $package) {
                continue;
            }
            if (isset($identities[$test->identity])) {
                throw $this->invalid('Test Metadata Is Invalid', 'A test identity is duplicated.', $test);
            }
            $identities[$test->identity] = true;
            if (!$test->executable || $test->shapeIssue !== null) {
                throw $this->invalid(
                    'Test Function Is Not Executable',
                    $this->shapeProblem($test->shapeIssue),
                    $test,
                );
            }
            $callableReference = $test->callable;
            $callable = $callableReference === null ? null : ($callables[$callableReference->identity] ?? null);
            $suite = $test->suite === null ? null : ($suites[$test->suite] ?? null);
            if (!$callable instanceof MetadataCallable
                || $callable->canonicalName !== $callableReference->canonicalName
                || !isset($sources[$test->source])
                || ($test->suite !== null && !$suite instanceof MetadataTestSuite)
                || $test->displayName === ''
            ) {
                throw $this->invalid(
                    'Test Metadata Is Invalid',
                    'An executable test does not resolve to complete compiler metadata.',
                    $test,
                );
            }
            if ($filter !== null && !str_contains($test->displayName, $filter)) {
                continue;
            }
            $tests[] = new ExecutableTest(
                $test->identity,
                $test->displayName,
                $callable->identity,
                $callable->canonicalName,
                $test->origin,
                $test->authoredSpelling,
                $test->suite,
                $test->pathSegments,
                $test->source,
                $callable->requiredEffects,
                $callable->ambientEffects,
                $test->location,
            );
        }
        usort($tests, static fn (ExecutableTest $left, ExecutableTest $right): int => [
            $left->displayName,
            $left->source,
            $left->location->byteStart,
            $left->identity,
        ] <=> [
            $right->displayName,
            $right->source,
            $right->location->byteStart,
            $right->identity,
        ]);
        return $tests;
    }

    private function shapeProblem(?string $issue): string
    {
        return match ($issue) {
            'targetIsNotCallable' => 'The #[Test] target does not resolve to callable metadata.',
            'callableIsNotAFunction' => 'Only top-level free functions may be executed as tests.',
            'functionIsGeneric' => 'Test functions cannot declare generic parameters.',
            'functionHasParameters' => 'Test functions cannot declare parameters.',
            'functionDoesNotReturnVoid' => 'Test functions must return exactly `void`.',
            'unsupportedAccess' => 'The test function has an unsupported access mode.',
            default => 'The compiler marked this test as non-executable.',
        };
    }

    private function invalid(string $heading, string $detail, MetadataTest $test): BatonError
    {
        $where = "\n\nAt {$test->location->displayPath}:byte {$test->location->byteStart}";

        return new BatonError('B0421', $heading, $detail . $where);
    }
}
