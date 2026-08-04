<?php

declare(strict_types=1);

namespace Doria\Baton\Toolchain;

use Closure;
use Doria\Baton\Application;
use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Diagnostics\BatonError;

/**
 * Resolves and validates `doriac` using the B5 discovery contract.
 *
 * Public mode never consults BATON_DORIAC or PATH. Those sources are available
 * only through the explicit development mode, preventing an unrelated compiler
 * installation from silently replacing the compiler shipped with Baton.
 */
final class ToolchainLocator
{
    private const IDENTITY_TIMEOUT_SECONDS = 10.0;

    private readonly string $batonExecutable;

    /** @var array<string, string> */
    private readonly array $environment;

    /** @var Closure(string): CompilerIdentity */
    private readonly Closure $identityProbe;

    /**
     * @param array<string, string>|null       $environment
     * @param Closure(string): CompilerIdentity|null $identityProbe
     */
    public function __construct(
        private readonly ?string $override = null,
        private readonly bool $developmentMode = false,
        ?string $batonExecutable = null,
        ?array $environment = null,
        private readonly ?Platform $host = null,
        ?Closure $identityProbe = null,
    ) {
        $runtimeArguments = $_SERVER['argv'] ?? null;
        $runtimeExecutable = is_array($runtimeArguments)
            && is_string($runtimeArguments[0] ?? null)
            ? $runtimeArguments[0]
            : 'baton';
        $this->environment = $environment ?? self::currentEnvironment();
        $this->batonExecutable = $this->resolveBatonExecutable(
            $batonExecutable ?? $runtimeExecutable
        );
        $this->identityProbe = $identityProbe ?? static function (string $path): CompilerIdentity {
            $result = (new CompilerAdapter($path))->capture(
                ['--version', '--json'],
                timeoutSeconds: self::IDENTITY_TIMEOUT_SECONDS,
            );

            return CompilerIdentity::fromResult($result, $path);
        };
    }

    public function locate(): ToolchainSelection
    {
        $host = $this->host ?? Platform::host();

        if ($this->override !== null && trim($this->override) !== '') {
            return $this->select(
                $this->absolutePath(trim($this->override)),
                '--compiler development override',
                $host,
            );
        }

        $manifestPath = $this->installedManifestPath();
        if ($manifestPath !== null) {
            $manifest = ToolchainManifest::load($manifestPath);
            $manifest->assertCompatible(Application::VERSION, $host);
            $path = $this->requireExecutable(
                $manifest->compilerPath,
                'toolchain.json'
            );
            $manifest->verifyCompilerHash();
            $manifest->verifyLanguageServerHash();

            return $this->select($path, 'toolchain.json', $host, $manifest);
        }

        $besideBaton = dirname($this->batonExecutable)
            . DIRECTORY_SEPARATOR
            . $this->compilerExecutableName();
        if (is_file($besideBaton)) {
            return $this->select($besideBaton, 'compiler beside Baton', $host);
        }

        if ($this->developmentMode) {
            $fromEnvironment = $this->environment['BATON_DORIAC'] ?? '';
            if (trim($fromEnvironment) !== '') {
                return $this->select(
                    $this->absolutePath(trim($fromEnvironment)),
                    'BATON_DORIAC development override',
                    $host,
                );
            }

            $onPath = $this->searchPath($this->compilerExecutableName());
            if ($onPath !== null) {
                return $this->select($onPath, 'development PATH', $host);
            }
        }

        $developmentHelp = $this->developmentMode
            ? "No BATON_DORIAC override or doriac executable was found on the development PATH."
            : "For source development, pass `--compiler <path>` or opt into\n"
                . "`--development` before using BATON_DORIAC or PATH.";

        throw new BatonError(
            'B0202',
            'Doria Compiler Not Found',
            "Baton could not find a compiler recorded in toolchain.json or beside:\n"
                . "    {$this->batonExecutable}\n\n{$developmentHelp}"
        );
    }

    private function select(
        string $path,
        string $source,
        Platform $host,
        ?ToolchainManifest $manifest = null,
    ): ToolchainSelection {
        $path = $this->requireExecutable($path, $source);
        $identity = ($this->identityProbe)($path);
        $identity->assertCompatible(Application::VERSION, $host, $path);

        return new ToolchainSelection($path, $source, $identity, $manifest);
    }

    private function installedManifestPath(): ?string
    {
        $candidates = [
            dirname(dirname($this->batonExecutable)) . DIRECTORY_SEPARATOR . 'toolchain.json',
        ];
        $resolved = realpath($this->batonExecutable);
        if ($resolved !== false) {
            $candidates[] = dirname(dirname($resolved)) . DIRECTORY_SEPARATOR . 'toolchain.json';
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function requireExecutable(string $path, string $source): string
    {
        if (!is_file($path) || !$this->isExecutable($path)) {
            throw new BatonError(
                'B0202',
                'Doria Compiler Not Found',
                "The compiler selected from {$source} is missing or not executable:\n"
                    . "    {$path}"
            );
        }
        if ($this->isDoriaSourceLauncher($path)) {
            throw new BatonError(
                'B0201',
                'Doria Source Launcher Is Not A Toolchain Component',
                "The compiler selected from {$source} is Doria's Cargo-backed source launcher:\n"
                    . "    {$path}\n\n"
                    . 'Install a compiled doriac artifact and select that executable instead.'
            );
        }

        return $path;
    }

    private function isDoriaSourceLauncher(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $prefix = fread($handle, 8192);
        fclose($handle);

        return is_string($prefix)
            && str_contains($prefix, 'Development launcher for `doriac` (cargo run from source).');
    }

    private function resolveBatonExecutable(string $path): string
    {
        if ($this->isAbsolute($path)) {
            return $path;
        }
        if (str_contains($path, '/') || str_contains($path, '\\')) {
            return $this->absolutePath($path);
        }

        return $this->searchPath($path) ?? $this->absolutePath($path);
    }

    private function absolutePath(string $path): string
    {
        if ($this->isAbsolute($path)) {
            return $path;
        }

        return (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function searchPath(string $name): ?string
    {
        $pathEnvironment = $this->environment['PATH'] ?? '';
        if ($pathEnvironment === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $pathEnvironment) as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && $this->isExecutable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isExecutable(string $path): bool
    {
        return PHP_OS_FAMILY === 'Windows' || is_executable($path);
    }

    private function compilerExecutableName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'doriac.exe' : 'doriac';
    }

    /** @return array<string, string> */
    private static function currentEnvironment(): array
    {
        return getenv();
    }
}
