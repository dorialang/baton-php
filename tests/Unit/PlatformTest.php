<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;

final class PlatformTest extends TestCase
{
    public function testUsesNativeAmd64ArchitectureForA32BitProcessOnWindows(): void
    {
        $host = Platform::host(
            osFamily: 'Windows',
            environment: [
                'PROCESSOR_ARCHITECTURE' => 'x86',
                'PROCESSOR_ARCHITEW6432' => 'AMD64',
            ],
        );

        self::assertSame('windows', $host->name);
        self::assertSame('x86_64', $host->architecture);
        self::assertSame('windows-x86_64', $host->target());
    }

    public function testUsesNativeArm64ArchitectureOnWindows(): void
    {
        $host = Platform::host(
            osFamily: 'Windows',
            environment: ['PROCESSOR_ARCHITECTURE' => 'ARM64'],
        );

        self::assertSame('windows-aarch64', $host->target());
    }
}
