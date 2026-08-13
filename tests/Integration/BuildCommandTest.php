<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Integration;

use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;

final class BuildCommandTest extends TestCase
{
    public function testBuildCreatesSeparateDeterministicProfilesAndMetadata(): void
    {
        $root = $this->project();
        $compiler = $this->writeFakeCompiler($root);
        $target = Platform::host()->target();
        $executable = PHP_OS_FAMILY === 'Windows' ? 'build-fixture.exe' : 'build-fixture';
        $development = $this->nativePath("{$root}/build/{$target}/development/{$executable}");
        $release = $this->nativePath("{$root}/build/{$target}/release/{$executable}");

        $developmentResult = $this->runBaton(
            ['build', '--compiler', $compiler],
            $root . '/nested/directory',
        );
        self::assertSame(0, $developmentResult['exitCode']);
        self::assertSame($development . "\n", $developmentResult['stdout']);
        self::assertSame('', $developmentResult['stderr']);
        self::assertSame('development artifact', file_get_contents($development));

        $releaseResult = $this->runBaton(
            ['build', '--release', '--compiler', $compiler],
            $root,
        );
        self::assertSame(0, $releaseResult['exitCode']);
        self::assertSame($release . "\n", $releaseResult['stdout']);
        self::assertSame('', $releaseResult['stderr']);
        self::assertSame('release artifact', file_get_contents($release));
        self::assertSame('development artifact', file_get_contents($development));

        $this->assertMetadata($development, 'development', $target);
        $this->assertMetadata($release, 'release', $target);
    }

    public function testFailedCompilationRemovesStaleOutputsAndPreservesCompilerFailure(): void
    {
        $root = $this->project("invalid\n");
        $compiler = $this->writeFakeCompiler($root);
        $profile = $root . '/build/' . Platform::host()->target() . '/development';
        self::assertTrue(mkdir($profile, 0o755, true));
        $artifact = $profile
            . '/'
            . (PHP_OS_FAMILY === 'Windows' ? 'build-fixture.exe' : 'build-fixture');
        self::assertNotFalse(file_put_contents($artifact, 'stale executable'));
        self::assertNotFalse(file_put_contents($profile . '/build.json', '{"stale":true}'));

        $result = $this->runBaton(['build', '--compiler', $compiler], $root);

        self::assertSame(17, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertSame("compiler diagnostic unchanged\n", $result['stderr']);
        self::assertFileDoesNotExist($artifact);
        self::assertFileDoesNotExist($profile . '/build.json');
    }

    public function testSuccessfulCompilerWithoutArtifactIsRejected(): void
    {
        $root = $this->project("no-output\n");
        $compiler = $this->writeFakeCompiler($root);

        $result = $this->runBaton(['build', '--compiler', $compiler], $root);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString(
            'Error[B0402]: Compiler Did Not Produce Build Artifact',
            $result['stderr'],
        );
    }

    private function project(string $source = "function main(): void {}\n"): string
    {
        $root = $this->temporaryDirectory('build project with spaces');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/nested/directory', 0o755, true));
        self::assertNotFalse(file_put_contents($root . '/Baton.toml', <<<'TOML'
manifest-version = 1

[package]
name = "build-fixture"
version = "1.2.3"
kind = "binary"
entry = "src/main.doria"
TOML));
        self::assertNotFalse(file_put_contents($root . '/src/main.doria', $source));

        return $root;
    }

    private function writeFakeCompiler(string $root): string
    {
        $directory = $root . '/fake compiler';
        $script = $directory . '/fake-compiler.php';
        $this->writeExecutable(
            $script,
            str_replace(
                '__TARGET__',
                Platform::host()->target(),
                $this->fakeCompiler(),
            ),
        );
        if (PHP_OS_FAMILY !== 'Windows') {
            return $script;
        }

        $launcher = $directory . '/doriac.bat';
        $this->writeExecutable(
            $launcher,
            "@echo off\r\n"
                . '"' . PHP_BINARY . '" "' . $script . "\" %*\r\n"
                . "exit /b %errorlevel%\r\n",
        );

        return $launcher;
    }

    private function assertMetadata(string $artifact, string $profile, string $target): void
    {
        $contents = file_get_contents(dirname($artifact) . '/build.json');
        self::assertIsString($contents);
        /** @var mixed $metadata */
        $metadata = json_decode($contents, true);
        self::assertSame([
            'package' => 'build-fixture',
            'packageVersion' => '1.2.3',
            'toolchainVersion' => '2026.03.1-canary',
            'target' => $target,
            'profile' => $profile,
        ], $metadata);
    }

    private function fakeCompiler(): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php

if (($argv[1] ?? '') === '--version' && ($argv[2] ?? '') === '--json') {
    echo json_encode([
        'schema' => 1,
        'component' => 'doriac',
        'toolchainVersion' => '2026.03.1-canary',
        'target' => '__TARGET__',
        'commit' => 'fake',
    ]) . "\n";
    exit(0);
}

$release = in_array('--release', $argv, true);
$outputIndex = array_search('--out', $argv, true);
$expected = [
    'compile',
    'src/main.doria',
    '--target',
    'native',
];
if ($release) {
    $expected[] = '--release';
}
$expected[] = '--out';
if (
    !is_int($outputIndex)
    || !isset($argv[$outputIndex + 1])
) {
    fwrite(STDERR, "unexpected arguments: " . json_encode(array_slice($argv, 1)) . "\n");
    exit(9);
}

$output = $argv[$outputIndex + 1];
$expected[] = $output;
if (array_slice($argv, 1) !== $expected) {
    fwrite(STDERR, "unexpected arguments: " . json_encode(array_slice($argv, 1)) . "\n");
    exit(9);
}

$source = file_get_contents('src/main.doria');
if ($source !== false && str_contains($source, 'invalid')) {
    fwrite(STDERR, "compiler diagnostic unchanged\n");
    exit(17);
}

if ($source === false || !str_contains($source, 'no-output')) {
    file_put_contents($output, $release ? 'release artifact' : 'development artifact');
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($output, 0755);
    }
}
echo $output . "\n";
PHP;
    }
}
