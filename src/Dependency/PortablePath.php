<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;

final class PortablePath
{
    public static function relative(string $from, string $to): string
    {
        $from = str_replace('\\', '/', rtrim($from, '/\\'));
        $to = str_replace('\\', '/', rtrim($to, '/\\'));
        $fromDrive = preg_match('/^([A-Za-z]):\//', $from, $fromMatch) === 1
            ? strtolower($fromMatch[1])
            : null;
        $toDrive = preg_match('/^([A-Za-z]):\//', $to, $toMatch) === 1
            ? strtolower($toMatch[1])
            : null;
        if ($fromDrive !== $toDrive) {
            throw new BatonError(
                'B0341',
                'Path Dependency Cannot Be Locked Portably',
                "The project and dependency are on different filesystem volumes:\n    {$from}\n    {$to}",
            );
        }

        $fromParts = array_values(array_filter(explode('/', $from), static fn (string $part): bool => $part !== ''));
        $toParts = array_values(array_filter(explode('/', $to), static fn (string $part): bool => $part !== ''));
        $caseInsensitive = PHP_OS_FAMILY === 'Windows';
        while ($fromParts !== [] && $toParts !== []) {
            $left = $caseInsensitive ? strtolower($fromParts[0]) : $fromParts[0];
            $right = $caseInsensitive ? strtolower($toParts[0]) : $toParts[0];
            if ($left !== $right) {
                break;
            }
            array_shift($fromParts);
            array_shift($toParts);
        }
        $relative = [...array_fill(0, count($fromParts), '..'), ...$toParts];

        return $relative === [] ? '.' : implode('/', $relative);
    }
}
