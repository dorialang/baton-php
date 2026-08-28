<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Distribution;

use Doria\Baton\Tests\TestCase;
use Symfony\Component\Process\Process;

final class RuntimeSpecTest extends TestCase
{
    public function testRuntimeInputsAndSupportedTargetsArePinned(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/packaging/php-runtime/spec.json');
        self::assertNotFalse($contents);
        /** @var array{
         *     schema: int,
         *     php: array{version: string, source: string},
         *     builder: array{
         *         name: string,
         *         version: string,
         *         assets: array<string, array{url: string, sha256: string, spcTarget: string}>
         *     },
         *     extensions: array{common: list<string>, unix: list<string>},
         *     sources: array<string, array{url: string, sha256: string, runtime: bool, targets?: list<string>}>,
         *     capabilities: list<string>
         * } $spec
         */
        $spec = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $spec['schema']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $spec['php']['version']);
        self::assertSame(
            [
                'linux-x86_64',
                'linux-aarch64',
                'macos-x86_64',
                'macos-aarch64',
                'windows-x86_64',
            ],
            array_keys($spec['builder']['assets']),
        );
        self::assertSame(['phar', 'iconv', 'filter', 'zlib'], $spec['extensions']['common']);
        self::assertSame(['pcntl', 'posix'], $spec['extensions']['unix']);
        self::assertSame(
            ['cli', 'filesystem', 'filter', 'hash', 'iconv', 'json', 'phar', 'process'],
            $spec['capabilities'],
        );

        self::assertSame('php-src', $spec['php']['source']);
        foreach ($spec['builder']['assets'] as $asset) {
            self::assertPinnedUrlAndHash($asset['url'], $asset['sha256']);
        }
        self::assertSame(
            [
                'native-native-musl',
                'native-native-musl',
                'native-macos',
                'native-macos',
                'native-windows',
            ],
            array_column($spec['builder']['assets'], 'spcTarget'),
        );
        self::assertSame(
            ['zlib', 'micro', 'frankenphp', 'php-src', 'libiconv', 'libiconv-win'],
            array_keys($spec['sources']),
        );
        foreach ($spec['sources'] as $source) {
            self::assertPinnedUrlAndHash($source['url'], $source['sha256']);
            self::assertMatchesRegularExpression(
                '/\.(?:tar\.(?:gz|xz)|tgz|zip)$/',
                parse_url($source['url'], PHP_URL_PATH) ?: '',
            );
        }
        self::assertTrue($spec['sources']['zlib']['runtime']);
        self::assertFalse($spec['sources']['micro']['runtime']);
        self::assertFalse($spec['sources']['frankenphp']['runtime']);
        self::assertTrue($spec['sources']['php-src']['runtime']);
        self::assertTrue($spec['sources']['libiconv']['runtime']);
        self::assertTrue($spec['sources']['libiconv-win']['runtime']);
        self::assertStringStartsWith(
            'https://mirrors.kernel.org/gnu/',
            $spec['sources']['libiconv']['url'],
        );
        self::assertSame(
            ['linux-x86_64', 'linux-aarch64', 'macos-x86_64', 'macos-aarch64'],
            $spec['sources']['libiconv']['targets'] ?? null,
        );
        self::assertSame(
            ['windows-x86_64'],
            $spec['sources']['libiconv-win']['targets'] ?? null,
        );
    }

    public function testRuntimePlansSelectPlatformSpecificSources(): void
    {
        $unixPlan = $this->runtimePlan('linux-x86_64');
        self::assertContains('libiconv', $unixPlan['sources']);
        self::assertNotContains('libiconv-win', $unixPlan['sources']);

        $windowsPlan = $this->runtimePlan('windows-x86_64');
        self::assertNotContains('libiconv', $windowsPlan['sources']);
        self::assertContains('libiconv-win', $windowsPlan['sources']);
    }

    public function testRuntimeBuilderRefusesToReplaceAnUnrecognizedOutputDirectory(): void
    {
        $root = $this->temporaryDirectory('runtime-output-guard');
        $output = $root . '/output';
        $work = $root . '/work';
        self::assertTrue(mkdir($output));
        self::assertTrue(mkdir($work));
        $sentinel = $output . '/belongs-to-the-user.txt';
        self::assertNotFalse(file_put_contents($sentinel, "keep me\n"));
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/packaging/php-runtime/build.php',
            '--output',
            $output,
            '--work',
            $work,
        ]);

        self::assertSame(64, $process->run());
        self::assertStringContainsString(
            'Refusing to replace an unrecognized nonempty output directory',
            $process->getErrorOutput(),
        );
        self::assertSame("keep me\n", file_get_contents($sentinel));
    }

    private static function assertPinnedUrlAndHash(string $url, string $sha256): void
    {
        self::assertStringStartsWith('https://', $url);
        self::assertStringNotContainsString('latest', strtolower($url));
        self::assertStringNotContainsString('nightly', strtolower($url));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sha256);
    }

    /** @return array{sources: list<string>} */
    private function runtimePlan(string $target): array
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/packaging/php-runtime/build.php',
            '--print-plan',
            '--target',
            $target,
        ]);
        self::assertSame(0, $process->run(), $process->getErrorOutput());

        /** @var array{sources: list<string>} */
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
