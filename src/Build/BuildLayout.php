<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Project\ProjectPathResolver;

final readonly class BuildLayout
{
    public string $directory;

    public string $buildPlan;

    public string $receipt;

    public string $artifact;

    public function __construct(
        string $projectRoot,
        string $hostTarget,
        string $profile,
        string $targetName,
    ) {
        $this->directory = $projectRoot
            . DIRECTORY_SEPARATOR . 'build'
            . DIRECTORY_SEPARATOR . $hostTarget
            . DIRECTORY_SEPARATOR . $profile
            . DIRECTORY_SEPARATOR . $targetName;
        $this->createContainedDirectory($projectRoot);
        $this->buildPlan = $this->directory . DIRECTORY_SEPARATOR . 'build-plan.json';
        $this->receipt = $this->directory . DIRECTORY_SEPARATOR . 'build.json';
        $this->artifact = $this->directory
            . DIRECTORY_SEPARATOR . $targetName
            . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    private function createContainedDirectory(string $projectRoot): void
    {
        $resolver = new ProjectPathResolver($projectRoot);
        $relative = substr($this->directory, strlen($projectRoot) + 1);
        $current = $projectRoot;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (file_exists($current) || is_link($current)) {
                $canonical = realpath($current);
                if ($canonical === false || !$resolver->containsCanonical($canonical)) {
                    throw $this->error('Managed Build Path Escapes Project', $current);
                }
                if (!is_dir($current)) {
                    throw $this->error('Build Output Could Not Be Prepared', $current);
                }
                continue;
            }
            if (!@mkdir($current, 0o755)) {
                throw $this->error('Build Output Could Not Be Prepared', $current);
            }
            $canonical = realpath($current);
            if ($canonical === false || !$resolver->containsCanonical($canonical)) {
                throw $this->error('Managed Build Path Escapes Project', $current);
            }
        }
    }

    private function error(string $heading, string $path): BatonError
    {
        return new BatonError(
            'B0401',
            $heading,
            "Managed path:\n    {$path}",
            ['Correct the build path or permissions, then retry:'],
            ['baton doctor'],
        );
    }
}
