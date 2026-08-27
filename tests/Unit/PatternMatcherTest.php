<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Source\PatternMatcher;
use Doria\Baton\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PatternMatcherTest extends TestCase
{
    #[DataProvider('patterns')]
    public function testPortablePatternLanguage(string $pattern, string $path, bool $matches): void
    {
        self::assertSame($matches, (new PatternMatcher())->matches($pattern, $path));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function patterns(): iterable
    {
        yield 'recursive includes root' => ['**/*.doria', 'main.doria', true];
        yield 'recursive includes nested' => ['**/*.doria', 'Domain/Post.doria', true];
        yield 'single star stays in segment' => ['*.doria', 'Domain/Post.doria', false];
        yield 'single star root match' => ['*.doria', 'Post.doria', true];
        yield 'question one character' => ['Post?.doria', 'Post1.doria', true];
        yield 'question does not cross separator' => ['Post?.doria', 'Post/1.doria', false];
        yield 'double star crosses directories' => ['Domain/**/Post.doria', 'Domain/Admin/Post.doria', true];
        yield 'double star matches zero directories' => ['Domain/**/Post.doria', 'Domain/Post.doria', true];
    }
}
