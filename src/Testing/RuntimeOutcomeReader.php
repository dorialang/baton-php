<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

final class RuntimeOutcomeReader
{
    public const MAX_RECORD_BYTES = 8_388_608;

    private const ASSERTION_ERROR = 'Doria\\Std\\Test\\AssertionError';

    /** @var list<string> */
    private const MATCHERS = [
        'Equal',
        'Null',
        'True',
        'False',
        'GreaterThan',
        'GreaterThanOrEqual',
        'LessThan',
        'LessThanOrEqual',
        'StringContains',
        'StringStartsWith',
        'StringEndsWith',
        'StringEmpty',
        'CollectionContains',
        'CollectionEmpty',
        'CollectionCount',
        'DictionaryHasKey',
        'DictionaryHasValue',
        'Throws',
        'Fail',
    ];

    /** @var array<string, list<string>> */
    private const PANIC_FACTS = [
        'P1000' => [],
        'P1001' => [],
        'P1101' => [],
        'P1102' => [],
        'P1103' => [],
        'P1104' => [],
        'P1105' => [],
        'P1106' => [],
        'P1107' => [],
        'P1108' => [],
        'P1109' => [],
        'P1110' => [],
        'P1111' => ['status'],
        'P1201' => ['length'],
        'P1202' => ['requestedLength'],
        'P1203' => [
            'operation',
            'value',
            'currentGraphemeLength',
            'requestedGraphemeLength',
            'paddingGraphemeLength',
        ],
        'P1204' => ['count'],
        'P1205' => [],
        'P1206' => [],
        'P1301' => ['index', 'length'],
        'P1302' => [],
        'P1310' => ['index', 'length'],
        'P1311' => ['count'],
        'P1312' => [],
        'P1313' => [],
        'P1320' => [],
        'P1321' => [],
        'P1322' => [],
        'P1401' => [],
        'P1402' => [],
        'P1403' => [],
        'P1404' => [],
        'P1405' => [],
        'P1406' => [],
        'P1407' => [],
        'P1410' => [],
        'P1501' => ['conflictReason'],
        'P1502' => [],
        'P1503' => [],
        'P1504' => [],
        'P1505' => [],
        'P1601' => [],
    ];

