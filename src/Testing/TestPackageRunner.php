<?php

declare(strict_types=1);

namespace Doria\Baton\Testing;

use Doria\Baton\Build\AtomicFileWriter;
use Doria\Baton\Build\BuildPlan;
use Doria\Baton\Build\BuildPlanWriter;
use Doria\Baton\Build\Schema2ProjectContextFactory;
use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Compiler\MetadataReader;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Inventory\ManagedInventoryStore;
use Doria\Baton\Process\BoundedProcessRunner;
use Doria\Baton\Toolchain\ToolchainSelection;
use Doria\Baton\Workspace\WorkspaceContext;
use Symfony\Component\Console\Output\OutputInterface;

final class TestPackageRunner
{
    private const PROCESS_TIMEOUT = 300.0;
    private const OUTPUT_LIMIT = 67_108_864;

    /**
     * @return array{
     *   selected: int,
     *   passed: int,
     *   assertionFailed: int,
     *   unexpectedCheckedError: int,
     *   fatalPanic: int,
     *   abnormalProcessFailure: int
     * }
     */
    public function run(
        string $projectRoot,
        string $storageRoot,
        Schema2Manifest $manifest,
        ?WorkspaceContext $workspace,
        ToolchainSelection $toolchain,
        NetworkPolicy $network,
        bool $release,
        ?string $filter,
        bool $showOutput,
        OutputInterface $output,
    ): array {
        $profile = $release ? 'release' : 'development';
        $selected = new SelectedPackageTarget(
            $manifest->targets->library ?? $manifest->targets->binaries[0],
        );
        $context = (new Schema2ProjectContextFactory())->create(
            $projectRoot,
            $manifest,
            $selected,
            $toolchain,
            $profile,
            network: $network,
            workspace: $workspace,
            development: true,
            output: $output,
        );
        $compiler = new CompilerAdapter($toolchain->compilerPath);
        $metadataResult = $compiler->capture(
            ['metadata', '--schema-version', '3', '--build-plan', $context->buildPlan->path],
            $projectRoot,
        );
        if (!$metadataResult->succeeded()) {
            throw new BatonError(
                'B0423',
                'Test Metadata Could Not Be Produced',
                trim($metadataResult->stderr) ?: 'doriac metadata failed while discovering tests.',
            );
        }
        $metadata = (new MetadataReader())->schema3($metadataResult->stdout);
        $tests = (new TestDiscovery())->discover(
            $metadata,
            $manifest->package->compilerIdentity,
            $filter,
        );
        $output->writeln($this->terminalSafe($manifest->package->name));
        if ($tests === []) {
            $output->writeln('  0 tests');

            return $this->counts(0);
        }

        $directory = $this->testDirectory($storageRoot, $toolchain, $profile, $manifest);
        $dispatcherPath = $directory . DIRECTORY_SEPARATOR . 'dispatcher.doria';
        $artifact = $directory . DIRECTORY_SEPARATOR . 'dispatcher'
            . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $dispatcher = $this->dispatcher($tests);
        (new AtomicFileWriter())->write($dispatcherPath, $dispatcher, 'Test Dispatcher Could Not Be Written');
        (new AtomicFileWriter())->write(
            $directory . DIRECTORY_SEPARATOR . 'metadata.json',
            $metadataResult->stdout,
            'Test Metadata Could Not Be Written',
        );
        $plan = $this->testPlan(
            $context->buildPlan->bytes,
            $projectRoot,
            $storageRoot,
            $dispatcherPath,
            $manifest->package->compilerIdentity,
            $profile,
        );
        $written = (new BuildPlanWriter())->write(
            new BuildPlan($plan),
            $directory . DIRECTORY_SEPARATOR . 'build-plan.json',
        );
        $testInventory = $this->json([
            'schemaVersion' => 1,
            'package' => $manifest->package->name,
            'compilerRevisionExpected' => $toolchain->identity->commit,
            'outcomeCategoryVocabularyVersion' => 1,
            'tests' => array_map($this->testFact(...), $tests),
            'metadataSha256' => hash('sha256', $metadataResult->stdout),
            'dispatcherSha256' => hash('sha256', $dispatcher),
            'buildPlanSha256' => $written->sha256,
        ]);
        (new AtomicFileWriter())->write(
            $directory . DIRECTORY_SEPARATOR . 'inventory.json',
            $testInventory,
            'Test Inventory Could Not Be Written',
        );
        $compile = $compiler->capture(
            ['compile', '--build-plan', $written->path, '--out', $artifact],
            $projectRoot,
        );
        if (!$compile->succeeded() || !is_file($artifact)) {
            throw new BatonError(
                'B0424',
                'Test Dispatcher Could Not Be Compiled',
                trim($compile->stderr) ?: 'doriac did not produce the package test dispatcher.',
            );
        }
        $artifactHash = hash_file('sha256', $artifact);
        if (!is_string($artifactHash)) {
            throw new BatonError('B0424', 'Test Dispatcher Could Not Be Compiled', 'Dispatcher could not be hashed.');
        }
        (new ManagedInventoryStore())->recordTests(
            $storageRoot,
            $toolchain->identity->commit,
            $manifest->package->compilerIdentity,
            hash('sha256', $metadataResult->stdout),
            hash('sha256', $testInventory),
            $written->sha256,
            hash('sha256', $dispatcher),
            $artifactHash,
            array_map($this->testFact(...), $tests),
        );

        $counts = $this->counts(count($tests));
        $previousSuites = [];
        foreach ($tests as $test) {
            $result = $this->execute($artifact, $test, $projectRoot, $directory);
            $key = $this->countKey($result->category);
            ++$counts[$key];
            $this->renderHierarchy($output, $test, $result, $previousSuites);
            $previousSuites = $test->suitePathIdentities;
            if (!$result->category->passed()) {
                $this->renderFailure($output, $test, $result);
            }
            if ($showOutput || !$result->category->passed()) {
                $this->renderOutput($output, $result);
            }
        }

        return $counts;
    }

