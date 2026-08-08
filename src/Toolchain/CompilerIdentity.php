<?php

declare(strict_types=1);

namespace Doria\Baton\Toolchain;

use Doria\Baton\Compiler\CompilerResult;
use Doria\Baton\Diagnostics\BatonError;
use JsonException;

/** Validated schema-1 output from `doriac --version --json`. */
final readonly class CompilerIdentity
{
    private const SUPPORTED_SCHEMA = 1;

    public function __construct(
        public int $schema,
        public string $component,
        public string $toolchainVersion,
        public string $target,
        public string $commit,
    ) {
    }

    public static function fromResult(CompilerResult $result, string $path): self
    {
        if (!$result->succeeded()) {
            $detail = trim($result->stderr);
            throw self::incompatible(
                "The compiler at:\n    {$path}\n"
                    . "failed `--version --json` with exit code {$result->exitCode}."
                    . ($detail === '' ? '' : "\n\n{$detail}")
            );
        }

        return self::fromJson($result->stdout, $path);
    }

    public static function fromJson(string $json, string $path = 'doriac'): self
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw self::incompatible(
                "The compiler at:\n    {$path}\n"
                    . "returned invalid JSON from `--version --json`: {$error->getMessage()}"
            );
        }

        if (!is_array($value)) {
            throw self::incompatible("The compiler at:\n    {$path}\nreturned a non-object identity.");
        }

        $schema = $value['schema'] ?? null;
        if ($schema !== self::SUPPORTED_SCHEMA) {
            throw self::incompatible(
                "The compiler at:\n    {$path}\nuses unsupported identity schema "
                    . self::describe($schema) . '; expected schema ' . self::SUPPORTED_SCHEMA . '.'
            );
        }

        foreach (['component', 'toolchainVersion', 'target', 'commit'] as $field) {
            if (!is_string($value[$field] ?? null) || $value[$field] === '') {
                throw self::incompatible(
                    "The compiler at:\n    {$path}\nhas an invalid or missing `{$field}` identity field."
                );
            }
        }
        if ($value['component'] !== 'doriac') {
            throw self::incompatible(
                "The selected component identifies itself as `{$value['component']}`, not `doriac`."
            );
        }

        return new self(
            $schema,
            $value['component'],
            $value['toolchainVersion'],
            $value['target'],
            $value['commit'],
        );
    }

    public function assertCompatible(string $expectedVersion, Platform $host, string $path): void
    {
        if ($this->toolchainVersion !== $expectedVersion) {
            throw self::incompatible(
                "Baton:  {$expectedVersion}\n"
                    . "doriac: {$this->toolchainVersion}\n\n"
                    . "The compiler at:\n    {$path}\n"
                    . "does not belong to this Baton toolchain.",
                [
                    'Toolchain components ship together and must report the same version.',
                    'Check which component is behind, then install a matching toolchain:',
                ],
                ['baton doctor'],
            );
        }
        if ($this->target !== $host->target()) {
            throw self::incompatible(
                "Host:    {$host->target()}\n"
                    . "doriac: {$this->target}\n\n"
                    . "The selected compiler was built for a different target.",
                [
                    'Install the Doria toolchain built for this host rather than reusing',
                    'a compiler copied from another machine. To confirm the host target:',
                ],
                ['baton doctor'],
            );
        }
    }

    /**
     * @param list<string> $help
     * @param list<string> $run
     */
    private static function incompatible(string $body, array $help = [], array $run = []): BatonError
    {
        return new BatonError('B0201', 'Incompatible Doria Compiler', $body, $help, $run);
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) || $value === null
            ? var_export($value, true)
            : get_debug_type($value);
    }
}
