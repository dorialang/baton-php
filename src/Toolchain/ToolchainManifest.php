<?php

declare(strict_types=1);

namespace Doria\Baton\Toolchain;

use Doria\Baton\Diagnostics\BatonError;
use JsonException;

/** Validated installed-toolchain metadata from `toolchain.json`. */
final readonly class ToolchainManifest
{
    private const SUPPORTED_SCHEMA = 1;

    public function __construct(
        public string $path,
        public string $toolchainVersion,
        public string $platform,
        public string $architecture,
        public string $compilerPath,
        public string $compilerVersion,
        public string $compilerHash,
    ) {
    }

    public static function load(string $path): self
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw self::invalid("The toolchain manifest could not be read:\n    {$path}");
        }

        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw self::invalid(
                "The toolchain manifest is not valid JSON:\n    {$path}\n\n{$error->getMessage()}"
            );
        }
        if (!is_array($value)) {
            throw self::invalid("The toolchain manifest root must be an object:\n    {$path}");
        }
        if (($value['schema'] ?? null) !== self::SUPPORTED_SCHEMA) {
            throw self::invalid(
                'Unsupported toolchain manifest schema; expected '
                    . self::SUPPORTED_SCHEMA . '.'
            );
        }

        $toolchainVersion = self::stringField($value, 'toolchainVersion');
        $platform = self::stringField($value, 'platform');
        $architecture = self::stringField($value, 'architecture');
        $components = $value['components'] ?? null;
        $compiler = is_array($components) ? ($components['doriac'] ?? null) : null;
        if (!is_array($compiler)) {
            throw self::invalid('The toolchain manifest is missing `components.doriac`.');
        }
        $compilerVersion = self::stringField($compiler, 'version', 'components.doriac.');
        $relativeCompilerPath = self::stringField($compiler, 'path', 'components.doriac.');
        $compilerHash = strtolower(self::stringField($compiler, 'sha256', 'components.doriac.'));
        if (preg_match('/^[a-f0-9]{64}$/', $compilerHash) !== 1) {
            throw self::invalid('`components.doriac.sha256` must be a SHA-256 digest.');
        }

        $compilerPath = self::containedComponentPath(dirname($path), $relativeCompilerPath);

        return new self(
            $path,
            $toolchainVersion,
            $platform,
            $architecture,
            $compilerPath,
            $compilerVersion,
            $compilerHash,
        );
    }

    public function assertCompatible(string $expectedVersion, Platform $host): void
    {
        if (
            $this->toolchainVersion !== $expectedVersion
            || $this->compilerVersion !== $expectedVersion
        ) {
            throw self::invalid(
                "Baton expects toolchain {$expectedVersion}, but the manifest records "
                    . "{$this->toolchainVersion} and doriac {$this->compilerVersion}."
            );
        }
        if ($this->platform !== $host->name || $this->architecture !== $host->architecture) {
            throw self::invalid(
                "The manifest targets {$this->platform}-{$this->architecture}, "
                    . "but the host is {$host->target()}."
            );
        }
    }

    public function verifyCompilerHash(): void
    {
        $root = realpath(dirname($this->path));
        $compiler = realpath($this->compilerPath);
        if (
            $root === false
            || $compiler === false
            || !self::isWithin($compiler, $root)
        ) {
            throw self::invalid(
                "The doriac component resolves outside the toolchain root:\n"
                    . "    {$this->compilerPath}"
            );
        }

        $actual = @hash_file('sha256', $this->compilerPath);
        if (!is_string($actual) || !hash_equals($this->compilerHash, strtolower($actual))) {
            throw self::invalid(
                "The doriac component hash does not match toolchain.json:\n"
                    . "    {$this->compilerPath}"
            );
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function stringField(array $value, string $field, string $prefix = ''): string
    {
        $fieldValue = $value[$field] ?? null;
        if (!is_string($fieldValue) || $fieldValue === '') {
            throw self::invalid("Missing or invalid `{$prefix}{$field}`.");
        }

        return $fieldValue;
    }

    private static function containedComponentPath(string $root, string $relative): string
    {
        if (
            str_starts_with($relative, '/')
            || str_starts_with($relative, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $relative) === 1
        ) {
            throw self::invalid('Toolchain component paths must be relative.');
        }
        $segments = preg_split('/[\\\\\/]+/', $relative);
        if ($segments === false || in_array('..', $segments, true)) {
            throw self::invalid('Toolchain component paths must not escape the toolchain root.');
        }

        return $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private static function isWithin(string $path, string $root): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function invalid(string $body): BatonError
    {
        return new BatonError('B0204', 'Invalid Toolchain Manifest', $body);
    }
}