    private function testDirectory(
        string $storageRoot,
        ToolchainSelection $toolchain,
        string $profile,
        Schema2Manifest $manifest,
    ): string {
        $directory = $storageRoot . DIRECTORY_SEPARATOR . 'build'
            . DIRECTORY_SEPARATOR . $toolchain->identity->target
            . DIRECTORY_SEPARATOR . $profile;
        foreach (explode('/', $manifest->package->compilerIdentity) as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
        }
        $directory .= DIRECTORY_SEPARATOR . 'tests';
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new BatonError('B0424', 'Test Storage Could Not Be Prepared', $directory);
        }

        return $directory;
    }

    /** @param list<ExecutableTest> $tests */
    private function dispatcher(array $tests): string
    {
        $effects = [];
        foreach ($tests as $test) {
            foreach ($test->requiredEffects as $effect) {
                $effects[$effect] = true;
            }
        }
        $effectNames = array_keys($effects);
        sort($effectNames, SORT_STRING);
        $throws = $effectNames === [] ? '' : ' throws ' . implode(', ', $effectNames);
        $source = "function main(List<string> \$args): void{$throws}\n{\n";
        foreach ($tests as $test) {
            $name = $this->string($test->identity);
            $source .= "    if (\$args[0] == \"{$name}\") {\n";
            $source .= "        {$test->callableCanonicalName}();\n";
            $source .= "        return;\n";
            $source .= "    }\n";
        }
        $source .= "}\n";

        return $source;
    }

    /** @return array<string, mixed> */
    private function testPlan(
        string $json,
        string $projectRoot,
        string $storageRoot,
        string $dispatcherPath,
        string $compilerPackage,
        string $profile,
    ): array {
        $plan = $this->buildPlanDocument($json);
        $packages = $this->packages($plan['packages'] ?? null);
        $selectedEntry = $this->selectedEntry($plan, $compilerPackage);
        $root = realpath($storageRoot) ?: $storageRoot;
        $project = realpath($projectRoot) ?: $projectRoot;
        $projectPrefix = $project === $root ? '' : $this->relative($root, $project) . '/';
        $dispatcherRelative = $this->relative($root, $dispatcherPath);
        $dispatcherIdentity = $compilerPackage . ':' . $dispatcherRelative;
        foreach ($packages as $index => $package) {
            if ($package['identity'] !== $compilerPackage) {
                continue;
            }
            $sources = $this->sources($package['sources'] ?? null);
            $mappings = $this->namespaceMappings($package['namespaceMappings'] ?? null);
            if ($selectedEntry !== null) {
                $sources = array_values(array_filter(
                    $sources,
                    static fn (array $source): bool => $source['identity'] !== $selectedEntry,
                ));
            }
            if ($projectPrefix !== '') {
                $package['root'] = $root;
                foreach ($sources as $sourceIndex => $source) {
                    $source['path'] = $projectPrefix . $source['path'];
                    $sources[$sourceIndex] = $source;
                }
                foreach ($mappings as $mappingIndex => $mapping) {
                    $mapping['path'] = $projectPrefix . $mapping['path'];
                    $mappings[$mappingIndex] = $mapping;
                }
            }
            $mappings[] = [
                'prefix' => '',
                'path' => str_replace('\\', '/', dirname($dispatcherRelative)) . '/',
                'scope' => 'generated',
                'generatedFor' => 'development',
            ];
            usort($mappings, static fn (array $left, array $right): int => strcmp(
                $left['scope'] . "\0" . $left['prefix'] . "\0" . $left['path'],
                $right['scope'] . "\0" . $right['prefix'] . "\0" . $right['path'],
            ));
            $sources[] = [
                'identity' => $dispatcherIdentity,
                'path' => $dispatcherRelative,
                'scope' => 'generated',
                'origin' => 'entry',
                'generatedFor' => 'development',
            ];
            usort($sources, static fn (array $left, array $right): int => strcmp(
                $left['identity'],
                $right['identity'],
            ));
            $package['sources'] = $sources;
            $package['namespaceMappings'] = $mappings;
            $packages[$index] = $package;
        }
        $plan['packages'] = $packages;
        $plan['selectedTarget'] = [
            'package' => $compilerPackage,
            'name' => 'baton-tests',
            'kind' => 'binary',
            'entrySource' => $dispatcherIdentity,
            'activeScopes' => ['main', 'development', 'generated'],
        ];
        if (is_array($plan['compiler'] ?? null)) {
            $plan['compiler']['nativeProfile'] = $profile === 'release' ? 'release' : 'fast';
        }

        return $plan;
    }

    /** @param array<string, mixed> $plan */
    private function selectedEntry(array $plan, string $compilerPackage): ?string
    {
        $selected = $plan['selectedTarget'] ?? null;
        if (!is_array($selected)
            || ($selected['package'] ?? null) !== $compilerPackage
            || ($selected['kind'] ?? null) !== 'binary'
        ) {
            return null;
        }

        return is_string($selected['entrySource'] ?? null) ? $selected['entrySource'] : null;
    }

    /** @return array<string, mixed> */
    private function buildPlanDocument(string $json): array
    {
        try {
            $plan = json_decode($json, true, 256, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new BatonError('B0424', 'Test Build Plan Is Invalid', $error->getMessage());
        }
        return $this->object($plan, 'Expected a compiler build-plan object.');
    }

    /** @return list<array<string, mixed>> */
    private function packages(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new BatonError('B0424', 'Test Build Plan Is Invalid', 'Expected a package list.');
        }
        $packages = [];
        foreach ($value as $package) {
            $package = $this->object($package, 'Expected each package to be an object.');
            if (!is_string($package['identity'] ?? null)) {
                throw new BatonError('B0424', 'Test Build Plan Is Invalid', 'Expected each package to have an identity.');
            }
            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * @return list<array{identity: string, path: string, scope: string, origin: string, generatedFor: string|null}>
     */
    private function sources(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new BatonError('B0424', 'Test Build Plan Is Invalid', 'Expected each package to have a source list.');
        }
        $sources = [];
        foreach ($value as $source) {
            if (!is_array($source)
                || !is_string($source['identity'] ?? null)
                || !is_string($source['path'] ?? null)
                || !is_string($source['scope'] ?? null)
                || !is_string($source['origin'] ?? null)
                || !(is_string($source['generatedFor'] ?? null) || ($source['generatedFor'] ?? null) === null)
            ) {
                throw new BatonError('B0424', 'Test Build Plan Is Invalid', 'Expected a valid package source record.');
            }
            $sources[] = [
                'identity' => $source['identity'],
                'path' => $source['path'],
                'scope' => $source['scope'],
                'origin' => $source['origin'],
                'generatedFor' => $source['generatedFor'] ?? null,
            ];
        }

        return $sources;
    }

    /** @return list<array{prefix: string, path: string, scope: string, generatedFor: string|null}> */
    private function namespaceMappings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new BatonError(
                'B0424',
                'Test Build Plan Is Invalid',
                'Expected each package to have a namespace mapping list.',
            );
        }
        $mappings = [];
        foreach ($value as $mapping) {
            if (!is_array($mapping)
                || !is_string($mapping['prefix'] ?? null)
                || !is_string($mapping['path'] ?? null)
                || !is_string($mapping['scope'] ?? null)
                || !(is_string($mapping['generatedFor'] ?? null) || ($mapping['generatedFor'] ?? null) === null)
            ) {
                throw new BatonError('B0424', 'Test Build Plan Is Invalid', 'Expected a valid namespace mapping.');
            }
            $mappings[] = [
                'prefix' => $mapping['prefix'],
                'path' => $mapping['path'],
                'scope' => $mapping['scope'],
                'generatedFor' => $mapping['generatedFor'] ?? null,
            ];
        }

        return $mappings;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $expectation): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new BatonError('B0424', 'Test Build Plan Is Invalid', $expectation);
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new BatonError('B0424', 'Test Build Plan Is Invalid', $expectation);
            }
            $object[$key] = $item;
        }

        return $object;
    }

    private function execute(
        string $artifact,
        ExecutableTest $test,
        string $workingDirectory,
        string $testDirectory,
    ): TestExecutionResult {
        $channel = new RuntimeOutcomeChannel($testDirectory, $test->identity);
        $process = (new BoundedProcessRunner())->run(
            [$artifact, $test->identity],
            $workingDirectory,
            $channel->environment(),
            null,
            self::PROCESS_TIMEOUT,
            self::OUTPUT_LIMIT,
            self::OUTPUT_LIMIT,
        );
        $decoded = $channel->read();
        try {
            $channel->remove();
        } catch (BatonError $error) {
            return TestExecutionResult::classify(
                $process,
                null,
                'Runtime outcome cleanup failed: ' . $error->body,
            );
        }

        return TestExecutionResult::classify($process, $decoded['outcome'], $decoded['error']);
    }

    /** @param list<string> $previousSuites */
    private function renderHierarchy(
        OutputInterface $output,
        ExecutableTest $test,
        TestExecutionResult $result,
        array $previousSuites,
    ): void {
        $common = 0;
        $limit = min(count($previousSuites), count($test->suitePathIdentities));
        while ($common < $limit
            && $previousSuites[$common] === $test->suitePathIdentities[$common]
        ) {
            ++$common;
        }
        for ($index = $common; $index < count($test->suitePathIdentities); ++$index) {
            $heading = $test->pathSegments[$index] ?? '';
            $output->writeln(str_repeat('  ', $index + 1) . $this->terminalSafe($heading));
        }
        $depth = count($test->suitePathIdentities) + 1;
        $leaf = $test->pathSegments === []
            ? $test->displayName
            : $test->pathSegments[count($test->pathSegments) - 1];
        $status = $result->category->passed()
            ? 'PASS'
            : 'FAIL';
        $category = $result->category->passed() ? '' : " [{$result->category->value}]";
        $output->writeln(
            str_repeat('  ', $depth)
                . $status
                . ' '
                . $this->terminalSafe($leaf)
                . $category,
        );
    }

    private function renderFailure(
        OutputInterface $output,
        ExecutableTest $test,
        TestExecutionResult $result,
    ): void {
        $indent = str_repeat('  ', count($test->suitePathIdentities) + 2);
        $outcome = $result->outcome;
        $output->writeln('');
        if ($result->category === TestOutcomeCategory::AssertionFailed && $outcome !== null) {
            $matcher = $outcome->matcherSourceName() ?? $outcome->matcher ?? 'unknown';
            $output->writeln($indent . 'Matcher: ' . ($outcome->negated ? 'not->' : '') . $matcher);
            if ($outcome->userMessage !== null) {
                $output->writeln($indent . 'Message: ' . $this->terminalSafe($outcome->userMessage));
            }
            if ($outcome->expected !== null) {
                $output->writeln(
                    $indent . 'Expected: ' . $this->terminalSafe($outcome->expected['presentation']),
                );
            }
            if ($outcome->actual !== null) {
                $output->writeln(
                    $indent . 'Actual: ' . $this->terminalSafe($outcome->actual['presentation']),
                );
            }
            if ($outcome->difference !== null) {
                $output->writeln($indent . 'Difference: ' . $this->terminalSafe($outcome->difference));
            }
            $this->renderOrigin($output, $indent, $outcome);
        } elseif ($result->category === TestOutcomeCategory::UnexpectedCheckedError && $outcome !== null) {
            $output->writeln($indent . 'Error: ' . $this->terminalSafe($outcome->errorType ?? 'Error'));
            $output->writeln($indent . 'Message: ' . $this->terminalSafe($outcome->message ?? ''));
            $this->renderOrigin($output, $indent, $outcome);
        } elseif ($result->category === TestOutcomeCategory::FatalPanic && $outcome !== null) {
            $output->writeln($indent . 'Panic: ' . $this->terminalSafe($outcome->diagnosticCode ?? 'unknown'));
            if ($outcome->message !== null && $outcome->message !== '') {
                $output->writeln($indent . 'Message: ' . $this->terminalSafe($outcome->message));
            }
            foreach ($outcome->facts as $fact) {
                $value = is_bool($fact['value']) ? ($fact['value'] ? 'true' : 'false') : (string) $fact['value'];
                $output->writeln(
                    $indent
                        . $this->terminalSafe($fact['name'])
                        . ': '
                        . $this->terminalSafe($value),
                );
            }
            $this->renderOrigin($output, $indent, $outcome);
        } else {
            $output->writeln(
                $indent . $this->terminalSafe($result->infrastructureDetail ?? 'Test process failed.'),
            );
        }
        $output->writeln('');
    }

    private function renderOrigin(OutputInterface $output, string $indent, RuntimeOutcome $outcome): void
    {
        if ($outcome->origin === null) {
            return;
        }
        $location = $outcome->origin['path']
            . ':'
            . $outcome->origin['line']
            . ':'
            . $outcome->origin['column'];
        $output->writeln($indent . 'At: ' . $this->terminalSafe($location));
        if ($outcome->origin['function'] !== null
            && $outcome->origin['function'] !== ''
            && !$this->generatedCallable($outcome->origin['function'])
        ) {
            $output->writeln(
                $indent . 'Function: ' . $this->terminalSafe($outcome->origin['function']),
            );
        }
    }

    private function generatedCallable(string $function): bool
    {
        $separator = strrpos($function, '\\');
        $leaf = $separator === false ? $function : substr($function, $separator + 1);

        return str_starts_with($leaf, '__doria_test_');
    }

    private function renderOutput(OutputInterface $output, TestExecutionResult $result): void
    {
        $output->writeln('--- stdout ---');
        if ($result->process->stdout !== '') {
            $stdout = $this->terminalSafe($result->process->stdout, true);
            $output->write($stdout);
            if (!str_ends_with($stdout, "\n")) {
                $output->writeln('');
            }
        }
        $output->writeln('--- stderr ---');
        if ($result->process->stderr !== '') {
            $stderr = $this->terminalSafe($result->process->stderr, true);
            $output->write($stderr);
            if (!str_ends_with($stderr, "\n")) {
                $output->writeln('');
            }
        }
        $termination = $result->process->signaled
            ? 'signal ' . ($result->process->signal ?? 'unknown')
            : 'exit ' . ($result->process->exitCode ?? 'unknown');
        $output->writeln("--- {$termination} ---");
    }

    private function terminalSafe(string $value, bool $preserveNewlines = false): string
    {
        $safe = '';
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            $byte = ord($value[$index]);
            if ($byte === 10 && $preserveNewlines) {
                $safe .= "\n";
            } elseif ($byte < 32 || $byte === 127) {
                $safe .= sprintf('\\u%04x', $byte);
            } else {
                $safe .= $value[$index];
            }
        }

        return $safe;
    }

    /**
     * @return array{
     *   selected: int,
     *   passed: int,
     *   assertionFailed: int,
     *   unexpectedCheckedError: int,
     *   fatalPanic: int,
     *   abnormalProcessFailure: int
     * }
     */
    private function counts(int $selected): array
    {
        return [
            'selected' => $selected,
            'passed' => 0,
            'assertionFailed' => 0,
            'unexpectedCheckedError' => 0,
            'fatalPanic' => 0,
            'abnormalProcessFailure' => 0,
        ];
    }

    /**
     * @return 'passed'|'assertionFailed'|'unexpectedCheckedError'|'fatalPanic'|'abnormalProcessFailure'
     */
    private function countKey(TestOutcomeCategory $category): string
    {
        return match ($category) {
            TestOutcomeCategory::Passed => 'passed',
            TestOutcomeCategory::AssertionFailed => 'assertionFailed',
            TestOutcomeCategory::UnexpectedCheckedError => 'unexpectedCheckedError',
            TestOutcomeCategory::FatalPanic => 'fatalPanic',
            TestOutcomeCategory::AbnormalProcessFailure => 'abnormalProcessFailure',
        };
    }

    /** @return array<string, mixed> */
    private function testFact(ExecutableTest $test): array
    {
        return [
            'identity' => $test->identity,
            'displayName' => $test->displayName,
            'pathSegments' => $test->pathSegments,
            'origin' => $test->origin,
            'authoredSpelling' => $test->authoredSpelling,
            'callableIdentity' => $test->callableIdentity,
            'callableCanonicalName' => $test->callableCanonicalName,
            'source' => $test->source,
            'byteStart' => $test->location->byteStart,
            'authoredOrdinal' => $test->authoredOrdinal,
        ];
    }

    private function relative(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if ($path !== $root && !str_starts_with($path, $root . '/')) {
            throw new BatonError('B0424', 'Test Storage Escapes Project', $path);
        }

        return ltrim(substr($path, strlen($root)), '/');
    }

    private function string(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
