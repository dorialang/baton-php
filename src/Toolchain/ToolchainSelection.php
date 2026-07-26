<?php

declare(strict_types=1);

namespace Doria\Baton\Toolchain;

/** The compiler selected by B5 discovery, including its verified identity. */
final readonly class ToolchainSelection
{
    public function __construct(
        public string $compilerPath,
        public string $source,
        public CompilerIdentity $identity,
        public ?ToolchainManifest $manifest = null,
    ) {
    }

    public function manifestStatus(): string
    {
        return $this->manifest === null ? 'not used' : 'verified';
    }

    public function hashStatus(): string
    {
        return $this->manifest === null ? 'not available' : 'verified';
    }
}
