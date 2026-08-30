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
use Doria\Baton\Process\BoundedProcessTimedOut;
use Doria\Baton\Process\ProcessOutputLimitExceeded;
use Doria\Baton\Toolchain\ToolchainSelection;
use Doria\Baton\Workspace\WorkspaceContext;
use Symfony\Component\Console\Output\OutputInterface;

final class TestPackageRunner
{
    private const PROCESS_TIMEOUT = 300.0;
    private const OUTPUT_LIMIT = 67_108_864;

    /**
     * @return array{selected: int, passed: int, failed: int}
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
        if ($tests === []) {
            $output->writeln("{$manifest->package->name}: 0 tests");

            return ['selected' => 0, 'passed' => 0, 'failed' => 0];
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

        $passed = 0;
        $failed = 0;
        foreach ($tests as $test) {
            $result = $this->execute($artifact, $test->identity, $projectRoot);
            $success = $result['exitCode'] === 0;
            if ($success) {
                ++$passed;
                $output->writeln("PASS {$manifest->package->name} {$test->displayName}");
            } else {
                ++$failed;
                $output->writeln("FAIL {$manifest->package->name} {$test->displayName}");
            }
            if ($showOutput || !$success) {
                $this->renderOutput($output, $test, $result);
            }
        }

        return ['selected' => count($tests), 'passed' => $passed, 'failed' => $failed];
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

    /** @return array{exitCode: int, stdout: string, stderr: string} */
    private function execute(string $artifact, string $test, string $workingDirectory): array
    {
        try {
            $result = (new BoundedProcessRunner())->run(
                [$artifact, $test],
                $workingDirectory,
                null,
                null,
                self::PROCESS_TIMEOUT,
                self::OUTPUT_LIMIT,
                self::OUTPUT_LIMIT,
            );
        } catch (BoundedProcessTimedOut $error) {
            return ['exitCode' => 1, 'stdout' => '', 'stderr' => $error->getMessage() . "\n"];
        } catch (ProcessOutputLimitExceeded $error) {
            return ['exitCode' => 1, 'stdout' => '', 'stderr' => $error->getMessage() . "\n"];
        }

        return ['exitCode' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr];
    }

    /**
     * @param array{exitCode: int, stdout: string, stderr: string} $result
     */
    private function renderOutput(OutputInterface $output, ExecutableTest $test, array $result): void
    {
        $output->writeln("--- {$test->displayName} stdout ---");
        if ($result['stdout'] !== '') {
            $output->write($result['stdout']);
            if (!str_ends_with($result['stdout'], "\n")) {
                $output->writeln('');
            }
        }
        $output->writeln("--- {$test->displayName} stderr ---");
        if ($result['stderr'] !== '') {
            $output->write($result['stderr']);
            if (!str_ends_with($result['stderr'], "\n")) {
                $output->writeln('');
            }
        }
        $output->writeln("--- exit {$result['exitCode']} ---");
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
