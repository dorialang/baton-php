<?php

declare(strict_types=1);

namespace Doria\Baton\Process;

use Symfony\Component\Process\Process;

final class BoundedProcessRunner
{
    /**
     * @param non-empty-list<string> $command
     * @param array<string, string>|null $environment
     */
    public function run(
        array $command,
        string $workingDirectory,
        ?array $environment,
        ?string $input,
        float $timeoutSeconds,
        int $stdoutLimit,
        int $stderrLimit,
    ): BoundedProcessResult {
        $process = new Process($command, $workingDirectory, $environment, $input, $timeoutSeconds);
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $outputLimitStream = null;
        try {
            $process->start();
            foreach ($process as $type => $bytes) {
                if ($type === Process::ERR) {
                    $stderr .= $bytes;
                    if (strlen($stderr) > $stderrLimit) {
                        $stderr = substr($stderr, 0, $stderrLimit);
                        $outputLimitStream = 'stderr';
                        $process->stop(0.0);
                        break;
                    }
                } else {
                    $stdout .= $bytes;
                    if (strlen($stdout) > $stdoutLimit) {
                        $stdout = substr($stdout, 0, $stdoutLimit);
                        $outputLimitStream = 'stdout';
                        $process->stop(0.0);
                        break;
                    }
                }
            }
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
            $timedOut = true;
            $process->stop(0.0);
        }

        $signaled = $process->hasBeenSignaled();

        return new BoundedProcessResult(
            $process->getExitCode(),
            $stdout,
            $stderr,
            $signaled,
            $signaled ? $process->getTermSignal() : null,
            $timedOut,
            $outputLimitStream,
        );
    }
}
