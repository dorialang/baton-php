<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Manifest\PackageVersionConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class PackageVersionConstraintTest extends TestCase
{
    #[DataProvider('acceptedConstraints')]
    public function testAcceptedConstraintForms(string $constraint): void
    {
        self::assertSame($constraint, PackageVersionConstraint::parse($constraint)->expression);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedConstraints(): iterable
    {
        yield 'exact' => ['1.4.2'];
        yield 'caret partial' => ['^1.4'];
        yield 'caret zero major' => ['^0.4.2'];
        yield 'tilde' => ['~1.4.2'];
        yield 'bounded partial comparators' => ['>=1.4 <2.0'];
        yield 'inclusive comparators' => ['>=1.4.2 <=1.9.9'];
        yield 'exact prerelease' => ['2.0.0-beta.1'];
        yield 'explicit prerelease range' => ['>=2.0.0-beta.1 <2.0.0'];
    }

    #[DataProvider('rejectedConstraints')]
    public function testUnsupportedConstraintFormsAreRejected(string $constraint): void
    {
        $this->expectException(UnexpectedValueException::class);
        PackageVersionConstraint::parse($constraint);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedConstraints(): iterable
    {
        yield 'empty' => [''];
        yield 'or' => ['^1.0 || ^2.0'];
        yield 'wildcard' => ['1.4.*'];
        yield 'x range' => ['1.x'];
        yield 'stability flag' => ['^1.0@beta'];
        yield 'dev version' => ['dev-main'];
        yield 'malformed comparator' => ['>=1.0 <'];
        yield 'toolchain-shaped partial is not exact package SemVer' => ['2026.3'];
    }

    public function testMatchingUsesPackageSemverAndRequiresExplicitPrereleaseIntent(): void
    {
        self::assertTrue(PackageVersionConstraint::parse('^1.4')->matches('1.9.0'));
        self::assertFalse(PackageVersionConstraint::parse('^1.4')->matches('2.0.0'));
        self::assertFalse(PackageVersionConstraint::parse('^1.4')->matches('1.5.0-beta.1'));
        self::assertTrue(PackageVersionConstraint::parse('>=1.5.0-beta.1 <2.0.0')->matches('1.5.0-beta.2'));
    }
}
