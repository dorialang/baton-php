<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Closure;
use Doria\Baton\Application;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\CompilerIdentity;
use Doria\Baton\Toolchain\Platform;
use Doria\Baton\Toolchain\ToolchainLocator;

final class ToolchainLocatorTest extends TestCase
{
    private Platform $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new Platform('linux', 'x86_64');
    }

    public function testExplicitOverrideWinsBeforeEveryInstalledAndDevelopmentSource(): void
    {
        $root = $this->temporaryDirectory('override');
        $baton = $this->batonExecutable($root);
        $override = $root . '/override/' . $this->compilerName();
        $environment = $root . '/environment/' . $this->compilerName();
        $this->writeExecutable($override);
        $this->writeExecutable($environment);
        $this->writeExecutable(dirname($baton) . '/' . $this->compilerName());

        $selection = (new ToolchainLocator(
            $override,
            true,
            $baton,
            ['BATON_DORIAC' => $environment, 'PATH' => dirname($environment)],
            $this->host,
            $this->identityProbe(),
        ))->locate();

        self::assertSame($override, $selection->compilerPath);
        self::assertSame('--compiler development override', $selection->source);
    }

    public function testVerifiedManifestWinsBeforeCompilerBesideBaton(): void
    {
        $root = $this->temporaryDirectory('manifest');
        $baton = $this->batonExecutable($root);
        $compiler = $root . '/components/' . $this->compilerName();
        $languageServer = $root . '/components/' . $this->languageServerName();
        $this->writeExecutable($compiler, "manifest compiler\n");
        $this->writeExecutable($languageServer, "manifest language server\n");
        $this->writeExecutable(dirname($baton) . '/' . $this->compilerName(), "beside\n");
        $this->writeManifest(
            $root,
            'components/' . $this->compilerName(),
            $compiler,
            languageServerPath: $languageServer,
        );

        $selection = (new ToolchainLocator(
            null,
            false,
            $baton,
            ['PATH' => ''],
            $this->host,
            $this->identityProbe(),
        ))->locate();

        self::assertSame($compiler, $selection->compilerPath);
        self::assertSame('toolchain.json', $selection->source);
        self::assertSame('verified', $selection->manifestStatus());
        self::assertSame('verified', $selection->hashStatus());
    }

    public function testCompilerBesideBatonWinsBeforeDevelopmentOverrides(): void
    {
        $root = $this->temporaryDirectory('beside');
        $baton = $this->batonExecutable($root);
        $beside = dirname($baton) . '/' . $this->compilerName();
        $environment = $root . '/environment/' . $this->compilerName();
        $this->writeExecutable($beside);
        $this->writeExecutable($environment);

        $selection = (new ToolchainLocator(
            null,
            true,
            $baton,
            ['BATON_DORIAC' => $environment, 'PATH' => dirname($environment)],
            $this->host,
            $this->identityProbe(),
        ))->locate();

        self::assertSame($beside, $selection->compilerPath);
        self::assertSame('compiler beside Baton', $selection->source);
    }

    public function testPublicModeDoesNotConsultEnvironmentOrPath(): void
    {
        $root = $this->temporaryDirectory('public');
        $baton = $this->batonExecutable($root);
        $environment = $root . '/environment/' . $this->compilerName();
        $this->writeExecutable($environment);

        $locator = new ToolchainLocator(
            null,
            false,
            $baton,
            ['BATON_DORIAC' => $environment, 'PATH' => dirname($environment)],
            $this->host,
            $this->identityProbe(),
        );

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Doria Compiler Not Found');
        $locator->locate();
    }

    public function testDevelopmentModeUsesEnvironmentBeforePath(): void
    {
        $root = $this->temporaryDirectory('development-environment');
        $baton = $this->batonExecutable($root);
        $environment = $root . '/environment/' . $this->compilerName();
        $onPath = $root . '/path/' . $this->compilerName();
        $this->writeExecutable($environment);
        $this->writeExecutable($onPath);

        $selection = (new ToolchainLocator(
            null,
            true,
            $baton,
            ['BATON_DORIAC' => $environment, 'PATH' => dirname($onPath)],
            $this->host,
            $this->identityProbe(),
        ))->locate();

        self::assertSame($environment, $selection->compilerPath);
        self::assertSame('BATON_DORIAC development override', $selection->source);
    }

    public function testDevelopmentModeCanUsePathAsTheLastResort(): void
    {
        $root = $this->temporaryDirectory('development-path');
        $baton = $this->batonExecutable($root);
        $onPath = $root . '/path/' . $this->compilerName();
        $this->writeExecutable($onPath);

        $selection = (new ToolchainLocator(
            null,
            true,
            $baton,
            ['PATH' => dirname($onPath)],
            $this->host,
            $this->identityProbe(),
        ))->locate();

        self::assertSame($onPath, $selection->compilerPath);
        self::assertSame('development PATH', $selection->source);
    }

    public function testManifestHashMismatchIsRejectedBeforeCompilerExecution(): void
    {
        $root = $this->temporaryDirectory('hash');
        $baton = $this->batonExecutable($root);
        $compiler = $root . '/components/' . $this->compilerName();
        $this->writeExecutable($compiler, "actual compiler\n");
        $this->writeManifest(
            $root,
            'components/' . $this->compilerName(),
            $compiler,
            str_repeat('0', 64),
        );

        $locator = new ToolchainLocator(
            null,
            false,
            $baton,
            ['PATH' => ''],
            $this->host,
            static function (): CompilerIdentity {
                self::fail('The mismatched component must not be executed.');
            },
        );

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Invalid Toolchain Manifest');
        $locator->locate();
    }

    public function testManifestLanguageServerHashMismatchIsRejected(): void
    {
        $root = $this->temporaryDirectory('language-server-hash');
        $baton = $this->batonExecutable($root);
        $compiler = $root . '/components/' . $this->compilerName();
        $languageServer = $root . '/components/' . $this->languageServerName();
        $this->writeExecutable($compiler, "compiler\n");
        $this->writeExecutable($languageServer, "language server\n");
        $this->writeManifest(
            $root,
            'components/' . $this->compilerName(),
            $compiler,
            languageServerPath: $languageServer,
        );
        self::assertNotFalse(file_put_contents($languageServer, "tampered\n"));

        $locator = new ToolchainLocator(
            null,
            false,
            $baton,
            ['PATH' => ''],
            $this->host,
            $this->identityProbe(),
        );

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Invalid Toolchain Manifest');
        $locator->locate();
    }

    public function testManifestCompilerSymlinkCannotEscapeToolchainRoot(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows symlink creation requires host policy support.');
        }

        $root = $this->temporaryDirectory('symlink-root');
        $outside = $this->temporaryDirectory('symlink-outside');
        $baton = $this->batonExecutable($root);
        $outsideCompiler = $outside . '/' . $this->compilerName();
        $linkedCompiler = $root . '/components/' . $this->compilerName();
        $this->writeExecutable($outsideCompiler);
        self::assertTrue(mkdir(dirname($linkedCompiler), 0o755, true));
        self::assertTrue(symlink($outsideCompiler, $linkedCompiler));
        $this->writeManifest(
            $root,
            'components/' . $this->compilerName(),
            $linkedCompiler,
        );

        $locator = new ToolchainLocator(
            null,
            false,
            $baton,
            ['PATH' => ''],
            $this->host,
            $this->identityProbe(),
        );

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Invalid Toolchain Manifest');
        $locator->locate();
    }

    private function batonExecutable(string $root): string
    {
        $path = $root . '/bin/baton';
        $this->writeExecutable($path, "baton\n");

        return $path;
    }

    /** @return Closure(string): CompilerIdentity */
    private function identityProbe(): Closure
    {
        return fn (string $path): CompilerIdentity => new CompilerIdentity(
            1,
            'doriac',
            Application::VERSION,
            $this->host->target(),
            hash('sha256', $path),
        );
    }

    private function writeManifest(
        string $root,
        string $relativeCompilerPath,
        string $compilerPath,
        ?string $hash = null,
        ?string $languageServerPath = null,
    ): void {
        $languageServerPath ??= $root . '/components/' . $this->languageServerName();
        if (!is_file($languageServerPath)) {
            $this->writeExecutable($languageServerPath, "language server\n");
        }
        $manifest = [
            'schema' => 1,
            'toolchainVersion' => Application::VERSION,
            'channel' => 'canary',
            'platform' => $this->host->name,
            'architecture' => $this->host->architecture,
            'components' => [
                'doriac' => [
                    'version' => Application::VERSION,
                    'path' => $relativeCompilerPath,
                    'sha256' => $hash ?? hash_file('sha256', $compilerPath),
                ],
                'doria-lsp' => [
                    'version' => Application::VERSION,
                    'path' => 'components/' . $this->languageServerName(),
                    'sha256' => hash_file('sha256', $languageServerPath),
                ],
            ],
        ];
        self::assertNotFalse(file_put_contents(
            $root . '/toolchain.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        ));
    }
}
