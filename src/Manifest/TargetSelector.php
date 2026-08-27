<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use Doria\Baton\Diagnostics\BatonError;

/** Applies the one accepted selector policy consistently across commands. */
final class TargetSelector
{
    public function select(
        Manifest|Schema2Manifest $manifest,
        ?string $binary,
        bool $library,
        string $command,
    ): SelectedPackageTarget {
        if ($binary !== null && $library) {
            throw $this->error(
                'Target Selectors Conflict',
                '`--binary <name>` and `--library` are mutually exclusive.',
            );
        }

        if ($manifest instanceof Manifest) {
            if ($library) {
                throw $this->error(
                    'Library Target Is Not Declared',
                    'Schema 1 has one implicit binary target and no library target.',
                );
            }
            if ($binary !== null && $binary !== $manifest->name) {
                throw $this->unknownBinary($binary, [$manifest->name]);
            }

            return new SelectedPackageTarget(new BinaryTarget($manifest->name, $manifest->entry));
        }

        if ($command === 'run' && $library) {
            throw $this->error(
                'Library Target Cannot Be Run',
                'A library has no executable artifact. Select a binary with `--binary <name>`.',
            );
        }
        if ($binary !== null) {
            $target = $manifest->targets->binary($binary);
            if ($target === null) {
                throw $this->unknownBinary($binary, $manifest->targets->binaryNames());
            }

            return new SelectedPackageTarget($target);
        }
        if ($library) {
            if ($manifest->targets->library === null) {
                throw $this->error(
                    'Library Target Is Not Declared',
                    "This package has no library target.\n\n" . $this->available($manifest->targets),
                );
            }

            return new SelectedPackageTarget($manifest->targets->library);
        }

        if ($command === 'run') {
            if (count($manifest->targets->binaries) === 1) {
                return new SelectedPackageTarget($manifest->targets->binaries[0]);
            }
            if ($manifest->targets->binaries === []) {
                throw $this->error(
                    'Library Target Cannot Be Run',
                    "This package has no binary target.\n\n" . $this->available($manifest->targets),
                );
            }
        } elseif (count($manifest->targets->all()) === 1) {
            return new SelectedPackageTarget($manifest->targets->all()[0]);
        }

        throw $this->error(
            'Target Selection Is Ambiguous',
            "Select one package target explicitly.\n\n"
                . $this->available($manifest->targets)
                . "\n\nUse `--binary <name>` or `--library`.",
        );
    }

    /** @param list<string> $available */
    private function unknownBinary(string $name, array $available): BatonError
    {
        $list = $available === [] ? '(none)' : implode(', ', $available);

        return $this->error(
            'Binary Target Is Unknown',
            "Binary target `{$name}` is not declared.\n\nBinary: {$list}",
        );
    }

    private function available(TargetCollection $targets): string
    {
        $binaries = $targets->binaryNames();
        $binaryList = $binaries === [] ? '(none)' : implode(', ', $binaries);
        $libraryTarget = $targets->library;
        $library = $libraryTarget === null ? '(none)' : $libraryTarget->targetName;

        return "Available targets:\nBinary: {$binaryList}\nLibrary: {$library}";
    }

    private function error(string $heading, string $body): BatonError
    {
        return new BatonError(
            'B0311',
            $heading,
            $body,
            ['Choose one of the declared targets, then run the command again.'],
        );
    }
}
