<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Project\ProjectLocator;

/**
 * Loads and validates the minimal bootstrap `Baton.toml` (plan B6).
 *
 * This reads only the documented subset — a top-level `manifest-version` and a
 * `[package]` table of string values. It is deliberately not a full TOML parser;
 * selecting one (with line/column diagnostics) is its own milestone. Anything
 * outside the subset is reported rather than silently accepted.
 */
final class ManifestLoader
{
    private const SUPPORTED_MANIFEST_VERSION = 1;

    /** @var list<string> */
    private const SUPPORTED_KINDS = ['binary'];

    public function load(string $projectRoot): Manifest
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . ProjectLocator::MANIFEST_FILE;
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw $this->invalid("The manifest could not be read:\n    {$path}");
        }

        $values = $this->parse($contents);

        $manifestVersion = $this->requireInt($values, 'manifest-version');
        if ($manifestVersion !== self::SUPPORTED_MANIFEST_VERSION) {
            throw $this->invalid(
                "Unsupported manifest-version {$manifestVersion}; this Baton "
                    . "understands version " . self::SUPPORTED_MANIFEST_VERSION . '.'
            );
        }

        $name = $this->requireString($values, 'package.name');
        if (preg_match('/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $name) !== 1) {
            throw $this->invalid(
                "Invalid package name \"{$name}\". Use lowercase letters, digits, "
                    . "'-', or '_'."
            );
        }

        $version = $this->requireString($values, 'package.version');
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+].+)?$/', $version) !== 1) {
            throw $this->invalid(
                "Package version \"{$version}\" is not SemVer (expected MAJOR.MINOR.PATCH)."
            );
        }

        $kind = $this->requireString($values, 'package.kind');
        if (!in_array($kind, self::SUPPORTED_KINDS, true)) {
            throw $this->invalid(
                "Unsupported package kind \"{$kind}\". Supported: "
                    . implode(', ', self::SUPPORTED_KINDS) . '.'
            );
        }

        $entry = $this->requireString($values, 'package.entry');
        $this->assertContained($entry);

        return new Manifest($manifestVersion, $name, $version, $kind, $entry);
    }

    /**
     * Parse the supported subset into flat dotted keys, e.g. `package.name`.
     *
     * @return array<string, string|int>
     */
    private function parse(string $contents): array
    {
        $values = [];
        $section = '';

        foreach (explode("\n", $contents) as $raw) {
            $line = trim($this->stripComment($raw));
            if ($line === '') {
                continue;
            }

            if ($line[0] === '[') {
                if (!str_ends_with($line, ']')) {
                    throw $this->invalid("Malformed table header: {$line}");
                }
                $section = trim(substr($line, 1, -1));
                continue;
            }

            $equals = strpos($line, '=');
            if ($equals === false) {
                throw $this->invalid("Expected `key = value` but found: {$line}");
            }

            $key = trim(substr($line, 0, $equals));
            $rawValue = trim(substr($line, $equals + 1));
            $prefix = $section === '' ? '' : $section . '.';
            $values[$prefix . $key] = $this->parseValue($rawValue, $line);
        }

        return $values;
    }

    private function stripComment(string $line): string
    {
        $inString = false;
        $length = strlen($line);
        for ($index = 0; $index < $length; $index++) {
            $character = $line[$index];
            if ($character === '"') {
                $inString = !$inString;
            } elseif ($character === '#' && !$inString) {
                return substr($line, 0, $index);
            }
        }

        return $line;
    }

    private function parseValue(string $rawValue, string $line): string|int
    {
        if ($rawValue !== '' && $rawValue[0] === '"') {
            if (strlen($rawValue) < 2 || !str_ends_with($rawValue, '"')) {
                throw $this->invalid("Unterminated string value on line: {$line}");
            }

            return substr($rawValue, 1, -1);
        }

        if (preg_match('/^-?\d+$/', $rawValue) === 1) {
            return (int) $rawValue;
        }

        throw $this->invalid("Unsupported value on line: {$line}");
    }

    /** @param array<string, string|int> $values */
    private function requireString(array $values, string $key): string
    {
        if (!array_key_exists($key, $values)) {
            throw $this->invalid("Missing required field `{$key}`.");
        }
        $value = $values[$key];
        if (!is_string($value)) {
            throw $this->invalid("Field `{$key}` must be a string.");
        }

        return $value;
    }

    /** @param array<string, string|int> $values */
    private function requireInt(array $values, string $key): int
    {
        if (!array_key_exists($key, $values)) {
            throw $this->invalid("Missing required field `{$key}`.");
        }
        $value = $values[$key];
        if (!is_int($value)) {
            throw $this->invalid("Field `{$key}` must be an integer.");
        }

        return $value;
    }

    private function assertContained(string $entry): void
    {
        if ($entry === '' || str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $entry) === 1) {
            throw $this->invalid("Entry path \"{$entry}\" must be relative to the project root.");
        }

        foreach (explode('/', str_replace('\\', '/', $entry)) as $segment) {
            if ($segment === '..') {
                throw $this->invalid("Entry path \"{$entry}\" must not escape the project root.");
            }
        }
    }

    private function invalid(string $body): BatonError
    {
        // The manifest is edited by hand, so the remedy is an edit rather than a
        // command. Name the field contract instead of leaving the reader to
        // guess which spelling Baton accepts.
        return new BatonError(
            'B0302',
            'Invalid Project Manifest',
            $body,
            [
                'Correct Baton.toml against the field contract in the project manifest',
                'documentation, then re-check the project:',
            ],
            ['baton check'],
        );
    }
}
