<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use UnexpectedValueException;

final readonly class GitSelector
{
    private function __construct(
        public string $kind,
        public string $value,
    ) {
    }

    public static function parse(string $kind, string $value): self
    {
        if (!in_array($kind, ['rev', 'tag', 'branch'], true)) {
            throw new UnexpectedValueException('unknown Git selector kind');
        }
        $valid = $kind === 'rev'
            ? preg_match('/^[0-9a-fA-F]{7,40}$/D', $value) === 1
            : $value !== ''
                && preg_match('/[\x00-\x20\x7f~^:?*\[\\\\]/', $value) !== 1
                && !str_contains($value, '..')
                && !str_contains($value, '@{')
                && !str_starts_with($value, '-')
                && !str_starts_with($value, '/')
                && !str_ends_with($value, '/')
                && !str_ends_with($value, '.')
                && !str_ends_with($value, '.lock');
        if (!$valid) {
            throw new UnexpectedValueException("invalid Git {$kind}");
        }

        return new self($kind, $value);
    }

    public function describe(): string
    {
        return "{$this->kind} {$this->value}";
    }
}
