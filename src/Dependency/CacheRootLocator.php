<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;

final readonly class CacheRootLocator
{
    /** @param array<string, string|false> $environment */
    public function __construct(
        private array $environment = [],
        private string $osFamily = PHP_OS_FAMILY,
    ) {
    }

    public function locate(): string
    {
        if ($this->osFamily === 'Windows') {
            $root = $this->value('LOCALAPPDATA');
            if ($root === null) {
                throw $this->missing();
            }

            return $root . DIRECTORY_SEPARATOR . 'Doria' . DIRECTORY_SEPARATOR . 'Baton'
                . DIRECTORY_SEPARATOR . 'Cache';
        }

        $home = $this->value('HOME');
        if ($this->osFamily === 'Darwin') {
            if ($home === null) {
                throw $this->missing();
            }

            return $home . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'Caches'
                . DIRECTORY_SEPARATOR . 'Doria' . DIRECTORY_SEPARATOR . 'Baton';
        }

        $xdg = $this->value('XDG_CACHE_HOME');
        if ($xdg !== null) {
            if (!$this->absolute($xdg)) {
                throw new BatonError(
                    'B0360',
                    'Dependency Cache Is Unavailable',
                    'XDG_CACHE_HOME must be an absolute path.',
                );
            }

            return $xdg . DIRECTORY_SEPARATOR . 'doria' . DIRECTORY_SEPARATOR . 'baton';
        }
        if ($home === null) {
            throw $this->missing();
        }

        return $home . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'doria'
            . DIRECTORY_SEPARATOR . 'baton';
    }

    private function value(string $name): ?string
    {
        $value = array_key_exists($name, $this->environment)
            ? $this->environment[$name]
            : getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function missing(): BatonError
    {
        return new BatonError(
            'B0360',
            'Dependency Cache Is Unavailable',
            'Baton could not determine the current user cache directory.',
        );
    }
}
