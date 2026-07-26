<?php

declare(strict_types=1);

namespace Doria\Baton\Project;

use Doria\Baton\Diagnostics\BatonError;

/** Finds the project root by walking up from a starting directory to `Baton.toml`. */
final class ProjectLocator
{
    public const MANIFEST_FILE = 'Baton.toml';

    public function locate(string $startDirectory): string
    {
        $directory = realpath($startDirectory);
        if ($directory === false) {
            $directory = $startDirectory;
        }

        while (true) {
            if (is_file($directory . DIRECTORY_SEPARATOR . self::MANIFEST_FILE)) {
                return $directory;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break; // reached the filesystem root
            }
            $directory = $parent;
        }

        throw new BatonError(
            'B0301',
            'No Baton Project Found',
            "No " . self::MANIFEST_FILE . " was found in the current directory or any\n"
                . "parent. Run this command inside a Baton project, or create one with\n"
                . "`baton new <name>`."
        );
    }
}
