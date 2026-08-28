<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\DependencyDeclaration;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\PathDependencySource;

final class ManifestDependencyEditor
{
    public function add(string $contents, DependencyDeclaration $dependency): string
    {
        [$start, $end] = $this->dependencySection($contents);
        $line = $this->line($dependency);
        if ($start === null) {
            $suffix = str_ends_with($contents, "\n") ? '' : "\n";

            return $contents . $suffix . "\n[dependencies]\n{$line}\n";
        }
        $prefix = substr($contents, 0, $end);
        $suffix = substr($contents, $end);
        if ($prefix !== '' && !str_ends_with($prefix, "\n")) {
            $prefix .= "\n";
        }

        return $prefix . $line . "\n" . $suffix;
    }

    public function remove(string $contents, string $package): string
    {
        [$start, $end] = $this->dependencySection($contents);
        if ($start === null) {
            throw $this->notDirect($package);
        }
        $section = substr($contents, $start, $end - $start);
        $keys = [preg_quote($this->quote($package), '/')];
        if (!str_contains($package, '/')) {
            $keys[] = preg_quote($package, '/');
        }
        $pattern = '/(?m)^[ \t]*(?:' . implode('|', $keys) . ')[ \t]*=/';
        if (preg_match($pattern, $section, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw $this->notDirect($package);
        }
        $entryStart = $match[0][1];
        $entryEnd = $this->entryEnd($section, $entryStart);
        $updated = substr($section, 0, $entryStart) . substr($section, $entryEnd);

        return substr($contents, 0, $start) . $updated . substr($contents, $end);
    }

    /** @return array{0: int|null, 1: int} */
    private function dependencySection(string $contents): array
    {
        if (preg_match('/(?m)^\[dependencies\][ \t]*(?:#.*)?\r?$/', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [null, strlen($contents)];
        }
        $headerOffset = $match[0][1];
        $bodyStart = $headerOffset + strlen($match[0][0]);
        if (substr($contents, $bodyStart, 2) === "\r\n") {
            $bodyStart += 2;
        } elseif (substr($contents, $bodyStart, 1) === "\n") {
            ++$bodyStart;
        }
        $tail = substr($contents, $bodyStart);
        if (preg_match('/(?m)^\[{1,2}[^\r\n]+\]{1,2}[ \t]*(?:#.*)?\r?$/', $tail, $next, PREG_OFFSET_CAPTURE) === 1) {
            return [$bodyStart, $bodyStart + $next[0][1]];
        }

        return [$bodyStart, strlen($contents)];
    }

    private function entryEnd(string $section, int $start): int
    {
        $length = strlen($section);
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($index = $start; $index < $length; ++$index) {
            $character = $section[$index];
            if ($quote !== null) {
                if ($quote === '"' && !$escaped && $character === '\\') {
                    $escaped = true;
                    continue;
                }
                if (!$escaped && $character === $quote) {
                    $quote = null;
                }
                $escaped = false;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '{') {
                ++$depth;
            } elseif ($character === '}') {
                --$depth;
            } elseif ($character === "\n" && $depth === 0) {
                return $index + 1;
            }
        }

        return $length;
    }

    private function line(DependencyDeclaration $dependency): string
    {
        $parts = [];
        if ($dependency->source instanceof PathDependencySource) {
            $parts[] = 'path = ' . $this->quote($dependency->source->path);
        } elseif ($dependency->source instanceof GitDependencySource) {
            $parts[] = 'git = ' . $this->quote($dependency->source->url);
            $parts[] = $dependency->source->selector->kind . ' = '
                . $this->quote($dependency->source->selector->value);
        }
        if ($dependency->version !== null) {
            $parts[] = 'version = ' . $this->quote($dependency->version->expression);
        }

        return $this->quote($dependency->package) . ' = { ' . implode(', ', $parts) . ' }';
    }

    private function quote(string $value): string
    {
        return '"' . addcslashes($value, "\\\"\n\r\t") . '"';
    }

    private function notDirect(string $package): BatonError
    {
        return new BatonError(
            'B0381',
            'Dependency Is Not Directly Declared',
            "Package `{$package}` is not a direct normal dependency.",
        );
    }
}
