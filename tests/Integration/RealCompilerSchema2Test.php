<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Integration;

use Doria\Baton\Tests\TestCase;
use Doria\Baton\Toolchain\Platform;
use Symfony\Component\Process\Process;

final class RealCompilerSchema2Test extends TestCase
{
    public function testGeneratedSchemaTwoProjectChecksBuildsAndRunsWithExplicitCompiler(): void
    {
        $compiler = $this->compiler();

        $root = $this->temporaryDirectory('real compiler schema2');
        $new = $this->runBaton(['new', 'hello'], $root);
        self::assertSame(0, $new['exitCode'], $new['stderr']);
        $project = $root . '/hello';

        $check = $this->runBaton(['check', '--compiler', $compiler], $project);
        self::assertSame(0, $check['exitCode'], $check['stderr']);
        $build = $this->runBaton(['build', '--compiler', $compiler], $project);
        self::assertSame(0, $build['exitCode'], $build['stderr']);
        $run = $this->runBaton(['run', '--compiler', $compiler], $project);
        self::assertSame(0, $run['exitCode'], $run['stderr']);
        self::assertSame("Hello, Doria!\n", $run['stdout']);

        $plans = glob($project . '/build/*/development/hello/build-plan.json') ?: [];
        self::assertCount(1, $plans);
        $plan = (string) file_get_contents($plans[0]);
        self::assertStringContainsString('"rootPackage": "local/hello"', $plan);
        self::assertStringContainsString('"schemaVersion": 1', $plan);
    }

    public function testSchemaOneKeepsDirectInvocationAndHistoricalBuildLayout(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler schema1');
        self::assertTrue(mkdir($root . '/src'));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 1
[package]
name = "legacy"
version = "0.1.0"
kind = "binary"
entry = "src/main.doria"
TOML);
        $this->write($root, 'src/main.doria', <<<'DORIA'
function main(): void
{
    echo "legacy\n";
}
DORIA);

