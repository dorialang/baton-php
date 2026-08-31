<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

use Doria\Baton\Diagnostics\BatonError;

final class RuntimeOutcomeChannel
{
    public readonly string $path;

    public function __construct(string $testDirectory, string $identity)
    {
        $directory = $testDirectory . DIRECTORY_SEPARATOR . 'outcomes';
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new BatonError('B0425', 'Runtime Outcome Storage Could Not Be Prepared', $directory);
        }
        $this->path = $directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $identity)
            . '-'
            . bin2hex(random_bytes(12))
            . '.outcome';
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        $this->remove();

        return [
            'DORIA_RUNTIME_OUTCOME_V2' => $this->path,
            'DORIA_RUNTIME_OUTCOME_V3' => $this->path,
            'DORIA_RUNTIME_OUTCOME_V4' => $this->path,
        ];
    }

    /** @return array{outcome: RuntimeOutcome|null, error: string|null} */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return ['outcome' => null, 'error' => null];
        }
        try {
            return ['outcome' => (new RuntimeOutcomeReader())->read($this->path), 'error' => null];
        } catch (RuntimeOutcomeInvalid $error) {
            return ['outcome' => null, 'error' => $error->getMessage()];
        }
    }

    public function remove(): void
    {
        if ((is_file($this->path) || is_link($this->path)) && !@unlink($this->path)) {
            throw new BatonError('B0425', 'Runtime Outcome Could Not Be Removed', $this->path);
        }
    }
}
