<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Process\BoundedProcessRunner;
use Doria\Baton\Tests\TestCase;

final class BoundedProcessRunnerTest extends TestCase
{
    public function testPreservesNormalStatusAndSeparateStreams(): void
    {
        $result = $this->executeFixture('fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(7);');

        self::assertSame(7, $result->exitCode);
        self::assertSame('out', $result->stdout);
        self::assertSame('err', $result->stderr);
        self::assertFalse($result->signaled);
        self::assertFalse($result->timedOut);
    }

    public function testReturnsTimeoutAndOutputLimitInsteadOfSyntheticExitOne(): void
    {
        $timeout = $this->executeFixture('usleep(500000);', timeout: 0.05);
        self::assertTrue($timeout->timedOut);

        $stdout = $this->executeFixture('fwrite(STDOUT, str_repeat("x", 32));', stdoutLimit: 8);
        self::assertSame('stdout', $stdout->outputLimitStream);
        self::assertSame('xxxxxxxx', $stdout->stdout);

        $stderr = $this->executeFixture('fwrite(STDERR, str_repeat("y", 32));', stderrLimit: 8);
        self::assertSame('stderr', $stderr->outputLimitStream);
        self::assertSame('yyyyyyyy', $stderr->stderr);
    }

    public function testPreservesSignalFactsWhereSupported(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_kill')) {
            self::markTestSkipped('POSIX signal facts are not available on this host.');
        }
        $result = $this->executeFixture('posix_kill(getmypid(), SIGTERM); usleep(100000);');

        self::assertTrue($result->signaled);
        self::assertSame(SIGTERM, $result->signal);
    }

    private function executeFixture(
        string $program,
        float $timeout = 2.0,
        int $stdoutLimit = 1024,
        int $stderrLimit = 1024,
    ): \Doria\Baton\Process\BoundedProcessResult {
        return (new BoundedProcessRunner())->run(
            [PHP_BINARY, '-r', $program],
            sys_get_temp_dir(),
            null,
            null,
            $timeout,
            $stdoutLimit,
            $stderrLimit,
        );
    }
}
