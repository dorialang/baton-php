<?php

declare(strict_types=1);

namespace Doria\Baton\Templates;

use Doria\Baton\Diagnostics\BatonError;

/**
 * Copies a template directory to a destination, substituting `{{ key }}`
 * placeholders in file contents. Templates are Baton-owned (plan B7).
 */
final class TemplateRenderer
{
    public function __construct(private readonly string $templateRoot)
    {
    }

    public static function projectTemplate(): self
    {
        return new self(dirname(__DIR__, 2) . '/templates/project');
    }

    /** @param array<string, string> $replacements */
    public function renderTo(string $destination, array $replacements): void
    {
        if (!is_dir($this->templateRoot)) {
            throw new BatonError(
                'B0103',
                'Project Template Not Found',
                "The project template is missing:\n    {$this->templateRoot}"
            );
        }

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->templateRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($this->templateRoot) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;

            if ($file->isDir()) {
                $this->makeDirectory($target);
                continue;
            }

            $this->makeDirectory(dirname($target));
            $contents = (string) file_get_contents($file->getPathname());
            file_put_contents($target, $this->substitute($contents, $replacements));
        }
    }

    /** @param array<string, string> $replacements */
    private function substitute(string $contents, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ ' . $key . ' }}', $value, $contents);
        }

        return $contents;
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new BatonError(
                'B0104',
                'Project Directory Could Not Be Created',
                "Failed to create:\n    {$directory}"
            );
        }
    }
}
