<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Testing\RuntimeOutcomeInvalid;
use Doria\Baton\Testing\RuntimeOutcomeReader;
use Doria\Baton\Testing\TestOutcomeCategory;
use Doria\Baton\Tests\TestCase;

final class RuntimeOutcomeReaderTest extends TestCase
{
    public function testDecodesEverySupportedOutcomeVersionIntoTypedFacts(): void
    {
        $reader = new RuntimeOutcomeReader();

        $panic = $reader->decode($this->panicRecord('P1204', [['count', 1, 3]]));
        self::assertSame(2, $panic->version);
        self::assertSame(TestOutcomeCategory::FatalPanic, $panic->category);
        self::assertSame('P1204', $panic->diagnosticCode);
        self::assertSame([['name' => 'count', 'value' => 3]], $panic->facts);

        $signed = $reader->decode($this->panicRecord('P1111', [['status', 1, -42]]));
        self::assertSame([['name' => 'status', 'value' => -42]], $signed->facts);

        $error = $reader->decode($this->errorRecord());
        self::assertSame(3, $error->version);
        self::assertSame(TestOutcomeCategory::UnexpectedCheckedError, $error->category);
        self::assertSame('Acme\\ParseError', $error->errorType);
        self::assertNotNull($error->origin);
        self::assertSame(1, $error->origin['line']);
        self::assertSame(1, $error->origin['column']);

        $assertion = $reader->decode($this->assertionRecord());
        self::assertSame(4, $assertion->version);
        self::assertSame(TestOutcomeCategory::AssertionFailed, $assertion->category);
        self::assertSame('CollectionCount', $assertion->matcher);
        self::assertSame('toHaveCount', $assertion->matcherSourceName());
        self::assertSame('Expected Count: 3', $assertion->difference);
        self::assertSame(['type' => 'int', 'presentation' => '3'], $assertion->expected);
    }

    public function testPreservesCompilerOwnedDiagnosticsMatchersAndFacts(): void
    {
        $reader = new RuntimeOutcomeReader();
        $panic = $reader->decode($this->panicRecord('P9999', [['futureFact', 4, 'value']]));
        self::assertSame('P9999', $panic->diagnosticCode);
        self::assertSame([['name' => 'futureFact', 'value' => 'value']], $panic->facts);

        $assertion = $reader->decode($this->assertionRecord('FutureMatcher'));
        self::assertSame('FutureMatcher', $assertion->matcher);
        self::assertSame('FutureMatcher', $assertion->matcherSourceName());
    }

    public function testRejectsMalformedUnsupportedAndOversizedRecords(): void
    {
        $reader = new RuntimeOutcomeReader();
        $cases = [
            'unknown magic' => "DORIAO9\0" . pack('v', 9),
            'unknown version' => substr_replace($this->errorRecord(), pack('v', 9), 8, 2),
            'truncated' => substr($this->assertionRecord(), 0, -1),
            'invalid flag' => substr_replace($this->assertionRecord(), "\x02", 23, 1),
            'empty matcher' => $this->assertionRecord(''),
            'empty panic code' => $this->panicRecord(''),
            'trailing bytes' => $this->panicRecord() . 'x',
            'invalid UTF-8' => $this->assertionRecord(actual: ['string', "\xff"]),
            'assertion Error in V3' => $this->errorRecord('Doria\\Std\\Test\\AssertionError'),
            'empty fact name' => $this->panicRecord('P9999', [['', 4, 'value']]),
        ];
        foreach ($cases as $name => $bytes) {
            try {
                $reader->decode($bytes);
                self::fail("{$name} must be rejected.");
            } catch (RuntimeOutcomeInvalid) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRetainsTerminalControlsLogically(): void
    {
        $message = "bad\x1b[31m\0value";
        $outcome = (new RuntimeOutcomeReader())->decode($this->errorRecord(message: $message));

        self::assertSame($message, $outcome->message);
    }

    /** @param list<array{string, int<0, 255>, int|string|bool}> $facts */
    private function panicRecord(string $code = 'P1000', array $facts = []): string
    {
        $message = $code === 'P1000' ? 'boom' : '';
        $path = 'tests/Test.doria';
        $source = "panic();\n";
        $function = 'test';
        $bytes = "DORIAO2\0"
            . pack('v', 2)
            . pack('v', strlen($code))
            . pack('V', strlen($message))
            . pack('V', strlen($path))
            . pack('V', strlen($source))
            . pack('v', strlen($function))
            . pack('v', 0)
            . pack('v', count($facts))
            . pack('P', 0)
            . pack('P', 5)
            . $code
            . $message
            . $path
            . $source
            . $function;
        foreach ($facts as [$name, $kind, $value]) {
            $text = $kind === 4 ? (string) $value : '';
            $scalar = $kind === 3 ? (int) (bool) $value : (is_int($value) ? $value : 0);
            $bytes .= pack('v', strlen($name))
                . chr($kind)
                . pack('P', $scalar)
                . pack('V', strlen($text))
                . $name
                . $text;
        }

        return $bytes;
    }

    private function errorRecord(
        string $errorType = 'Acme\\ParseError',
        string $message = 'invalid input',
    ): string {
        $path = 'tests/Test.doria';
        $source = "throw error;\n";
        $function = 'test';

        return "DORIAO3\0"
            . pack('v', 3)
            . pack('V', strlen($errorType))
            . pack('P', strlen($message))
            . pack('V', strlen($path))
            . pack('V', strlen($source))
            . pack('V', strlen($function))
            . "\x01"
            . pack('P', 0)
            . pack('P', 5)
            . $errorType
            . $message
            . $path
            . $source
            . $function;
    }

    /**
     * @param array{string, string}|null $actual
     * @param array{string, string}|null $expected
     */
    private function assertionRecord(
        string $matcher = 'CollectionCount',
        ?array $actual = ['List<int>', 'List<int>(count: 2) [1, 2]'],
        ?array $expected = ['int', '3'],
        ?string $difference = 'Expected Count: 3',
        ?string $userMessage = null,
    ): string {
        $errorType = 'Doria\\Std\\Test\\AssertionError';
        $path = 'tests/Test.doria';
        $source = "expect(2);\n";
        $function = 'test';
        [$actualType, $actualPresentation] = $actual ?? ['', ''];
        [$expectedType, $expectedPresentation] = $expected ?? ['', ''];
        $differenceText = $difference ?? '';
        $messageText = $userMessage ?? '';
        $bytes = "DORIAO4\0"
            . pack('v', 4)
            . pack('V', strlen($errorType))
            . pack('V', 70)
            . "\x01"
            . pack('V', strlen($matcher))
            . "\x00"
            . chr((int) ($actual !== null))
            . pack('V', strlen($actualType))
            . pack('V', strlen($actualPresentation))
            . chr((int) ($expected !== null))
            . pack('V', strlen($expectedType))
            . pack('V', strlen($expectedPresentation))
            . chr((int) ($difference !== null))
            . pack('V', strlen($differenceText))
            . chr((int) ($userMessage !== null))
            . pack('V', strlen($messageText))
            . pack('V', strlen($path))
            . pack('V', strlen($source))
            . pack('V', strlen($function))
            . "\x01"
            . pack('P', 0)
            . pack('P', 6)
            . pack('v', 0)
            . $errorType
            . $matcher
            . $actualType
            . $actualPresentation
            . $expectedType
            . $expectedPresentation
            . $differenceText
            . $messageText
            . $path
            . $source
            . $function;

        return $bytes;
    }
}
