<?php

declare(strict_types=1);

namespace Doria\Baton\Toolchain;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Compiler\CompilerResult;
use JsonException;

/** Proves that the selected compiler can produce a native host executable. */
final class NativeCompilerProbe
{
    private const TIMEOUT_SECONDS = 30.0;

    /** Returns null on success or a concise structured diagnostic on failure. */
    public function check(string $compiler): ?string
    {
        $directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'baton-native-compiler-probe-'
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            return 'could not create a temporary native-compile workspace';
        }

        try {
            $source = $directory . DIRECTORY_SEPARATOR . 'main.doria';
            $output = $directory
                . DIRECTORY_SEPARATOR
                . 'main'
                . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            $program = <<<'DORIA'
function main(): void
{
    echo "doctor runtime probe\n";
}
DORIA;
            if (file_put_contents($source, $program . "\n") === false) {
                return 'could not write the temporary native-compile source';
            }

            $result = (new CompilerAdapter($compiler))->capture(
                [
                    'compile',
                    $source,
                    '--target',
                    'native',
                    '--out',
                    $output,
                    '--diagnostic-format',
                    'json',
                    '--diagnostic-color',
                    'never',
                ],
                $directory,
                self::TIMEOUT_SECONDS,
            );
            if (!$result->succeeded()) {
                return $this->failureDetail($result);
            }
            if (!is_file($output)) {
                return 'compiler reported success without creating the native executable';
            }

            return null;
        } finally {
            $this->removeTree($directory);
        }
    }

    private function failureDetail(CompilerResult $result): string
    {
        try {
            $document = json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $document = null;
        }
        $diagnostic = is_array($document)
            && ($document['schemaVersion'] ?? null) === 1
            && is_array($document['diagnostics'] ?? null)
            ? ($document['diagnostics'][0] ?? null)
            : null;
        if (is_array($diagnostic)) {
            $code = $diagnostic['code'] ?? null;
            $title = $diagnostic['title'] ?? null;
            $message = $diagnostic['message'] ?? null;
            if (is_string($code) && $code !== '' && is_string($title) && $title !== '') {
                $detail = "[{$code}] {$title}";
                if (is_string($message) && trim($message) !== '') {
                    $detail .= ': ' . $this->oneLine($message);
                }

                return $detail;
            }
        }

        return "compiler exited with code {$result->exitCode}; structured diagnostics were unavailable";
    }

    private function oneLine(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        if (strlen($value) <= 240) {
            return $value;
        }

        return substr($value, 0, 237) . '...';
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}
