<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

final readonly class PackageVersionConstraint
{
    private function __construct(public string $expression)
    {
    }

    public static function parse(string $expression): self
    {
        if ($expression === ''
            || trim($expression) !== $expression
            || preg_match('/(?:\|\||\||\*|\bx\b|\bX\b|@|\bdev\b)/', $expression) === 1
        ) {
            throw new UnexpectedValueException('unsupported package version constraint');
        }

        $version = self::versionPattern();
        $exact = "/^{$version}$/D";
        $caretOrTilde = "/^[\^~](?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*)){0,2}(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D";
        $partial = self::partialVersionPattern();
        $comparators = "/^(?:>=|<=|>|<|=){$partial}(?:\s+(?:>=|<=|>|<|=){$partial})*$/D";
        if (preg_match($exact, $expression) !== 1
            && preg_match($caretOrTilde, $expression) !== 1
            && preg_match($comparators, $expression) !== 1
        ) {
            throw new UnexpectedValueException('unsupported package version constraint');
        }

        try {
            (new VersionParser())->parseConstraints($expression);
        } catch (UnexpectedValueException) {
            throw new UnexpectedValueException('invalid package version constraint');
        }

        return new self($expression);
    }

    public function matches(string $version): bool
    {
        if (str_contains($version, '-') && !str_contains($this->expression, '-')) {
            return false;
        }

        return Semver::satisfies($version, $this->expression);
    }

    private static function versionPattern(): string
    {
        return '(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
            . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?';
    }

    private static function partialVersionPattern(): string
    {
        return '(?:0|[1-9]\d*)'
            . '(?:\.(?:0|[1-9]\d*)'
            . '(?:\.(?:0|[1-9]\d*)'
            . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
            . ')?'
            . ')?';
    }
}
