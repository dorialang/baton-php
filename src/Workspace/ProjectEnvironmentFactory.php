<?php

declare(strict_types=1);

namespace Doria\Baton\Workspace;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ManifestLoader;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\WorkspaceManifest;
use Doria\Baton\Project\ProjectLocator;

final class ProjectEnvironmentFactory
{
    public function create(string $startDirectory): ProjectEnvironment
    {
        $nearest = (new ProjectLocator())->locate($startDirectory);
        $loader = new ManifestLoader();
        $nearestManifest = $loader->load($nearest);
        $directory = $nearest;
        while (true) {
            if (is_file($directory . DIRECTORY_SEPARATOR . ProjectLocator::MANIFEST_FILE)) {
                $candidate = $loader->load($directory);
                $workspaceDeclared = $candidate instanceof WorkspaceManifest
                    || ($candidate instanceof Schema2Manifest && $candidate->workspace !== null);
                if ($workspaceDeclared) {
                    $workspace = (new WorkspaceDiscovery())->discover($directory, $candidate);
                    $member = $workspace->memberForRoot($nearest);
                    if ($member !== null) {
                        return new ProjectEnvironment($member->root, $workspace->root, $member->manifest, $workspace);
                    }
                    if ($nearest === $workspace->root && $candidate instanceof WorkspaceManifest) {
                        return new ProjectEnvironment($workspace->root, $workspace->root, $candidate, $workspace);
                    }
                    throw new BatonError(
                        'B0398',
                        'Workspace Package Selection Is Ambiguous',
                        'The current package is below a workspace root but is not a declared member.',
                    );
                }
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return new ProjectEnvironment($nearest, $nearest, $nearestManifest, null);
    }
}
