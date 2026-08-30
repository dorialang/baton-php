<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

use Doria\Baton\Workspace\WorkspaceContext;

final class WorkspaceFingerprint
{
    public function calculate(WorkspaceContext $workspace): string
    {
        $members = [];
        $fingerprints = new ManifestFingerprint();
        foreach ($workspace->sortedMembers() as $member) {
            $members[] = [
                'package' => $member->manifest->package->name,
                'compilerPackage' => $member->manifest->package->compilerIdentity,
                'path' => $member->relativePath,
                'manifestFingerprint' => $fingerprints->calculate($member->manifest),
            ];
        }

        $definition = $workspace->rootManifest->workspace;
        if ($definition === null) {
            throw new \LogicException('A discovered workspace must retain its workspace definition.');
        }
        $patterns = $definition->members;

        return hash('sha256', json_encode(
            ['patterns' => $patterns, 'members' => $members],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
