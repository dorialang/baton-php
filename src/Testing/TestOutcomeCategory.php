<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

enum TestOutcomeCategory: string
{
    case Passed = 'Passed';
    case AssertionFailed = 'Assertion Failed';
    case UnexpectedCheckedError = 'Unexpected Checked Error';
    case FatalPanic = 'Fatal Panic';
    case AbnormalProcessFailure = 'Abnormal Process Failure';

    public function passed(): bool
    {
        return $this === self::Passed;
    }
}
