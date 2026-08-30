<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Testing\RuntimeOutcomeChannel;
use Doria\Baton\Tests\TestCase;

final class RuntimeOutcomeChannelTest extends TestCase
{
    public function testUsesOneUniqueManagedPathForEveryProtocolAndRemovesStaleData(): void
    {
        $root = $this->temporaryDirectory('runtime outcome channel');
        $first = new RuntimeOutcomeChannel($root, 'Suite > test / unsafe');
        $second = new RuntimeOutcomeChannel($root, 'Suite > test / unsafe');
        self::assertNotSame($first->path, $second->path);
        self::assertStringNotContainsString('Suite', basename($first->path));

        self::assertNotFalse(file_put_contents($first->path, 'stale'));
        $environment = $first->environment();
        self::assertFileDoesNotExist($first->path);
        self::assertSame($first->path, $environment['DORIA_RUNTIME_OUTCOME_V2']);
        self::assertSame($first->path, $environment['DORIA_RUNTIME_OUTCOME_V3']);
        self::assertSame($first->path, $environment['DORIA_RUNTIME_OUTCOME_V4']);

        self::assertNotFalse(file_put_contents($first->path, 'malformed'));
        $decoded = $first->read();
        self::assertNull($decoded['outcome']);
        self::assertStringContainsString('unknown magic', $decoded['error'] ?? '');
        $first->remove();
        self::assertFileDoesNotExist($first->path);
    }
}
