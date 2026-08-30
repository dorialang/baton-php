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
        self::assertStringNotContainsString('tests/Broken.doria', $webPlan);
        self::assertStringNotContainsString('"scope": "development"', $webPlan);
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
"acme/support" = { source = "path", path = "../support", version = "^1.0" }
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

    public function testMetadataDiscoveredTestsCompileOnceAndRunInFreshProcesses(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler test runner');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/tested"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "tested"
[autoload.namespaces]
"" = "src/"
[autoload-dev.namespaces]
"" = "tests/"
TOML);
        $this->write($root, 'src/Library.doria', "class Library {}\n");
        $this->write($root, 'tests/Feature.doria', <<<'DORIA'
#[Test]
function afterFailure(): void
{
    echo "after output\n";
}

#[Test]
function failingTest(): void
{
    panic("expected test failure");
}

#[Test]
function passingTest(): void
{
    echo "pass output\n";
}
DORIA);

        $suite = $this->runBaton(['test', '--compiler', $compiler], $root);
        self::assertSame(1, $suite['exitCode']);
        self::assertStringContainsString("acme/tested\n  PASS afterFailure", $suite['stdout']);
        self::assertStringContainsString('FAIL failingTest [Fatal Panic]', $suite['stdout']);
        self::assertStringContainsString('PASS passingTest', $suite['stdout']);
        self::assertStringContainsString('expected test failure', $suite['stdout']);
        self::assertStringNotContainsString('after output', $suite['stdout']);
        self::assertStringNotContainsString('pass output', $suite['stdout']);
        self::assertStringContainsString('Passed:                    2', $suite['stdout']);
        self::assertStringContainsString('Fatal Panic:               1', $suite['stdout']);
        self::assertStringContainsString('Total:                     3', $suite['stdout']);

        $filtered = $this->runBaton(
            ['test', '--filter', 'passing', '--show-output', '--compiler', $compiler],
            $root,
        );
        self::assertSame(0, $filtered['exitCode'], $filtered['stderr']);
        self::assertStringContainsString('pass output', $filtered['stdout']);
        self::assertStringContainsString('Passed:                    1', $filtered['stdout']);
        self::assertStringContainsString('Total:                     1', $filtered['stdout']);

        $testInventories = glob($root . '/build/*/development/acme/tested/tests/inventory.json') ?: [];
        self::assertCount(1, $testInventories);
        self::assertFileExists($root . '/build/.baton/inventory.json');
        $inventory = json_decode(
            (string) file_get_contents($root . '/build/.baton/inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($inventory);
        self::assertSame(1, $inventory['schemaVersion']);
        $identity = new Process([$compiler, '--version', '--json']);
        self::assertSame(0, $identity->run(), $identity->getErrorOutput());
        $compilerIdentity = json_decode($identity->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($compilerIdentity);
        self::assertSame($compilerIdentity['commit'], $inventory['compilerRevision']);
        $recordedTests = $inventory['tests'] ?? null;
        self::assertIsArray($recordedTests);
        self::assertArrayHasKey('acme/tested', $recordedTests);
    }

    public function testBehavioralMetadataDrivesIdentityDispatchEffectsReportingAndIsolation(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler behavioral tests');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/behavioral"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "behavioral"
[autoload.namespaces]
"" = "src/"
[autoload-dev.namespaces]
"" = "tests/"
TOML);
        $this->write($root, 'src/Library.doria', "class Library {}\n");
        $this->write($root, 'tests/Behavior.doria', <<<'DORIA'
use Doria\Std\Test\{describe, it, test, expect, fail};

internal class ExpectedFailure implements Error
{
    function __construct(string $message) {}
}

#[Test]
function lowLevel(): void
{
    expect(42)->toEqual(42);
    echo "low level output\n";
}

#[Test]
function lowLevelExpectationFailure(): void
{
    echo "before low-level assertion\n";
    expect(41)->toEqual(42);
}

function assertHelper(): void
{
    expect("helper")->toStartWith("help");
}

describe("Shopping cart", function (): void {
    it("passes", function (): void {
        assertHelper();
        expect(true)->toBeTrue();
        echo "behavioral output\n";
    });

    it("expectation fails", function (): void {
        echo "before behavioral assertion\n";
        expect("cart")->toContain("missing");
    });

    it("fails explicitly", function (): void {
        fail("explicit assertion failure");
    });

    test("returns a checked Error", function (): void {
        throw new ExpectedFailure("expected checked failure");
    });

    it("matches collection facts", function (): void {
        List<int> $values = [1, 2];
        expect($values)->toContain(2);
    });

    it("reports collection differences", function (): void {
        List<int> $values = [1, 2];
        expect($values)->toHaveCount(3);
    });

    it("intercepts checked Errors", function (): void {
        expect(function (): void {
            throw new ExpectedFailure("observed");
        })->toThrow(function (ExpectedFailure $error): void {
            expect($error->message)->toContain("observed");
        });
    });

    describe("failure isolation", function (): void {
        it("panics", function (): void {
            panic("expected panic failure");
        });

        it("continues later tests", function (): void {
            echo "later output\n";
        });
    });
});
DORIA);

        $suite = $this->runBaton(['test', '--compiler', $compiler], $root);
        self::assertSame(1, $suite['exitCode']);
        self::assertStringContainsString("acme/behavioral\n  PASS lowLevel", $suite['stdout']);
        self::assertStringContainsString('FAIL lowLevelExpectationFailure [Assertion Failed]', $suite['stdout']);
        self::assertStringContainsString("  Shopping cart\n    PASS passes", $suite['stdout']);
        self::assertStringContainsString('FAIL expectation fails [Assertion Failed]', $suite['stdout']);
        self::assertStringContainsString('FAIL fails explicitly [Assertion Failed]', $suite['stdout']);
        self::assertStringContainsString(
            'FAIL returns a checked Error [Unexpected Checked Error]',
            $suite['stdout'],
        );
        self::assertStringContainsString('PASS matches collection facts', $suite['stdout']);
        self::assertStringContainsString(
            'FAIL reports collection differences [Assertion Failed]',
            $suite['stdout'],
        );
        self::assertStringContainsString('PASS intercepts checked Errors', $suite['stdout']);
        self::assertStringContainsString("    failure isolation\n      FAIL panics [Fatal Panic]", $suite['stdout']);
        self::assertStringContainsString('PASS continues later tests', $suite['stdout']);
        self::assertStringContainsString('expected checked failure', $suite['stdout']);
        self::assertStringContainsString('expected panic failure', $suite['stdout']);
        self::assertStringContainsString('before low-level assertion', $suite['stdout']);
        self::assertStringContainsString('before behavioral assertion', $suite['stdout']);
        self::assertStringContainsString('explicit assertion failure', $suite['stdout']);
        self::assertStringContainsString('Matcher: toEqual', $suite['stdout']);
        self::assertStringContainsString('Matcher: toContain', $suite['stdout']);
        self::assertStringContainsString('Matcher: toHaveCount', $suite['stdout']);
        self::assertStringContainsString('Expected Count: 3', $suite['stdout']);
        self::assertStringContainsString('Error: ExpectedFailure', $suite['stdout']);
        self::assertStringNotContainsString('__doria_test_', $suite['stdout']);
        self::assertStringNotContainsString('behavioral output', $suite['stdout']);
        self::assertStringNotContainsString('later output', $suite['stdout']);
        self::assertStringContainsString('Passed:                    5', $suite['stdout']);
        self::assertStringContainsString('Assertion Failed:          4', $suite['stdout']);
        self::assertStringContainsString('Unexpected Checked Error:  1', $suite['stdout']);
        self::assertStringContainsString('Fatal Panic:               1', $suite['stdout']);
        self::assertStringContainsString('Total:                     11', $suite['stdout']);

        $filtered = $this->runBaton(
            ['test', '--filter', 'Shopping cart > passes', '--show-output', '--compiler', $compiler],
            $root,
        );
        self::assertSame(0, $filtered['exitCode'], $filtered['stderr']);
        self::assertStringContainsString('behavioral output', $filtered['stdout']);
        self::assertStringContainsString('Passed:                    1', $filtered['stdout']);
        self::assertStringContainsString('Total:                     1', $filtered['stdout']);

        $missing = $this->runBaton(
            ['test', '--filter', 'shopping CART', '--compiler', $compiler],
            $root,
        );
        self::assertSame(1, $missing['exitCode']);
        self::assertStringContainsString('No Tests Match The Filter', $missing['stderr']);

        $release = $this->runBaton(
            ['test', '--release', '--filter', 'Shopping cart > passes', '--compiler', $compiler],
            $root,
        );
        self::assertSame(0, $release['exitCode'], $release['stderr'] . $release['stdout']);
        self::assertStringContainsString("  Shopping cart\n    PASS passes", $release['stdout']);

        $this->runBaton(['test', '--compiler', $compiler], $root);

        $testDirectories = glob($root . '/build/*/development/acme/behavioral/tests') ?: [];
        self::assertCount(1, $testDirectories);
        $inventory = json_decode(
            (string) file_get_contents($testDirectories[0] . '/inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($inventory);
        self::assertIsArray($inventory['tests'] ?? null);
        self::assertSame(1, $inventory['outcomeCategoryVocabularyVersion'] ?? null);
        self::assertIsString($inventory['compilerRevisionExpected'] ?? null);
        $outcomes = glob($testDirectories[0] . '/outcomes/*') ?: [];
        self::assertSame([], $outcomes, 'Every per-test runtime outcome must be removed after decoding.');
        $behavioral = array_values(array_filter(
            $inventory['tests'],
            static fn (mixed $test): bool => is_array($test) && ($test['origin'] ?? null) === 'behavioral',
        ));
        self::assertCount(9, $behavioral);
        $passing = array_values(array_filter(
            $behavioral,
            static fn (array $test): bool => ($test['displayName'] ?? null) === 'Shopping cart > passes',
        ));
        self::assertCount(1, $passing);
        self::assertSame(['Shopping cart', 'passes'], $passing[0]['pathSegments']);
        self::assertIsInt($passing[0]['authoredOrdinal'] ?? null);
        self::assertNotSame($passing[0]['displayName'], $passing[0]['callableCanonicalName']);

        $metadata = json_decode(
            (string) file_get_contents($testDirectories[0] . '/metadata.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($metadata);
        self::assertSame(3, $metadata['schemaVersion']);
        self::assertIsArray($metadata['tests'] ?? null);
        self::assertCount(11, $metadata['tests']);

        $dispatcher = (string) file_get_contents($testDirectories[0] . '/dispatcher.doria');
        self::assertIsString($passing[0]['identity'] ?? null);
        self::assertIsString($passing[0]['callableCanonicalName'] ?? null);
        self::assertStringContainsString($passing[0]['identity'], $dispatcher);
        self::assertStringContainsString($passing[0]['callableCanonicalName'] . '();', $dispatcher);
        self::assertStringContainsString(' throws ExpectedFailure', $dispatcher);
        self::assertStringNotContainsString('throws AssertionError', $dispatcher);
        self::assertStringNotContainsString('ConsoleIO', $dispatcher);
        self::assertStringNotContainsString('describe(', $dispatcher);
        self::assertStringNotContainsString('it(', $dispatcher);
    }

    public function testBinaryOnlyPackageTestsUseTheBinaryForDiscoveryButNotTheDispatcherGraph(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler binary tests');
        self::assertTrue(mkdir($root . '/src', 0o755, true));
        self::assertTrue(mkdir($root . '/tests', 0o755, true));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/binary-tested"
version = "1.0.0"
edition = "2026"
[[targets.binary]]
name = "application"
entry = "src/main.doria"
[autoload.namespaces]
"" = "src/"
[autoload-dev.namespaces]
"" = "tests/"
TOML);
        $this->write($root, 'src/main.doria', <<<'DORIA'
function main(): void
{
    echo "application\n";
}
DORIA);
        $this->write($root, 'tests/ApplicationTests.doria', <<<'DORIA'
#[Test]
function applicationTest(): void
{
    echo "test\n";
}
DORIA);

        $suite = $this->runBaton(
            ['test', '--show-output', '--compiler', $compiler],
            $root,
        );
        self::assertSame(0, $suite['exitCode'], $suite['stderr'] . $suite['stdout']);
        self::assertStringContainsString("acme/binary-tested\n  PASS applicationTest", $suite['stdout']);
        self::assertStringContainsString("test\n", $suite['stdout']);
        self::assertStringContainsString('Passed:                    1', $suite['stdout']);
        self::assertStringContainsString('Total:                     1', $suite['stdout']);

        $plans = glob($root . '/build/*/development/acme/binary-tested/tests/build-plan.json') ?: [];
        self::assertCount(1, $plans);
        $plan = (string) file_get_contents($plans[0]);
        self::assertStringContainsString('dispatcher.doria', $plan);
        self::assertStringNotContainsString('src/main.doria', $plan);
    }

    public function testWorkspaceAssertionsUsePackageInternalsAndDirectDevelopmentDependencies(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler assertion workspace');
        self::assertTrue(mkdir($root . '/packages/application/src', 0o755, true));
        self::assertTrue(mkdir($root . '/packages/application/tests', 0o755, true));
        self::assertTrue(mkdir($root . '/packages/test-support/src', 0o755, true));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 2
[workspace]
members = ["packages/*"]
TOML);
        $this->write($root, 'packages/application/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/application"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "application"
[autoload.namespaces]
"" = "src/"
[autoload-dev.namespaces]
"" = "tests/"
[dev-dependencies]
"acme/test-support" = { source = "path", path = "../test-support" }
TOML);
        $this->write($root, 'packages/test-support/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/test-support"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "test-support"
[autoload.namespaces]
"" = "src/"
TOML);
        $this->write(
            $root,
            'packages/application/src/Library.doria',
            "internal function packageAnswer(): int { return 42; }\n",
        );
        $this->write(
            $root,
            'packages/test-support/src/Support.doria',
            "function dependencyAnswer(): int { return 42; }\n",
        );
        $this->write($root, 'packages/application/tests/Assertions.doria', <<<'DORIA'
use Doria\Std\Test\expect;

#[Test]
function workspaceAssertion(): void
{
    expect(packageAnswer())->toEqual(dependencyAnswer());
}
DORIA);

        $install = $this->runBaton(['install', '--offline'], $root);
        self::assertSame(0, $install['exitCode'], $install['stderr']);
        $suite = $this->runBaton(
            ['test', '--workspace', '--offline', '--compiler', $compiler],
            $root,
        );
        self::assertSame(0, $suite['exitCode'], $suite['stderr'] . $suite['stdout']);
        self::assertStringContainsString("acme/application\n  PASS workspaceAssertion", $suite['stdout']);
        self::assertStringContainsString("acme/test-support\n  0 tests", $suite['stdout']);
        self::assertStringContainsString('Passed:                    1', $suite['stdout']);
        self::assertStringContainsString('Total:                     1', $suite['stdout']);
    }

    public function testAggregateToolingPlanNormalizesNonSelectedBinaryEntries(): void
    {
        $compiler = $this->compiler();
        $root = $this->temporaryDirectory('real compiler aggregate tooling');
        self::assertTrue(mkdir($root . '/packages/library/src', 0o755, true));
        self::assertTrue(mkdir($root . '/tools/processor/src', 0o755, true));
        $this->write($root, 'Baton.toml', <<<'TOML'
manifest-version = 2
[workspace]
members = ["packages/*", "tools/*"]
TOML);
        $this->write($root, 'packages/library/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/library"
version = "1.0.0"
edition = "2026"
[targets.library]
name = "library"
[autoload.namespaces]
"" = "src/"
TOML);
        $this->write($root, 'packages/library/src/Library.doria', "class Library {}\n");
        $this->write($root, 'tools/processor/Baton.toml', <<<'TOML'
manifest-version = 2
[package]
name = "acme/processor"
version = "1.0.0"
edition = "2026"
[[targets.binary]]
name = "processor"
entry = "src/main.doria"
[autoload.namespaces]
"" = "src/"
TOML);
        $this->write($root, 'tools/processor/src/main.doria', "function main(): void {}\n");

        $install = $this->runBaton(['install', '--offline'], $root);
        self::assertSame(0, $install['exitCode'], $install['stderr']);
        $project = $this->runBaton(
            ['project', '--json', '--workspace', '--development', '--offline'],
            $root,
        );
        self::assertSame(0, $project['exitCode'], $project['stderr']);
        $document = json_decode($project['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertIsArray($document['toolingBuildPlan'] ?? null);
        $plan = $document['toolingBuildPlan'];
        $processor = $this->packageDocument($plan['packages'] ?? null, 'identity', 'acme/processor');
        self::assertSame('explicit', $this->firstSourceOrigin($processor));
        $projectProcessor = $this->packageDocument(
            $document['packages'] ?? null,
            'compilerPackage',
            'acme/processor',
        );
        self::assertSame('explicit', $this->firstSourceOrigin($projectProcessor));

        $planPath = $root . '/tooling-build-plan.json';
        self::assertNotFalse(file_put_contents(
            $planPath,
            json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $check = new Process([$compiler, 'check', '--build-plan', $planPath], $root);
        self::assertSame(0, $check->run(), $check->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function packageDocument(mixed $packages, string $key, string $value): array
    {
        self::assertIsArray($packages);
        foreach ($packages as $package) {
            self::assertIsArray($package);
            if (($package[$key] ?? null) === $value) {
                $document = [];
                foreach ($package as $field => $fieldValue) {
                    self::assertIsString($field);
                    $document[$field] = $fieldValue;
                }

                return $document;
            }
        }

        self::fail("Package `{$value}` was not found.");
    }

    /** @param array<string, mixed> $package */
    private function firstSourceOrigin(array $package): string
    {
        $sources = $package['sources'] ?? null;
        self::assertIsArray($sources);
        $source = $sources[0] ?? null;
        self::assertIsArray($source);
        $origin = $source['origin'] ?? null;
        self::assertIsString($origin);

        return $origin;
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
