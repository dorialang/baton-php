<?php

declare(strict_types=1);

namespace Doria\Baton\Workspace;

use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;

final readonly class WorkspaceContext
{
    /** @param array<string, WorkspaceMember> $members Package name to member. */
    public function __construct(
        public string $root,
        public Schema2Manifest|WorkspaceManifest $rootManifest,
        public array $members,
    ) {
    }

    public function memberForRoot(string $root): ?WorkspaceMember
    {
        $canonical = realpath($root) ?: $root;
        foreach ($this->members as $member) {
            if ($member->root === $canonical) {
                return $member;
            }
        }

        return null;
    }

    /** @return list<WorkspaceMember> */
    public function sortedMembers(): array
    {
        $members = array_values($this->members);
        usort($members, static fn (WorkspaceMember $left, WorkspaceMember $right): int => strcmp(
            $left->manifest->package->compilerIdentity,
            $right->manifest->package->compilerIdentity,
        ));

        return $members;
    }

    public function replacingMember(string $root, Schema2Manifest $manifest): self
    {
        $members = $this->members;
        foreach ($members as $package => $member) {
            if ($member->root !== $root) {
                continue;
            }
            unset($members[$package]);
            $members[$manifest->package->name] = new WorkspaceMember(
                $member->root,
                $member->relativePath,
                $member->manifestPath,
                $manifest,
            );

            return new self($this->root, $this->rootManifest, $members);
        }

        throw new \LogicException('Selected workspace member is missing.');
    }
}
