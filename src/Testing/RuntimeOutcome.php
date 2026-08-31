<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

/**
 * @phpstan-type OutcomeValue array{type: string, presentation: string}
 * @phpstan-type OutcomeOrigin array{path: string, line: int, column: int, byteStart: int, byteEnd: int, function: string|null}
 * @phpstan-type OutcomeFrame array{path: string, byteStart: int, byteEnd: int, function: string}
 * @phpstan-type OutcomeFact array{name: string, value: int|bool|string}
 */
final readonly class RuntimeOutcome
{
    /**
     * @param OutcomeValue|null $actual
     * @param OutcomeValue|null $expected
     * @param OutcomeOrigin|null $origin
     * @param list<OutcomeFrame> $frames
     * @param list<OutcomeFact> $facts
     */
    public function __construct(
        public int $version,
        public TestOutcomeCategory $category,
        public int $processStatus,
        public string $terminationBehavior,
        public ?string $diagnosticCode = null,
        public ?string $errorType = null,
        public ?string $message = null,
        public ?string $matcher = null,
        public bool $negated = false,
        public ?array $actual = null,
        public ?array $expected = null,
        public ?string $difference = null,
        public ?string $userMessage = null,
        public ?array $origin = null,
        public array $frames = [],
        public array $facts = [],
    ) {
    }

    public function matcherSourceName(): ?string
    {
        return match ($this->matcher) {
            'Equal' => 'toEqual',
            'Null' => 'toBeNull',
            'True' => 'toBeTrue',
            'False' => 'toBeFalse',
            'GreaterThan' => 'toBeGreaterThan',
            'GreaterThanOrEqual' => 'toBeGreaterThanOrEqual',
            'LessThan' => 'toBeLessThan',
            'LessThanOrEqual' => 'toBeLessThanOrEqual',
            'StringContains', 'CollectionContains' => 'toContain',
            'StringStartsWith' => 'toStartWith',
            'StringEndsWith' => 'toEndWith',
            'StringEmpty', 'CollectionEmpty' => 'toBeEmpty',
            'CollectionCount' => 'toHaveCount',
            'DictionaryHasKey' => 'toHaveKey',
            'DictionaryHasValue' => 'toHaveValue',
            'Throws' => 'toThrow',
            'Fail' => 'fail',
            default => $this->matcher,
        };
    }
}
