<?php

declare(strict_types=1);

namespace Doria\Baton\Toolchain;

use Doria\Baton\Diagnostics\BatonError;

/**
 * Resolves the `doriac` executable Baton should run.
 *
 * Bootstrap discovery order (a subset of plan B5 — the installed-toolchain
 * manifest steps land with the assembled distribution in B13):
 *
 *   1. an explicit `--compiler <path>` development override;
 *   2. the `BATON_DORIAC` environment override;
 *   3. `doriac` on `PATH`.
 *
 * Once a bundled toolchain exists, its recorded compiler must take precedence
 * over `PATH`; that step is intentionally not implemented yet.
 */
final class ToolchainLocator
{
    public function __construct(private readonly ?string $override = null)
    {
    }

    public function locate(): string
    {
        if ($this->override !== null && $this->override !== '') {
            return $this->requireExecutable($this->override, 'the --compiler override');
        }

        $fromEnv = getenv('BATON_DORIAC');
        if ($fromEnv !== false && $fromEnv !== '') {
            return $this->requireExecutable($fromEnv, 'BATON_DORIAC');
        }

        $onPath = $this->searchPath($this->executableName());
        if ($onPath !== null) {
            return $onPath;
        }

        throw new BatonError(
            'B0202',
            'Doria Compiler Not Found',
            "Baton could not locate `doriac`. Install the Doria toolchain so\n"
                . "`doriac` is on PATH, set BATON_DORIAC to its path, or pass\n"
                . "`--compiler <path>`."
        );
    }

    private function requireExecutable(string $path, string $source): string
    {
        if (!is_file($path)) {
            throw new BatonError(
                'B0202',
                'Doria Compiler Not Found',
                "The compiler configured via {$source} does not exist:\n    {$path}"
            );
        }

        return $path;
    }

    private function searchPath(string $name): ?string
    {
        $pathEnv = getenv('PATH');
        if ($pathEnv === false || $pathEnv === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $pathEnv) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && $this->isExecutable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isExecutable(string $path): bool
    {
        // On Windows is_executable() is unreliable for scripts; presence of a
        // matching PATHEXT entry is enough there.
        return PHP_OS_FAMILY === 'Windows' ? true : is_executable($path);
    }

    private function executableName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'doriac.exe' : 'doriac';
    }
}
