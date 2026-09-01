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

    /**
     * @param array<string, string>|null $environment
     */
    public static function host(
        ?string $osFamily = null,
        ?string $machine = null,
        ?array $environment = null,
    ): self
    {
        $osFamily ??= PHP_OS_FAMILY;
        $machine ??= self::hostMachine($osFamily, $environment);

        $name = match (strtolower($osFamily)) {
            'windows' => 'windows',
            'darwin' => 'macos',
            'linux' => 'linux',
            default => throw new BatonError(
                'B0204',
                'Unsupported Host Platform',
                "Baton does not have a toolchain target name for {$osFamily}.",
                [
                    'No command fixes this. Doria toolchains are published for Windows,',
                    'macOS, and Linux.',
                ],
            ),
        };
        $architecture = match (strtolower($machine)) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => throw new BatonError(
                'B0204',
                'Unsupported Host Architecture',
                "Baton does not have a toolchain architecture name for {$machine}.",
                ['No command fixes this. Doria toolchains are published for x86_64 and aarch64.'],
            ),
        };

        return new self($name, $architecture);
    }

    /**
     * A 32-bit PHP process reports i586 on 64-bit Windows. In that case,
     * PROCESSOR_ARCHITEW6432 identifies the native host architecture.
     *
     * @param array<string, string>|null $environment
     */
    private static function hostMachine(string $osFamily, ?array $environment): string
    {
        if (strtolower($osFamily) === 'windows') {
            foreach (['PROCESSOR_ARCHITEW6432', 'PROCESSOR_ARCHITECTURE'] as $name) {
                $value = $environment === null
                    ? getenv($name)
                    : ($environment[$name] ?? false);

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return php_uname('m');
    }

    public function target(): string
    {
        return "{$this->name}-{$this->architecture}";
    }
}
