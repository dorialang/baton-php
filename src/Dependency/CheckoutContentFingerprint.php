<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CheckoutContentFingerprint
{
    public function calculate(string $root): ?string
    {
        if (!is_dir($root) || is_link($root)) {
            return null;
        }
        $entries = [];
        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            if ($relative === '.baton-cache.json') {
                continue;
            }
            if ($entry->isLink()) {
                $target = readlink($entry->getPathname());
                if ($target === false) {
                    return null;
                }
                $entries[$relative] = ['link', $target];
                continue;
            }
            if (!$entry->isFile()) {
                return null;
            }
            $bytes = @file_get_contents($entry->getPathname());
            if ($bytes === false) {
                return null;
            }
            $entries[$relative] = ['file', $bytes];
        }
        ksort($entries, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($entries as $relative => [$kind, $bytes]) {
            hash_update($context, $kind . "\0" . $relative . "\0" . pack('N', strlen($bytes)) . $bytes);
        }

        return hash_final($context);
    }
}
