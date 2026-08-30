<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

use Doria\Baton\Process\BoundedProcessResult;

final readonly class TestExecutionResult
{
    public function __construct(
        public TestOutcomeCategory $category,
        public BoundedProcessResult $process,
        public ?RuntimeOutcome $outcome = null,
        public ?string $infrastructureDetail = null,
    ) {
    }

    public static function classify(
        BoundedProcessResult $process,
        ?RuntimeOutcome $outcome,
        ?string $outcomeError,
    ): self {
        if ($process->timedOut) {
            return self::abnormal($process, 'Test process exceeded its time limit.');
        }
        if ($process->outputLimitStream !== null) {
            return self::abnormal(
                $process,
                "Test process exceeded the {$process->outputLimitStream} retention limit.",
            );
        }
        if ($process->signaled) {
            return self::abnormal(
                $process,
                'Test process terminated by signal ' . ($process->signal ?? 'unknown') . '.',
            );
        }
        if ($outcomeError !== null) {
            return self::abnormal($process, 'Runtime outcome is invalid: ' . $outcomeError);
        }
        if ($outcome === null) {
            if ($process->exitCode === 0) {
                return new self(TestOutcomeCategory::Passed, $process);
            }

            return self::abnormal(
                $process,
                'Test process exited with status ' . ($process->exitCode ?? 'unknown') . ' without a runtime outcome.',
            );
        }
        if ($process->exitCode !== $outcome->processStatus) {
            return self::abnormal(
                $process,
                'Runtime outcome expects status '
                    . $outcome->processStatus
                    . ', but the process reported '
                    . ($process->exitCode ?? 'unknown')
                    . '.',
                $outcome,
            );
        }

        return new self($outcome->category, $process, $outcome);
    }

    private static function abnormal(
        BoundedProcessResult $process,
        string $detail,
        ?RuntimeOutcome $outcome = null,
    ): self {
        return new self(TestOutcomeCategory::AbnormalProcessFailure, $process, $outcome, $detail);
    }
}
