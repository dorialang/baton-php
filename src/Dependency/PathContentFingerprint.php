<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Source\SourceInventory;

final class PathContentFingerprint
{
    public function calculate(string $manifestFingerprint, SourceInventory $inventory): string
    {
        $context = hash_init('sha256');
        hash_update($context, "manifest\0{$manifestFingerprint}\0");
        foreach ($inventory->sources as $source) {
            if ($source->scope === 'development') {
                continue;
            }
            $bytes = @file_get_contents($source->canonicalPath);
            if ($bytes === false) {
                throw new BatonError(
                    'B0340',
                    'Path Dependency Could Not Be Read',
                    "Dependency source could not be read:\n    {$source->canonicalPath}",
                );
            }
            hash_update(
                $context,
                $source->relativePath . "\0" . $source->scope . "\0" . $source->origin . "\0",
            );
            hash_update($context, pack('N', strlen($bytes)) . $bytes);
        }

        return hash_final($context);
    }
}
