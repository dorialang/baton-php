<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use UnexpectedValueException;

final class PackageIdentity
{
    public static function compilerIdentity(string $authored): string
    {
        $segments = explode('/', $authored);
        if (!in_array(count($segments), [1, 2], true)
            || array_filter($segments, static fn (string $part): bool => !self::isSlug($part)) !== []
            || (count($segments) === 2 && $segments[0] === 'local')
        ) {
            throw new UnexpectedValueException('invalid package identity');
        }

        return count($segments) === 1 ? 'local/' . $authored : $authored;
    }

    public static function isScoped(string $authored): bool
    {
        self::compilerIdentity($authored);

        return str_contains($authored, '/');
    }

    private static function isSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/D', $value) === 1;
    }
}
