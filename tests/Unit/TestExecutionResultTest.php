<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Process\BoundedProcessResult;
use Doria\Baton\Testing\RuntimeOutcome;
use Doria\Baton\Testing\TestExecutionResult;
use Doria\Baton\Testing\TestOutcomeCategory;
use Doria\Baton\Tests\TestCase;

final class TestExecutionResultTest extends TestCase
{
    public function testClassifiesOnlyExitZeroWithoutARecordAsPassed(): void
    {
        $passed = TestExecutionResult::classify(new BoundedProcessResult(0, '', ''), null, null);
        self::assertSame(TestOutcomeCategory::Passed, $passed->category);

        $missing = TestExecutionResult::classify(new BoundedProcessResult(70, '', ''), null, null);
        self::assertSame(TestOutcomeCategory::AbnormalProcessFailure, $missing->category);
        self::assertStringContainsString('without a runtime outcome', $missing->infrastructureDetail ?? '');
    }

    public function testClassifiesEveryTypedOutcomeOnlyWhenProcessStatusMatches(): void
    {
        foreach ([
            [TestOutcomeCategory::AssertionFailed, 4, 70, 'propagateWithCleanup'],
            [TestOutcomeCategory::UnexpectedCheckedError, 3, 70, 'propagateWithCleanup'],
            [TestOutcomeCategory::FatalPanic, 2, 101, 'abortWithoutCleanup'],
        ] as [$category, $version, $status, $termination]) {
            $outcome = new RuntimeOutcome($version, $category, $status, $termination);
            self::assertSame(
                $category,
                TestExecutionResult::classify(
                    new BoundedProcessResult($status, '', ''),
                    $outcome,
                    null,
                )->category,
            );
            self::assertSame(
                TestOutcomeCategory::AbnormalProcessFailure,
                TestExecutionResult::classify(
                    new BoundedProcessResult(1, '', ''),
                    $outcome,
                    null,
                )->category,
            );
        }
    }

    public function testPreservesEveryInfrastructureFailureCause(): void
    {
        $cases = [
            new BoundedProcessResult(null, '', '', true, 9),
            new BoundedProcessResult(null, '', '', false, null, true),
            new BoundedProcessResult(1, '', '', outputLimitStream: 'stderr'),
        ];
        foreach ($cases as $process) {
            self::assertSame(
                TestOutcomeCategory::AbnormalProcessFailure,
                TestExecutionResult::classify($process, null, null)->category,
            );
        }
        self::assertSame(
            TestOutcomeCategory::AbnormalProcessFailure,
            TestExecutionResult::classify(
                new BoundedProcessResult(70, '', ''),
                null,
                'invalid UTF-8',
            )->category,
        );
    }
}
