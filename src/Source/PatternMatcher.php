<?php

declare(strict_types=1);

namespace Doria\Baton\Source;

final class PatternMatcher
{
    public function matches(string $pattern, string $relativePath): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '*' && ($pattern[$index + 1] ?? '') === '*') {
                $index++;
                if (($pattern[$index + 1] ?? '') === '/') {
                    $index++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($character === '*') {
                $regex .= '[^/]*';
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($character, '~');
            }
        }

        return preg_match('~^' . $regex . '$~D', $relativePath) === 1;
    }
}
