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
 * Baton diagnostics use Baton codes and headings. Compiler diagnostics are
 * forwarded unchanged and never reformatted into this shape (plan B1).
 */
final class BatonError extends RuntimeException
{
    public function __construct(
        public readonly string $diagnosticCode,
        public readonly string $heading,
        public readonly string $body = '',
    ) {
        parent::__construct($heading);
    }

    public function render(): string
    {
        $rendered = "Error[{$this->diagnosticCode}]: {$this->heading}";
        if ($this->body !== '') {
            $rendered .= "\n\n{$this->body}";
        }

        return $rendered;
    }
}
