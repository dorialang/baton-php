<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use PhpCollective\Toml\Toml;
use PhpCollective\Toml\TomlVersion;

final class WorkspaceDeclarationProbe
{
    public function declares(string $manifestPath): bool
    {
        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            return false;
        }

        $result = Toml::tryParse($contents, TomlVersion::V10);
        $values = $result->getValue();
        if (is_array($values) && array_key_exists('workspace', $values)) {
            return true;
        }

        $document = $result->getDocument();
        if ($document === null) {
            return false;
        }
        foreach ($document->items as $item) {
            if (($item->key->parts[0] ?? null) === 'workspace') {
                return true;
            }
        }

        return false;
    }
}
