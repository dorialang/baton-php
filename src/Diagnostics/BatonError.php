<?php

declare(strict_types=1);

namespace Doria\Baton\Diagnostics;

use RuntimeException;

/**
 * A Baton-owned problem, rendered in Doria's Title Case diagnostic convention:
 *
 *     Error[B0301]: No Baton Project Found
 *
 *     <body>
 *
 *     Help
 *     <what to do next>
 *
 *         <command to run>
 *
 * Baton diagnostics use Baton codes and headings. Compiler diagnostics are
 * forwarded unchanged and never reformatted into this shape (plan B1).
 *
 * A failure that a command can fix must carry that command. Reporting only what
 * broke leaves the reader to reconstruct the remedy from memory, which is the
 * one thing a person switching between projects does not have. `$help` explains
 * the next action and `$run` carries the commands themselves as data, so an
 * editor or `doctor` can offer them directly instead of parsing rendered prose
 * (`docs/diagnostic-style.md`: consumers must use fields).
 *
 * `$run` holds commands that exist in this toolchain. Naming a command a reader
 * cannot run is worse than naming none, so a remedy that is not yet available
 * belongs in `$help` as prose, never here.
 */
final class BatonError extends RuntimeException
{
    /**
     * @param list<string> $help Next actions, one sentence per entry.
     * @param list<string> $run  Runnable commands, in the order to try them.
     */
    public function __construct(
        public readonly string $diagnosticCode,
        public readonly string $heading,
        public readonly string $body = '',
        public readonly array $help = [],
        public readonly array $run = [],
    ) {
        parent::__construct($heading);
    }

    public function render(): string
    {
        $rendered = "Error[{$this->diagnosticCode}]: {$this->heading}";
        if ($this->body !== '') {
            $rendered .= "\n\n{$this->body}";
        }
        if ($this->help !== [] || $this->run !== []) {
            $rendered .= "\n\nHelp";
            foreach ($this->help as $line) {
                $rendered .= "\n{$line}";
            }
            if ($this->run !== []) {
                $rendered .= "\n";
                foreach ($this->run as $command) {
                    $rendered .= "\n    {$command}";
                }
            }
        }

        return $rendered;
    }
}
