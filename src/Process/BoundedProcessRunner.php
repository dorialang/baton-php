<?php

declare(strict_types=1);

namespace Doria\Baton\Process;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
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
        try {
            $process->start();
            foreach ($process as $type => $bytes) {
                if ($type === Process::ERR) {
                    $stderr .= $bytes;
                    if (strlen($stderr) > $stderrLimit) {
                        $process->stop(0.0);
                        throw new ProcessOutputLimitExceeded('stderr', $stderrLimit);
                    }
                } else {
                    $stdout .= $bytes;
                    if (strlen($stdout) > $stdoutLimit) {
                        $process->stop(0.0);
                        throw new ProcessOutputLimitExceeded('stdout', $stdoutLimit);
                    }
                }
            }
        } catch (ProcessTimedOutException) {
            $process->stop(0.0);
            throw new BoundedProcessTimedOut($timeoutSeconds);
        }

        return new BoundedProcessResult($process->getExitCode() ?? 1, $stdout, $stderr);
    }
}
