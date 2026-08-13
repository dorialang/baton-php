<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Distribution;

use Doria\Baton\Tests\TestCase;
use Symfony\Component\Process\Process;

final class LauncherTest extends TestCase
{
    public function testLauncherUsesOnlyTheRelativePrivateRuntimeWithConfigurationDisabled(): void
    {
        $fixture = $this->distributionFixture();
        $resultPath = $fixture['root'] . '/launcher-result.json';
        $iniPath = $fixture['root'] . '/hostile.ini';
        self::assertNotFalse(file_put_contents(
            $iniPath,
            'auto_prepend_file=' . $fixture['root'] . '/must-not-load.php' . PHP_EOL,
        ));

        $process = new Process(
            [$fixture['launcher'], 'one', 'two words', 'Doria-Ω'],
            $fixture['root'],
            [
                'BATON_TEST_OUTPUT' => $resultPath,
                'PATH' => $this->pathWithDecoyFirst($fixture['decoyDirectory']),
                'PHPRC' => $iniPath,
                'PHP_INI_SCAN_DIR' => $fixture['root'],
            ],
        );
        $exitCode = $process->run();

        self::assertSame(23, $exitCode, $process->getErrorOutput());
        self::assertFileDoesNotExist($fixture['decoyMarker']);
        self::assertFileExists($resultPath);
        /** @var array{
         *     binary: string,
         *     ini: string|false,
         *     scannedIni: string|false,
         *     arguments: list<string>
         * } $result
         */
        $result = json_decode(
            (string) file_get_contents($resultPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $expectedBinary = PHP_OS_FAMILY === 'Windows'
            ? $fixture['runtime']
            : realpath($fixture['runtime']);
        self::assertIsString($expectedBinary);
        self::assertSame(
            $this->nativePath($expectedBinary),
            $this->nativePath($result['binary']),
        );
        self::assertFalse($result['ini']);
        self::assertFalse($result['scannedIni']);
        self::assertSame(['one', 'two words', 'Doria-Ω'], $result['arguments']);
    }

    public function testLauncherFailsInsteadOfFallingBackToPathWhenRuntimeIsMissing(): void
    {
        $fixture = $this->distributionFixture(installRuntime: false);
        $process = new Process(
            [$fixture['launcher'], '--version'],
            $fixture['root'],
            ['PATH' => $this->pathWithDecoyFirst($fixture['decoyDirectory'])],
        );

        self::assertSame(70, $process->run());
        self::assertStringContainsString('private PHP runtime is missing', $process->getErrorOutput());
        self::assertFileDoesNotExist($fixture['decoyMarker']);
    }

    public function testPosixLauncherResolvesTheToolchainThroughASymlink(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The Windows launcher does not use POSIX symlinks.');
        }

        $fixture = $this->distributionFixture();
        $linkDirectory = $fixture['root'] . '/linked command';
        self::assertTrue(mkdir($linkDirectory));
        $launcherLink = $linkDirectory . '/baton';
        self::assertTrue(symlink('../bin/baton', $launcherLink));
        $resultPath = $fixture['root'] . '/symlink-result.json';
        $process = new Process(
            [$launcherLink, 'through-link'],
            $fixture['root'],
            [
                'BATON_TEST_OUTPUT' => $resultPath,
                'PATH' => $this->pathWithDecoyFirst($fixture['decoyDirectory']),
            ],
        );

        self::assertSame(23, $process->run(), $process->getErrorOutput());
        /** @var array{arguments: list<string>} $result */
        $result = json_decode(
            (string) file_get_contents($resultPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(['through-link'], $result['arguments']);
        self::assertFileDoesNotExist($fixture['decoyMarker']);
    }

    /**
     * @return array{
     *     root: string,
     *     launcher: string,
     *     runtime: string,
     *     decoyDirectory: string,
     *     decoyMarker: string
     * }
     */
    private function distributionFixture(bool $installRuntime = true): array
    {
        $root = $this->temporaryDirectory('Doria toolchain Ω');
        $binDirectory = $root . '/bin';
        $runtimeDirectory = $root . '/libexec/doria/php/bin';
        $applicationDirectory = $root . '/libexec/doria';
        self::assertTrue(mkdir($binDirectory, 0o755, true));
        self::assertTrue(mkdir($runtimeDirectory, 0o755, true));

        $windows = PHP_OS_FAMILY === 'Windows';
        $launcherName = $windows ? 'baton.cmd' : 'baton';
        $launcher = "{$binDirectory}/{$launcherName}";
        $launcherSource = dirname(__DIR__, 2) . "/packaging/launchers/{$launcherName}";
        self::assertTrue(copy($launcherSource, $launcher));
        if (!$windows) {
            self::assertTrue(chmod($launcher, 0o755));
        }

        $runtime = $runtimeDirectory . '/php' . ($windows ? '.exe' : '');
        if ($installRuntime) {
            self::assertTrue($windows
                ? copy(PHP_BINARY, $runtime)
                : symlink(PHP_BINARY, $runtime));
        }

        $application = $applicationDirectory . '/baton.phar';
        self::assertNotFalse(file_put_contents($application, <<<'PHP'
<?php

file_put_contents(
    getenv('BATON_TEST_OUTPUT'),
    json_encode([
        'binary' => PHP_BINARY,
        'ini' => php_ini_loaded_file(),
        'scannedIni' => php_ini_scanned_files(),
        'arguments' => array_slice($argv, 1),
    ], JSON_THROW_ON_ERROR),
);

exit(23);
PHP));

        $decoyDirectory = $root . '/host-path';
        $decoyMarker = $root . '/path-php-was-used';
        self::assertTrue(mkdir($decoyDirectory, 0o755));
        if ($windows) {
            $decoy = "{$decoyDirectory}/php.cmd";
            self::assertNotFalse(file_put_contents(
                $decoy,
                "@echo off\r\ntype nul > \"{$decoyMarker}\"\r\nexit /b 99\r\n",
            ));
        } else {
            $decoy = "{$decoyDirectory}/php";
            $this->writeExecutable(
                $decoy,
                "#!/bin/sh\n: > " . escapeshellarg($decoyMarker) . "\nexit 99\n",
            );
        }

        return [
            'root' => $root,
            'launcher' => $launcher,
            'runtime' => $runtime,
            'decoyDirectory' => $decoyDirectory,
            'decoyMarker' => $decoyMarker,
        ];
    }

    private function pathWithDecoyFirst(string $decoyDirectory): string
    {
        $path = getenv('PATH');

        return $decoyDirectory . PATH_SEPARATOR . (is_string($path) ? $path : '');
    }
}