    public function read(string $path): RuntimeOutcome
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeOutcomeInvalid('Runtime outcome could not be opened.');
        }
        try {
            $bytes = stream_get_contents($handle, self::MAX_RECORD_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($bytes)) {
            throw new RuntimeOutcomeInvalid('Runtime outcome could not be read.');
        }
        if (strlen($bytes) > self::MAX_RECORD_BYTES) {
            throw new RuntimeOutcomeInvalid('Runtime outcome exceeds the retained transport limit.');
        }

        return $this->decode($bytes);
    }

    public function decode(string $bytes): RuntimeOutcome
    {
        if (strlen($bytes) > self::MAX_RECORD_BYTES) {
            throw new RuntimeOutcomeInvalid('Runtime outcome exceeds the retained transport limit.');
        }
        if (str_starts_with($bytes, "DORIAO2\0")) {
            return $this->panic($bytes);
        }
        if (str_starts_with($bytes, "DORIAO3\0")) {
            return $this->error($bytes);
        }
        if (str_starts_with($bytes, "DORIAO4\0")) {
            return $this->assertion($bytes);
        }

        throw new RuntimeOutcomeInvalid('Runtime outcome has unknown magic.');
    }

    private function panic(string $bytes): RuntimeOutcome
    {
        $cursor = new RuntimeOutcomeCursor($bytes);
        $this->header($cursor, "DORIAO2\0", 2);
        $codeLength = $cursor->u16();
        $messageLength = $cursor->u32();
        $pathLength = $cursor->u32();
        $sourceLength = $cursor->u32();
        $functionLength = $cursor->u16();
        $frameCount = $cursor->u16();
        $factCount = $cursor->u16();
        $spanStart = $cursor->u64();
        $spanEnd = $cursor->u64();
        $this->bounded($codeLength, 16, 'panic code');
        $this->bounded($messageLength, 65_536, 'panic message');
        $this->bounded($pathLength, 4096, 'panic path');
        $this->bounded($sourceLength, 4_194_304, 'panic source');
        $this->bounded($functionLength, 1024, 'panic function');
        $this->bounded($frameCount, 128, 'panic frame count');
        $this->bounded($factCount, 32, 'panic fact count');

        $code = $cursor->text($codeLength);
        $message = $cursor->text($messageLength);
        $path = $cursor->text($pathLength);
        $source = $cursor->text($sourceLength);
        $function = $cursor->text($functionLength);
        $expectedFacts = self::PANIC_FACTS[$code] ?? null;
        if ($expectedFacts === null) {
            throw new RuntimeOutcomeInvalid('Runtime panic uses an unknown diagnostic code.');
        }
        $facts = [];
        for ($index = 0; $index < $factCount; ++$index) {
            $nameLength = $cursor->u16();
            $kind = $cursor->byte();
            $rawValue = $cursor->take(8);
            $valueLength = $cursor->u32();
            $this->bounded($nameLength, 1024, 'panic fact name');
            $this->bounded($valueLength, 65_536, 'panic fact value');
            $name = $cursor->text($nameLength);
            $value = match ($kind) {
                1 => $valueLength === 0
                    ? RuntimeOutcomeCursor::signed64($rawValue)
                    : throw new RuntimeOutcomeInvalid('Runtime panic has a malformed signed fact.'),
                2 => $valueLength === 0
                    ? RuntimeOutcomeCursor::unsigned64($rawValue)
                    : throw new RuntimeOutcomeInvalid('Runtime panic has a malformed unsigned fact.'),
                3 => $valueLength === 0 && RuntimeOutcomeCursor::unsigned64($rawValue) <= 1
                    ? RuntimeOutcomeCursor::unsigned64($rawValue) === 1
                    : throw new RuntimeOutcomeInvalid('Runtime panic has a malformed boolean fact.'),
                4 => $cursor->text($valueLength),
                default => throw new RuntimeOutcomeInvalid('Runtime panic has an unknown fact type.'),
            };
            $facts[] = ['name' => $name, 'value' => $value];
        }
        if (array_column($facts, 'name') !== $expectedFacts) {
            throw new RuntimeOutcomeInvalid('Runtime panic facts do not match the diagnostic catalogue.');
        }
        $this->validatePanicFactValues($code, $facts);
        $frames = $this->frames($cursor, $frameCount);
        $cursor->finish();

        return new RuntimeOutcome(
            2,
            TestOutcomeCategory::FatalPanic,
            101,
            'abortWithoutCleanup',
            diagnosticCode: $code,
            message: $message,
            origin: $this->origin($path, $source, $function, $spanStart, $spanEnd),
            frames: $frames,
            facts: $facts,
        );
    }

    private function error(string $bytes): RuntimeOutcome
    {
        $cursor = new RuntimeOutcomeCursor($bytes);
        $this->header($cursor, "DORIAO3\0", 3);
        $errorTypeLength = $cursor->u32();
        $messageLength = $cursor->u64();
        $pathLength = $cursor->u32();
        $sourceLength = $cursor->u32();
        $functionLength = $cursor->u32();
        $originKnown = $cursor->flag();
        $spanStart = $cursor->u64();
        $spanEnd = $cursor->u64();
        $this->bounded($errorTypeLength, 4096, 'Error type');
        $this->bounded($pathLength, 4096, 'Error path');
        $this->bounded($sourceLength, 4_194_304, 'Error source');
        $this->bounded($functionLength, 1024, 'Error function');
        $this->bounded($messageLength, $cursor->remaining(), 'Error message');
        $errorType = $cursor->text($errorTypeLength);
        $message = $cursor->text($messageLength);
        if ($errorType === '' || $errorType === self::ASSERTION_ERROR) {
            throw new RuntimeOutcomeInvalid('Runtime Error has an invalid Error type.');
        }
        $origin = $this->optionalOrigin(
            $cursor,
            $originKnown,
            $pathLength,
            $sourceLength,
            $functionLength,
            $spanStart,
            $spanEnd,
            'Error',
        );
        $cursor->finish();

        return new RuntimeOutcome(
            3,
            TestOutcomeCategory::UnexpectedCheckedError,
            70,
            'propagateWithCleanup',
            diagnosticCode: 'R1000',
            errorType: $errorType,
            message: $message,
            origin: $origin,
        );
    }

    private function assertion(string $bytes): RuntimeOutcome
    {
        $cursor = new RuntimeOutcomeCursor($bytes);
        $this->header($cursor, "DORIAO4\0", 4);
        $errorTypeLength = $cursor->u32();
        if ($cursor->u32() !== 70 || $cursor->byte() !== 1) {
            throw new RuntimeOutcomeInvalid('Runtime assertion has an invalid termination contract.');
        }
        $matcherLength = $cursor->u32();
        $negated = $cursor->flag();
        $actualPresent = $cursor->flag();
        $actualTypeLength = $cursor->u32();
        $actualPresentationLength = $cursor->u32();
        $expectedPresent = $cursor->flag();
        $expectedTypeLength = $cursor->u32();
        $expectedPresentationLength = $cursor->u32();
        $differencePresent = $cursor->flag();
        $differenceLength = $cursor->u32();
        $userMessagePresent = $cursor->flag();
        $userMessageLength = $cursor->u32();
        $pathLength = $cursor->u32();
        $sourceLength = $cursor->u32();
        $functionLength = $cursor->u32();
        $originKnown = $cursor->flag();
        $spanStart = $cursor->u64();
        $spanEnd = $cursor->u64();
        $frameCount = $cursor->u16();
        foreach ([
            [$errorTypeLength, 4096, 'assertion Error type'],
            [$matcherLength, 64, 'assertion matcher'],
            [$actualTypeLength, 4096, 'assertion actual type'],
            [$actualPresentationLength, 4096, 'assertion actual presentation'],
            [$expectedTypeLength, 4096, 'assertion expected type'],
            [$expectedPresentationLength, 4096, 'assertion expected presentation'],
            [$differenceLength, 4096, 'assertion difference'],
            [$userMessageLength, 65_536, 'assertion message'],
            [$pathLength, 4096, 'assertion path'],
            [$sourceLength, 4_194_304, 'assertion source'],
            [$functionLength, 1024, 'assertion function'],
            [$frameCount, 128, 'assertion frame count'],
        ] as [$value, $limit, $name]) {
            $this->bounded($value, $limit, $name);
        }
        if ($this->absentHasValues($actualPresent, $actualTypeLength, $actualPresentationLength)
            || $this->absentHasValues($expectedPresent, $expectedTypeLength, $expectedPresentationLength)
            || $this->absentHasValues($differencePresent, $differenceLength)
            || $this->absentHasValues($userMessagePresent, $userMessageLength)
        ) {
            throw new RuntimeOutcomeInvalid('Runtime assertion contains values for absent facts.');
        }

        $errorType = $cursor->text($errorTypeLength);
        $matcher = $cursor->text($matcherLength);
        $actualType = $cursor->text($actualTypeLength);
        $actualPresentation = $cursor->text($actualPresentationLength);
        $expectedType = $cursor->text($expectedTypeLength);
        $expectedPresentation = $cursor->text($expectedPresentationLength);
        $difference = $cursor->text($differenceLength);
        $userMessage = $cursor->text($userMessageLength);
        if ($errorType !== self::ASSERTION_ERROR) {
            throw new RuntimeOutcomeInvalid('Runtime assertion has an invalid Error identity.');
        }
        if (!in_array($matcher, self::MATCHERS, true)) {
            throw new RuntimeOutcomeInvalid('Runtime assertion has an unknown matcher.');
        }
        if ($matcher === 'Fail'
            && ($negated || $actualPresent || $expectedPresent || !$userMessagePresent)
        ) {
            throw new RuntimeOutcomeInvalid('Explicit assertion failure has malformed facts.');
        }
        if ($matcher !== 'Fail' && !$actualPresent) {
            throw new RuntimeOutcomeInvalid('Runtime assertion has no actual value.');
        }
        foreach ([
            'Null' => ['null', 'null'],
            'True' => ['bool', 'true'],
            'False' => ['bool', 'false'],
            'StringEmpty' => ['string', '""'],
        ] as $fixedMatcher => [$fixedType, $fixedPresentation]) {
            if ($matcher === $fixedMatcher
                && (!$expectedPresent
                    || $expectedType !== $fixedType
                    || $expectedPresentation !== $fixedPresentation)
            ) {
                throw new RuntimeOutcomeInvalid('Runtime assertion has malformed fixed expectation facts.');
            }
        }
        $origin = $this->optionalOrigin(
            $cursor,
            $originKnown,
            $pathLength,
            $sourceLength,
            $functionLength,
            $spanStart,
            $spanEnd,
            'assertion',
            requireNoFramesWhenAbsent: $frameCount,
        );
        $frames = $this->frames($cursor, $frameCount);
        $cursor->finish();

        return new RuntimeOutcome(
            4,
            TestOutcomeCategory::AssertionFailed,
            70,
            'propagateWithCleanup',
            diagnosticCode: 'R1001',
            errorType: $errorType,
            matcher: $matcher,
            negated: $negated,
            actual: $actualPresent ? ['type' => $actualType, 'presentation' => $actualPresentation] : null,
            expected: $expectedPresent ? ['type' => $expectedType, 'presentation' => $expectedPresentation] : null,
            difference: $differencePresent ? $difference : null,
            userMessage: $userMessagePresent ? $userMessage : null,
            origin: $origin,
            frames: $frames,
        );
    }

    private function header(RuntimeOutcomeCursor $cursor, string $magic, int $version): void
    {
        if ($cursor->take(8) !== $magic || $cursor->u16() !== $version) {
            throw new RuntimeOutcomeInvalid('Runtime outcome has an unsupported protocol version.');
        }
    }

    private function bounded(int $value, int $limit, string $field): void
    {
        if ($value < 0 || $value > $limit) {
            throw new RuntimeOutcomeInvalid("Runtime outcome {$field} is oversized.");
        }
    }

    private function absentHasValues(bool $present, int ...$lengths): bool
    {
        if ($present) {
            return false;
        }
        foreach ($lengths as $length) {
            if ($length !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{name: string, value: int|bool|string}> $facts
     */
    private function validatePanicFactValues(string $code, array $facts): void
    {
        if ($code === 'P1501') {
            $allowed = [
                'Cannot Acquire Writable Access While Readonly Access Is Active',
                'Cannot Acquire Readonly Access While Writable Access Is Active',
                'Cannot Acquire Writable Access While Writable Access Is Active',
            ];
            if (!isset($facts[0]) || !in_array($facts[0]['value'], $allowed, true)) {
                throw new RuntimeOutcomeInvalid('Runtime panic has an invalid shared-access conflict reason.');
            }
        }
        if ($code === 'P1203'
            && (!isset($facts[0]) || !in_array($facts[0]['value'], ['padStart', 'padEnd'], true))
        ) {
            throw new RuntimeOutcomeInvalid('Runtime panic has an invalid string-padding operation.');
        }
    }

    /** @return list<array{path: string, byteStart: int, byteEnd: int, function: string}> */
    private function frames(RuntimeOutcomeCursor $cursor, int $count): array
    {
        $frames = [];
        for ($index = 0; $index < $count; ++$index) {
            $functionLength = $cursor->u16();
            $pathLength = $cursor->u32();
            $spanStart = $cursor->u64();
            $spanEnd = $cursor->u64();
            $this->bounded($functionLength, 1024, 'frame function');
            $this->bounded($pathLength, 4096, 'frame path');
            if ($spanStart > $spanEnd) {
                throw new RuntimeOutcomeInvalid('Runtime outcome has an invalid frame span.');
            }
            $frames[] = [
                'function' => $cursor->text($functionLength),
                'path' => $cursor->text($pathLength),
                'byteStart' => $spanStart,
                'byteEnd' => $spanEnd,
            ];
        }

        return $frames;
    }

    /** @return array{path: string, line: int, column: int, byteStart: int, byteEnd: int, function: string|null} */
    private function origin(
        string $path,
        string $source,
        ?string $function,
        int $spanStart,
        int $spanEnd,
    ): array {
        if ($spanStart > $spanEnd || $spanEnd > strlen($source)) {
            throw new RuntimeOutcomeInvalid('Runtime outcome has an invalid source span.');
        }
        $prefix = substr($source, 0, $spanStart);
        $line = substr_count($prefix, "\n") + 1;
        $lineStart = strrpos($prefix, "\n");
        $linePrefix = $lineStart === false ? $prefix : substr($prefix, $lineStart + 1);
        preg_match_all('/./us', $linePrefix, $characters);

        return [
            'path' => $path,
            'line' => $line,
            'column' => count($characters[0]) + 1,
            'byteStart' => $spanStart,
            'byteEnd' => $spanEnd,
            'function' => $function,
        ];
    }

    /** @return array{path: string, line: int, column: int, byteStart: int, byteEnd: int, function: string|null}|null */
    private function optionalOrigin(
        RuntimeOutcomeCursor $cursor,
        bool $known,
        int $pathLength,
        int $sourceLength,
        int $functionLength,
        int $spanStart,
        int $spanEnd,
        string $domain,
        int $requireNoFramesWhenAbsent = 0,
    ): ?array {
        if (!$known) {
            if ($pathLength !== 0
                || $sourceLength !== 0
                || $functionLength !== 0
                || $spanStart !== 0
                || $spanEnd !== 0
                || $requireNoFramesWhenAbsent !== 0
            ) {
                throw new RuntimeOutcomeInvalid("Runtime {$domain} has facts for an unavailable origin.");
            }

            return null;
        }

        return $this->origin(
            $cursor->text($pathLength),
            $cursor->text($sourceLength),
            $cursor->text($functionLength),
            $spanStart,
            $spanEnd,
        );
    }
}

final class RuntimeOutcomeCursor
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes)
    {
    }

    public function take(int $length): string
    {
        if ($length < 0 || $length > $this->remaining()) {
            throw new RuntimeOutcomeInvalid('Runtime outcome is truncated.');
        }
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    public function byte(): int
    {
        return ord($this->take(1));
    }

    public function flag(): bool
    {
        return match ($this->byte()) {
            0 => false,
            1 => true,
            default => throw new RuntimeOutcomeInvalid('Runtime outcome has an invalid presence flag.'),
        };
    }

    public function u16(): int
    {
        /** @var array{value: int} $value */
        $value = unpack('vvalue', $this->take(2));

        return $value['value'];
    }

    public function u32(): int
    {
        /** @var array{value: int} $value */
        $value = unpack('Vvalue', $this->take(4));

        return $value['value'];
    }

    public function u64(): int
    {
        return self::unsigned64($this->take(8));
    }

    public static function unsigned64(string $bytes): int
    {
        /** @var array{value: int} $value */
        $value = unpack('Pvalue', $bytes);
        if ($value['value'] < 0) {
            throw new RuntimeOutcomeInvalid('Runtime outcome integer exceeds the host transport range.');
        }

        return $value['value'];
    }

    public static function signed64(string $bytes): int
    {
        /** @var array{value: int} $value */
        $value = unpack('Pvalue', $bytes);

        return $value['value'];
    }

    public function text(int $length): string
    {
        $value = $this->take($length);
        if (preg_match('//u', $value) !== 1) {
            throw new RuntimeOutcomeInvalid('Runtime outcome contains invalid UTF-8.');
        }

        return $value;
    }

    public function remaining(): int
    {
        return strlen($this->bytes) - $this->offset;
    }

    public function finish(): void
    {
        if ($this->remaining() !== 0) {
            throw new RuntimeOutcomeInvalid('Runtime outcome contains trailing bytes.');
        }
    }
}
