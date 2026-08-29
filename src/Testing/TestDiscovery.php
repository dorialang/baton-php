<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

use Doria\Baton\Compiler\MetadataCallable;
use Doria\Baton\Compiler\MetadataDocumentV2;
use Doria\Baton\Diagnostics\BatonError;

final class TestDiscovery
{
    /** @return list<ExecutableTest> */
    public function discover(
        MetadataDocumentV2 $metadata,
        string $package,
        ?string $filter,
    ): array {
        $callables = [];
        foreach ($metadata->callables as $callable) {
            $callables[$callable->identity] = $callable;
        }
        $tests = [];
        foreach ($metadata->applications as $application) {
            if (($application['attributeClass'] ?? null) !== 'compiler-known:Test'
                || ($application['package'] ?? null) !== $package
            ) {
                continue;
            }
            $target = $application['target'] ?? null;
            $callable = is_string($target) ? ($callables[$target] ?? null) : null;
            if (!$callable instanceof MetadataCallable) {
                throw $this->invalid(
                    'Test Attribute Target Is Unresolved',
                    'A #[Test] application does not resolve to callable metadata.',
                    $application,
                );
            }
            $problem = $this->shapeProblem($callable, $package);
            if ($problem !== null) {
                throw $this->invalid('Test Function Is Not Executable', $problem, $application);
            }
            if ($filter !== null && !str_contains($callable->canonicalName, $filter)) {
                continue;
            }
            $tests[] = new ExecutableTest(
                $callable->identity,
                $callable->canonicalName,
                $callable->source,
                $callable->requiredEffects,
                $callable->location,
            );
        }
        usort($tests, static fn (ExecutableTest $left, ExecutableTest $right): int => [
            $left->canonicalName,
            $left->source,
            $left->location->byteStart,
        ] <=> [
            $right->canonicalName,
            $right->source,
            $right->location->byteStart,
        ]);
        return $tests;
    }

    private function shapeProblem(MetadataCallable $callable, string $package): ?string
    {
        return match (true) {
            $callable->package !== $package => 'The test target belongs to another package.',
            $callable->kind !== 'function' => 'Only top-level free functions may be executed as tests.',
            $callable->genericParameterCount !== 0 => 'Test functions cannot declare generic parameters.',
            $callable->parameters !== [] => 'Test functions cannot declare parameters.',
            $callable->returnType !== 'void' => 'Test functions must return exactly `void`.',
            !in_array($callable->access, ['external', 'internal'], true) => 'The test function has an unsupported access mode.',
            default => null,
        };
    }

    /** @param array<string, mixed> $application */
    private function invalid(string $heading, string $detail, array $application): BatonError
    {
        $location = $application['location'] ?? null;
        $where = '';
        if (is_array($location)
            && is_string($location['displayPath'] ?? null)
            && is_int($location['byteStart'] ?? null)
        ) {
            $where = "\n\nAt {$location['displayPath']}:byte {$location['byteStart']}";
        }

        return new BatonError('B0421', $heading, $detail . $where);
    }
}
