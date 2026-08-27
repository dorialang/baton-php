<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

final readonly class TargetCollection
{
    /**
     * @param list<BinaryTarget> $binaries
     */
    public function __construct(
        public ?LibraryTarget $library,
        public array $binaries,
    ) {
    }

    /** @return list<PackageTarget> */
    public function all(): array
    {
        return $this->library === null
            ? $this->binaries
            : [$this->library, ...$this->binaries];
    }

    public function binary(string $name): ?BinaryTarget
    {
        foreach ($this->binaries as $binary) {
            if ($binary->targetName === $name) {
                return $binary;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function binaryNames(): array
    {
        return array_map(
            static fn (BinaryTarget $target): string => $target->targetName,
            $this->binaries,
        );
    }
}