        self::assertSame(0, $this->runBaton(['check', '--compiler', $compiler], $root)['exitCode']);
        self::assertSame(0, $this->runBaton(['build', '--compiler', $compiler], $root)['exitCode']);
        $run = $this->runBaton(['run', '--compiler', $compiler], $root);
        self::assertSame(0, $run['exitCode'], $run['stderr']);
        self::assertSame("legacy\n", $run['stdout']);
        $profile = $root . '/build/' . Platform::host()->target() . '/development';
        self::assertFileExists($profile . '/legacy' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''));
        self::assertFileExists($profile . '/build.json');
        self::assertDirectoryDoesNotExist($profile . '/legacy');
    }

    public function testExplicitTargetsAutoloadScopesAndLibraryBoundaryUseTheRealCompiler(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler targets');
        self::assertTrue(mkdir($root . '/src/Fixtures', 0o755, true));
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/blog"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "blog"
[[targets.binary]]
name = "web"
entry = "src/web.doria"
[[targets.binary]]
name = "worker"
entry = "src/worker.doria"
[autoload.namespaces]
"" = { path = "src/", include = ["**/*.doria"], exclude = ["**/Fixtures/**"] }
[autoload-dev.namespaces]
"Tests\\" = "tests/"
TOML);
        $this->write($root, 'src/messages.doria', <<<'DORIA'
function sharedMessage(): string
{
    return "shared";
}
DORIA);
        $this->write($root, 'src/web.doria', <<<'DORIA'
function main(): void
{
    echo "web\n";
}
DORIA);
        $this->write($root, 'src/worker.doria', <<<'DORIA'
function main(): void
{
    echo "worker\n";
}
DORIA);
        $this->write($root, 'src/Fixtures/Hidden.doria', "this source is excluded\n");
        $this->write($root, 'tests/Broken.doria', "this development source is inactive\n");

        $ambiguous = $this->runBaton(['check', '--compiler', $compiler], $root);
        self::assertSame(1, $ambiguous['exitCode']);
        self::assertStringContainsString('Target Selection Is Ambiguous', $ambiguous['stderr']);

        foreach (['web' => "web\n", 'worker' => "worker\n"] as $target => $output) {
            $check = $this->runBaton(['check', '--binary', $target, '--compiler', $compiler], $root);
            self::assertSame(0, $check['exitCode'], $check['stderr']);
            $build = $this->runBaton(['build', '--binary', $target, '--compiler', $compiler], $root);
            self::assertSame(0, $build['exitCode'], $build['stderr']);
            $run = $this->runBaton(['run', '--binary', $target, '--compiler', $compiler], $root);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            self::assertSame($output, $run['stdout']);
        }

        $directory = $root . '/build/' . Platform::host()->target() . '/development';
        $webPlan = (string) file_get_contents($directory . '/web/build-plan.json');
        self::assertStringContainsString('src/messages.doria', $webPlan);
        self::assertStringContainsString('tests/Broken.doria', $webPlan);
        self::assertStringContainsString('"scope": "development"', $webPlan);
        self::assertStringNotContainsString('src/worker.doria', $webPlan);
        self::assertStringNotContainsString('src/Fixtures/Hidden.doria', $webPlan);

        $library = $this->runBaton(['build', '--library', '--compiler', $compiler], $root);
        self::assertSame(0, $library['exitCode'], $library['stderr']);
        $receipt = json_decode(
            (string) file_get_contents($directory . '/blog/build.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($receipt);
        self::assertArrayHasKey('artifact', $receipt);
        self::assertNull($receipt['artifact']);
        self::assertFileDoesNotExist($directory . '/blog/blog');
        self::assertFileDoesNotExist($directory . '/blog/blog.exe');

        $rejected = $this->runBaton(['run', '--library', '--compiler', $compiler], $root);
        self::assertSame(1, $rejected['exitCode']);
        self::assertStringContainsString('Library Target Cannot Be Run', $rejected['stderr']);
    }

    public function testNamespacedAutoloadPlanIsAcceptedByTheRealCompiler(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler namespace');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/namespaced"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "namespaced"
[autoload.namespaces]
"Acme\\Namespaced\\" = "src/"
TOML);
        $this->write($root, 'src/Greeter.doria', <<<'DORIA'
namespace Acme\Namespaced;

class Greeter
{
    function message(): string
    {
        return "hello";
    }
}
DORIA);

        $check = $this->runBaton(['check', '--library', '--compiler', $compiler], $root);
        self::assertSame(0, $check['exitCode'], $check['stderr']);
        $plans = glob($root . '/build/*/development/namespaced/build-plan.json') ?: [];
        self::assertCount(1, $plans);
        $plan = (string) file_get_contents($plans[0]);
        self::assertStringContainsString('"prefix": "Acme\\\\Namespaced\\\\"', $plan);
        self::assertStringContainsString('src/Greeter.doria', $plan);
    }

    public function testPathDependencyGraphBuildsAndRunsWithExplicitCompiler(): void
    {
        $compiler = $this->compiler();
        $workspace = $this->temporaryDirectory('real compiler dependency graph');
        $support = $workspace . '/support';
        $application = $workspace . '/application';
        self::assertTrue(mkdir($support . '/src', 0o755, true));
        self::assertTrue(mkdir($application . '/src', 0o755, true));
        $this->write($support, 'Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/support"
version = "1.2.0"
edition = "2026"
[targets.library]
name = "support"
[autoload.namespaces]
"" = "src/"
TOML);
        $this->write($support, 'src/Support.doria', <<<'DORIA'
class Support
{
    function message(): string
    {
        return "dependency";
    }
}
DORIA);
        $this->write($application, 'Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/application"
version = "1.0.0"
edition = "2026"
[[targets.binary]]
name = "application"
entry = "src/main.doria"
[autoload.namespaces]
"" = "src/"
[dependencies]
"acme/support" = { path = "../support", version = "^1.0" }
TOML);
        $this->write($application, 'src/main.doria', <<<'DORIA'
function main(): void
{
    let $support = new Support();
    echo $support->message() . "\n";
}
DORIA);

        $install = $this->runBaton(['install', '--offline'], $application);
        self::assertSame(0, $install['exitCode'], $install['stderr']);
        foreach (['check', 'build'] as $command) {
            $result = $this->runBaton([$command, '--compiler', $compiler, '--offline'], $application);
            self::assertSame(0, $result['exitCode'], $result['stderr']);
        }
        $run = $this->runBaton(['run', '--compiler', $compiler, '--offline'], $application);
        self::assertSame(0, $run['exitCode'], $run['stderr']);
        self::assertSame("dependency\n", $run['stdout']);

        $plans = glob($application . '/build/*/development/application/build-plan.json') ?: [];
        self::assertCount(1, $plans);
        /** @var array{packages?: mixed} $plan */
        $plan = json_decode((string) file_get_contents($plans[0]), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan['packages'] ?? null);
        self::assertSame(
            ['acme/application', 'acme/support'],
            array_column($plan['packages'], 'identity'),
        );
    }

    private function compiler(): string
    {
        $compiler = getenv('DORIA_COMPILER');
        if (!is_string($compiler) || $compiler === '') {
            self::markTestSkipped('Set DORIA_COMPILER to an explicit compiled doriac artifact.');
        }
        $canonical = realpath($compiler);
        self::assertIsString($canonical, 'DORIA_COMPILER must identify an existing compiled artifact.');
        self::assertTrue(is_executable($canonical), 'DORIA_COMPILER must be executable.');

        $identity = new Process([$canonical, '--version', '--json']);
        self::assertSame(0, $identity->run(), $identity->getErrorOutput());
        /** @var array{schema?: mixed, component?: mixed} $document */
        $document = json_decode($identity->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $document['schema'] ?? null);
        self::assertSame('doriac', $document['component'] ?? null);

        return $canonical;
    }

    private function write(string $root, string $relative, string $contents): void
    {
        self::assertNotFalse(file_put_contents($root . '/' . $relative, $contents));
    }
}
