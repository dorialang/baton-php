<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use UnexpectedValueException;

final class GitUrl
{
    public static function canonicalize(string $url): string
    {
        if ($url === ''
            || preg_match('/[\x00-\x1f\x7f]/', $url) === 1
            || preg_match('/^[^\s@]+@[^:\s]+:.+$/', $url) === 1
        ) {
            throw new UnexpectedValueException('invalid Git URL');
        }
        $parts = parse_url($url);
        if (is_array($parts) && isset($parts['pass'])) {
            throw new UnexpectedValueException('credentials are not permitted');
        }
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || !in_array(strtolower($parts['scheme']), ['https', 'ssh'], true)
            || $parts['host'] === ''
            || $parts['path'] === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new UnexpectedValueException('invalid Git URL');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https' && isset($parts['user'])) {
            throw new UnexpectedValueException('credentials are not permitted');
        }
        if (isset($parts['user'])
            && ($scheme !== 'ssh' || preg_match('/^[A-Za-z0-9._-]+$/D', $parts['user']) !== 1)
        ) {
            throw new UnexpectedValueException('credentials are not permitted');
        }
        $path = preg_replace('#/+#', '/', $parts['path']);
        if (!is_string($path)
            || in_array('..', explode('/', $path), true)
        ) {
            throw new UnexpectedValueException('invalid Git URL path');
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            throw new UnexpectedValueException('invalid Git URL path');
        }

        $authority = isset($parts['user']) ? $parts['user'] . '@' : '';
        $authority .= strtolower($parts['host']);
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return "{$scheme}://{$authority}{$path}";
    }
}
