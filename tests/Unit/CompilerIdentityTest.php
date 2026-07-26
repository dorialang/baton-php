<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Application;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\CompilerIdentity;
use Doria\Baton\Toolchain\Platform;

final class CompilerIdentityTest extends TestCase
{
    public function testParsesSchemaOneIdentity(): void
    {
        $identity = CompilerIdentity::fromJson((string) json_encode([
            'schema' => 1,
            'component' => 'doriac',
            'toolchainVersion' => Application::VERSION,
            'target' => 'linux-x86_64',
            'commit' => 'abc123',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(1, $identity->schema);
        self::assertSame('doriac', $identity->component);
        self::assertSame(Application::VERSION, $identity->toolchainVersion);
        self::assertSame('linux-x86_64', $identity->target);
        self::assertSame('abc123', $identity->commit);
    }

    public function testRejectsMalformedIdentityJson(): void
    {
        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Incompatible Doria Compiler');

        CompilerIdentity::fromJson('{', '/toolchain/bin/doriac');
    }

    public function testRejectsVersionMismatch(): void
    {
        $identity = new CompilerIdentity(
            1,
            'doriac',
            '2026.02.9-canary',
            'linux-x86_64',
            'abc123',
        );

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Incompatible Doria Compiler');

        $identity->assertCompatible(
            Application::VERSION,
            new Platform('linux', 'x86_64'),
            '/toolchain/bin/doriac',
        );
    }

    public function testRejectsTargetMismatch(): void
    {
        $identity = new CompilerIdentity(
            1,
            'doriac',
            Application::VERSION,
            'windows-x86_64',
            'abc123',
        );

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Incompatible Doria Compiler');

        $identity->assertCompatible(
            Application::VERSION,
            new Platform('linux', 'x86_64'),
            '/toolchain/bin/doriac',
        );
    }
}
