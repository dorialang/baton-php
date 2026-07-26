<?php

declare(strict_types=1);

namespace Doria\Baton\Toolchain;

use Doria\Baton\Diagnostics\BatonError;

/** Canonical platform and architecture names used by Doria toolchain metadata. */
final readonly class Platform
{
    public function __construct(
        public string $name,
        public string $architecture,
    ) {
    }

    public static function host(?string $osFamily = null, ?string $machine = null): self
    {
        $osFamily ??= PHP_OS_FAMILY;
        $machine ??= php_uname('m');

        $name = match (strtolower($osFamily)) {
            'windows' => 'windows',
            'darwin' => 'macos',
            'linux' => 'linux',
            default => throw new BatonError(
                'B0204',
                'Unsupported Host Platform',
                "Baton does not have a toolchain target name for {$osFamily}."
            ),
        };
        $architecture = match (strtolower($machine)) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => throw new BatonError(
                'B0204',
                'Unsupported Host Architecture',
                "Baton does not have a toolchain architecture name for {$machine}."
            ),
        };

        return new self($name, $architecture);
    }

    public function target(): string
    {
        return "{$this->name}-{$this->architecture}";
    }
}
