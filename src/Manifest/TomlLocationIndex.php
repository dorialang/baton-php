<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

use PhpCollective\Toml\Ast\Document;
use PhpCollective\Toml\Ast\KeyValue;
use PhpCollective\Toml\Ast\Table;
use PhpCollective\Toml\Ast\Value\InlineTable;
use PhpCollective\Toml\Lexer\Span;

/** Key-path locations retained from the parser AST for manifest diagnostics. */
final class TomlLocationIndex
{
    /** @var array<string, Span> */
    private array $locations = [];

    public function __construct(Document $document)
    {
        foreach ($document->items as $item) {
            if ($item instanceof Table) {
                $tablePath = $item->key->toString();
                $this->locations[$tablePath] ??= $item->getSpan();
                foreach ($item->items as $value) {
                    $this->indexValue($value, $tablePath);
                }
            } else {
                $this->indexValue($item, '');
            }
        }
    }

    public function describe(string $manifestPath, string $keyPath): string
    {
        $span = $this->locationFor($keyPath);
        $location = $span === null
            ? $manifestPath
            : "{$manifestPath}:{$span->line}:" . ($span->column + 1);

        return $keyPath === '' ? $location : "{$location}\nField: {$keyPath}";
    }

    private function indexValue(KeyValue $value, string $parent): void
    {
        $path = $parent === ''
            ? $value->key->toString()
            : $parent . '.' . $value->key->toString();
        $this->locations[$path] ??= $value->getSpan();

        if ($value->value instanceof InlineTable) {
            foreach ($value->value->items as $child) {
                $this->indexValue($child, $path);
            }
        }
    }

    private function locationFor(string $keyPath): ?Span
    {
        if (isset($this->locations[$keyPath])) {
            return $this->locations[$keyPath];
        }

        while (($separator = strrpos($keyPath, '.')) !== false) {
            $keyPath = substr($keyPath, 0, $separator);
            if (isset($this->locations[$keyPath])) {
                return $this->locations[$keyPath];
            }
        }

        return null;
    }
}
