<?php

declare(strict_types=1);

namespace Doria\Baton\Compiler;

use Doria\Baton\Diagnostics\BatonError;

/**
 * The single gateway for invoking `doriac` (plan B4).
 *
 * Every invocation uses an argument vector — never a shell string — so spaces,
 * quotes, Unicode, and platform path separators in arguments are handled by the
 * OS, not by string interpolation. No Baton code parses Doria source; this only
 * runs the compiler and relays its result.
 */
final class CompilerAdapter
{
    public function __construct(private readonly string $doriacPath)
    {
    }

    public function path(): string
    {
        return $this->doriacPath;
    }

    /**
     * Run the compiler, forwarding stdin/stdout/stderr to this process so the
     * user sees compiler diagnostics unchanged, and return its exit code.
     *
     * @param list<string> $args
     */
    public function passthrough(array $args, ?string $workingDirectory = null): int
    {
        $descriptors = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];

        $process = $this->open($args, $descriptors, $workingDirectory, $pipes);
        $this->closePipes($pipes);

        return proc_close($process);
    }

    /**
     * Run the compiler capturing stdout and stderr (for machine-readable output
     * such as `--version --json`).
     *
     * @param list<string> $args
     */
    public function capture(
        array $args,
        ?string $workingDirectory = null,
        ?float $timeoutSeconds = null,
    ): CompilerResult
    {
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = $this->open($args, $descriptors, $workingDirectory, $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = hrtime(true);
        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (
                $timeoutSeconds !== null
                && (hrtime(true) - $startedAt) / 1_000_000_000 >= $timeoutSeconds
            ) {
                proc_terminate($process);
                $this->closePipes($pipes);
                proc_close($process);
                throw $this->timeoutError($timeoutSeconds);
            }
            usleep(10_000);
        } while (true);

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        $this->closePipes($pipes);
        $closedExitCode = proc_close($process);
        $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : $closedExitCode;

        return new CompilerResult($exitCode, $stdout, $stderr);
    }

    private function timeoutError(float $timeoutSeconds): BatonError
    {
        return new BatonError(
            'B0203',
            'Doria Compiler Did Not Respond',
            "The compiler at:\n    {$this->doriacPath}\n"
                . "did not respond within {$timeoutSeconds}s.\n\n"
                . 'Use a compiled doriac artifact. Source launchers are for explicit '
                . 'compiler development and are not installed toolchain components.',
            ['Confirm which compiler Baton selected and whether it is a compiled artifact:'],
            ['baton doctor'],
        );
    }

    /**
     * @param list<string>                    $args
     * @param array<int, array{0: string, 1: string, 2?: string}> $descriptors
     * @param array<int, resource>             $pipes
     * @return resource
     */
    private function open(array $args, array $descriptors, ?string $workingDirectory, &$pipes)
    {
        $command = array_merge([$this->doriacPath], $args);

        $process = proc_open($command, $descriptors, $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new BatonError(
                'B0203',
                'Doria Compiler Could Not Be Started',
                "Failed to launch the compiler at:\n    {$this->doriacPath}",
                ['Confirm the selected compiler still exists and is executable:'],
                ['baton doctor'],
            );
        }

        return $process;
    }

    /** @param array<int, resource> $pipes */
    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
}
