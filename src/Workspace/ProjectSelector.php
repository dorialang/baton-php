<?php

declare(strict_types=1);

namespace Doria\Baton\Workspace;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;

final class ProjectSelector
{
    public function select(
        ProjectEnvironment $environment,
        ?string $package,
        bool $workspace,
        bool $allowWorkspace,
        string $command,
    ): ProjectSelection {
        if ($package !== null && $workspace) {
            throw $this->error(
                'Workspace Selectors Conflict',
                '`--package <name>` and `--workspace` are mutually exclusive.',
            );
        }
        if ($workspace && !$allowWorkspace) {
            throw $this->error(
                'Workspace Mode Is Not Supported',
                "`baton {$command}` operates on one selected package and does not accept `--workspace`.",
            );
        }
        if ($environment->workspace === null) {
            if ($package !== null || $workspace) {
                throw $this->error(
                    'Workspace Package Selection Is Unavailable',
                    'This project is not a workspace.',
                );
            }
            if ($environment->manifest instanceof WorkspaceManifest) {
                throw new \LogicException('A virtual manifest requires a discovered workspace.');
            }

            return new ProjectSelection(
                $environment->commandRoot,
                $environment->lockRoot,
                $environment->manifest,
                null,
                false,
            );
        }
        if ($workspace) {
            return new ProjectSelection(
                $environment->workspace->root,
                $environment->workspace->root,
                null,
                $environment->workspace,
                true,
            );
        }

        $member = $package === null
            ? $environment->workspace->memberForRoot($environment->commandRoot)
            : ($environment->workspace->members[$package] ?? null);
        if ($member === null) {
            if ($package === null) {
                throw $this->error(
                    'Workspace Package Selection Is Ambiguous',
                    'Select one workspace member with `--package <manifest-package-name>`.',
                );
            }
            $available = array_keys($environment->workspace->members);
            sort($available, SORT_STRING);
            throw $this->error(
                'Workspace Package Is Unknown',
                "Package `{$package}` is not a workspace member.\nAvailable packages: " . implode(', ', $available),
            );
        }

        return new ProjectSelection(
            $member->root,
            $environment->workspace->root,
            $member->manifest,
            $environment->workspace,
            false,
        );
    }

    private function error(string $heading, string $body): BatonError
    {
        return new BatonError('B0398', $heading, $body);
    }
}
