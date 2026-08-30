<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Source\SourceInventory;

final readonly class ResolvedPackage
{
    /** @param array<string, SourceInventory> $targetInventories */
    public function __construct(
        public Schema2Manifest $manifest,
        public ResolvedPackageSource $source,
        public string $manifestFingerprint,
        public SourceInventory $inventory,
        private array $targetInventories = [],
    ) {
    }

    public function hasInventory(SelectedPackageTarget $target): bool
    {
        return isset($this->targetInventories[$this->targetKey($target)]);
    }

    public function inventoryFor(SelectedPackageTarget $target): SourceInventory
    {
        $inventory = $this->targetInventories[$this->targetKey($target)] ?? null;
        if ($inventory !== null) {
            return $inventory;
        }
        if ($this->targetInventories === []) {
            return $this->inventory;
        }

        throw new \LogicException("Resolved package inventory is missing for {$target->kind()} target {$target->name()}.");
    }

    public function withInventory(
        SelectedPackageTarget $target,
        SourceInventory $inventory,
        bool $canonical = false,
    ): self {
        $inventories = $this->targetInventories;
        $inventories[$this->targetKey($target)] = $inventory;
        ksort($inventories, SORT_STRING);

        return new self(
            $this->manifest,
            $this->source,
            $this->manifestFingerprint,
            $canonical ? $inventory : $this->inventory,
            $inventories,
        );
    }

    private function targetKey(SelectedPackageTarget $target): string
    {
        return $target->kind() . "\0" . $target->name();
    }
}
