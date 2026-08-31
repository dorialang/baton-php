<?php

declare(strict_types=1);

namespace Doria\Baton\Processor;

use Doria\Baton\Build\AtomicFileWriter;
use Doria\Baton\Build\BuildPlanBuilder;
use Doria\Baton\Build\BuildPlanWriter;
use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Compiler\MetadataReader;
use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Dependency\ResolvedDependencyGraph;
use Doria\Baton\Dependency\ResolvedPackage;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\ProcessorDeclaration;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\SelectedPackageTarget;
use Doria\Baton\Inventory\ManagedInventoryStore;
use Doria\Baton\Process\BoundedProcessRunner;
use Doria\Baton\Source\GeneratedSourceInput;
use Doria\Baton\Source\SourceInventory;
use Doria\Baton\Toolchain\ToolchainSelection;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @phpstan-type Metadata array{
 *   edition: string,
 *   compilerRevision: string,
 *   graphFingerprint: string,
 *   selectedTarget: array<string, mixed>,
 *   sources: list<array<string, mixed>>,
 *   attributeClasses: list<array<string, mixed>>,
 *   applications: list<array<string, mixed>>
 * }
 * @phpstan-type ProcessorRequest array<string, mixed>
 * @phpstan-type ProcessorDiagnostic array{
 *   code: string,
 *   title: string,
 *   severity: string,
 *   message: string,
 *   labels: mixed,
 *   explanation: mixed,
 *   help: mixed
 * }
 */
final class ProcessorPipeline
{
    private const PROTOCOL = 1;
    private const TIMEOUT_SECONDS = 300.0;
    private const STDOUT_LIMIT = 67_108_864;
    private const STDERR_LIMIT = 16_777_216;

    public function run(
        string $ownerRoot,
        string $storageRoot,
        Schema2Manifest $owner,
        SelectedPackageTarget $selected,
        SourceInventory $inventory,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        ToolchainSelection $toolchain,
        string $basePlanPath,
        NetworkPolicy $network,
        bool $development,
        ?OutputInterface $output = null,
    ): ProcessorRunResult {
        if ($owner->processors === []) {
            (new GeneratedSourceRegistry())->replaceOwner(
                $storageRoot,
                $toolchain->identity->commit,
                $owner,
                $inventory,
                $graph->packages,
                [],
                [],
            );
            (new ManagedInventoryStore())->removeProcessors(
                $storageRoot,
                $toolchain->identity->commit,
                $owner->package->compilerIdentity,
            );

            return new ProcessorRunResult([], []);
        }
        $output ??= new NullOutput();
        $basePlan = (new BuildPlanBuilder())->build(
            $ownerRoot,
            $owner,
            $selected,
            $inventory,
            'fast',
            $graph,
            $development,
        );
        $written = (new BuildPlanWriter())->write($basePlan, $basePlanPath);
        $metadataResult = (new CompilerAdapter($toolchain->compilerPath))->capture(
            ['metadata', '--schema-version', '2', '--build-plan', $written->path],
            $ownerRoot,
        );
        if (!$metadataResult->succeeded()) {
            throw new BatonError(
                'B0409',
                'Processor Metadata Could Not Be Produced',
                trim($metadataResult->stderr) ?: 'doriac metadata failed before processor execution.',
            );
        }
        $metadataDocument = (new MetadataReader())->schema2($metadataResult->stdout);
        $metadata = [
            'edition' => $metadataDocument->edition,
            'compilerRevision' => $metadataDocument->compilerRevision,
            'graphFingerprint' => $metadataDocument->graphFingerprint,
            'selectedTarget' => $metadataDocument->selectedTarget,
            'sources' => $metadataDocument->sources,
            'attributeClasses' => $metadataDocument->attributeClasses,
            'applications' => $metadataDocument->applications,
        ];
        $generated = [];
        $registry = [];
        $generatedIdentities = [];
        $processorFacts = [];
        foreach ($owner->processors as $declaration) {
            $applications = array_values(array_filter(
                $metadata['applications'],
                static fn (array $application): bool => $application['package'] === $owner->package->compilerIdentity
                    && in_array($application['attributeClass'], $declaration->attributes, true),
            ));
            if ($applications === []) {
                // A successful empty processor result replaces output from an older request.
                $this->publish($ownerRoot, $declaration, []);
                continue;
            }
            $request = $this->request($metadata, $declaration, $applications);
            $requestBytes = $this->json($request);
            $requestHash = hash('sha256', $requestBytes);
            $processor = $graph->packages[$declaration->package()] ?? null;
            if ($processor === null) {
                throw $this->error('B0408', 'Processor Package Is Missing', $declaration->package());
            }
            $processorSourceIdentity = (new ProcessorSourceIdentity())->calculate(
                $processor,
                $declaration->binary,
            );
            $binary = $this->processorBinary(
                $storageRoot,
                $processor,
                $declaration,
                $graph,
                $toolchain,
                $network,
            );
            $binaryHash = $this->fileHash($binary, 'Processor Binary Is Invalid');
            $cacheKey = hash('sha256', implode("\0", [
                (string) self::PROTOCOL,
                $toolchain->identity->commit,
                (string) $metadata['graphFingerprint'],
                $declaration->package(),
                $processorSourceIdentity,
                $binaryHash,
                implode("\0", $declaration->attributes),
                $requestBytes,
            ]));
            $cache = $storageRoot . DIRECTORY_SEPARATOR . 'build/.baton/processors/' . $cacheKey;
            $responseBytes = $this->cachedResponse($cache, $requestBytes);
            if ($responseBytes === null) {
                if ($network === NetworkPolicy::Offline) {
                    throw $this->error(
                        'B0417',
                        'Processor Cache Is Missing Offline',
                        "No exact complete processor result exists for `{$declaration->package()}`.",
                    );
                }
                $source = $processor->source->kind === 'git'
                    ? 'git ' . $processor->source->commit
                    : $processor->source->kind . ' ' . $processor->source->path;
                $output->writeln(
                    "Running processor {$declaration->package()} ({$source}) for {$owner->package->name} target {$declaration->binary}",
                );
                $responseBytes = $this->execute($binary, $requestBytes, $cache, $declaration);
                $this->writeCache($cache, $requestBytes, $responseBytes, $binaryHash);
            }
            $response = $this->response($responseBytes, $request, $inventory);
            $this->renderDiagnostics($response['diagnostics'], $output, $declaration);
            foreach ($response['generatedSources'] as $source) {
                $identity = strtolower($source['generatedFor'] . "\0" . $source['relativePath']);
                if (isset($generatedIdentities[$identity])) {
                    throw $this->error(
                        'B0415',
                        'Processor Generated Source Collides',
                        $source['relativePath'],
                    );
                }
                $generatedIdentities[$identity] = true;
            }
            $published = $this->publish(
                $ownerRoot,
                $declaration,
                $response['generatedSources'],
            );
            foreach ($published as $source) {
                $generated[] = new GeneratedSourceInput(
                    $source['relativePath'],
                    $source['generatedFor'],
                    null,
                    $source['relativePath'],
                    $source['sha256'],
                    $declaration->package(),
                    $owner->package->compilerIdentity,
                );
                $registry[] = [
                    'identity' => $owner->package->compilerIdentity . ':' . $source['relativePath'],
                    'package' => $owner->package->name,
                    'processor' => $declaration->package(),
                    'path' => $ownerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source['relativePath']),
                    'generatedFor' => $source['generatedFor'],
                    'requestSha256' => $requestHash,
                    'sha256' => $source['sha256'],
                ];
            }
            $processorFacts[] = [
                'owner' => $owner->package->compilerIdentity,
                'processor' => $declaration->package(),
                'sourceIdentitySha256' => hash('sha256', $processorSourceIdentity),
                'binaryTarget' => $declaration->binary,
                'binarySha256' => $binaryHash,
                'requestSha256' => $requestHash,
                'responseSha256' => hash('sha256', $responseBytes),
                'graphFingerprint' => (string) $metadata['graphFingerprint'],
                'generatedSha256' => hash('sha256', $this->json($published)),
            ];
        }
        (new GeneratedSourceRegistry())->replaceOwner(
            $storageRoot,
            $toolchain->identity->commit,
            $owner,
            $inventory,
            $graph->packages,
            $registry,
            $processorFacts,
        );
        (new ManagedInventoryStore())->recordProcessors(
            $storageRoot,
            $toolchain->identity->commit,
            $owner->package->compilerIdentity,
            hash('sha256', $metadataResult->stdout),
            $processorFacts,
        );

        return new ProcessorRunResult($generated, $processorFacts);
    }

    /**
     * @param Metadata $metadata
     * @param list<array<string, mixed>> $applications
     * @return ProcessorRequest
     */
    private function request(array $metadata, ProcessorDeclaration $processor, array $applications): array
    {
        $classes = [];
        foreach ($metadata['attributeClasses'] as $class) {
            if (in_array($class['identity'] ?? null, $processor->attributes, true)) {
                $classes[] = $class;
            }
        }

        return [
            'schemaVersion' => self::PROTOCOL,
            'edition' => $metadata['edition'],
            'compilerRevision' => $metadata['compilerRevision'],
            'graphFingerprint' => $metadata['graphFingerprint'],
            'processorPackage' => $processor->package(),
            'selectedTarget' => $metadata['selectedTarget'],
            'sources' => $metadata['sources'],
            'attributeClasses' => $classes,
            'applications' => $applications,
        ];
    }

    private function processorBinary(
        string $storageRoot,
        ResolvedPackage $processor,
        ProcessorDeclaration $declaration,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
        ToolchainSelection $toolchain,
        NetworkPolicy $network,
    ): string {
        $directory = $storageRoot . DIRECTORY_SEPARATOR . 'build/processors/'
            . str_replace('/', DIRECTORY_SEPARATOR, $processor->manifest->package->compilerIdentity)
            . DIRECTORY_SEPARATOR . $toolchain->identity->target
            . DIRECTORY_SEPARATOR . 'development'
            . DIRECTORY_SEPARATOR . $declaration->binary;
        $artifact = $directory . DIRECTORY_SEPARATOR . $declaration->binary
            . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $identityPath = $directory . DIRECTORY_SEPARATOR . 'identity.json';
        $sourceIdentity = (new ProcessorSourceIdentity())->calculate($processor, $declaration->binary);
        $expected = [
            'compilerCommit' => $toolchain->identity->commit,
            'processorPackage' => $processor->manifest->package->compilerIdentity,
            'sourceIdentity' => hash('sha256', $sourceIdentity),
            'target' => $declaration->binary,
        ];
        if (is_file($artifact) && is_file($identityPath)) {
            $identity = $this->decode((string) file_get_contents($identityPath), 'B0411', 'Processor Binary Identity Is Invalid');
            if ($identity === $expected + ['binarySha256' => $this->fileHash($artifact, 'Processor Binary Is Invalid')]) {
                return $artifact;
            }
        }
        if ($network === NetworkPolicy::Offline) {
            throw $this->error(
                'B0417',
                'Processor Cache Is Missing Offline',
                "Processor binary `{$declaration->package()}` is not available with the exact current identity.",
            );
        }
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw $this->error('B0411', 'Processor Binary Could Not Be Built', $directory);
        }
        $target = $processor->manifest->targets->binary($declaration->binary);
        if ($target === null) {
            throw $this->error('B0408', 'Processor Binary Target Is Missing', $declaration->binary);
        }
        $selected = new SelectedPackageTarget($target);
        $processorGraph = new ResolvedDependencyGraph(
            $processor->source->root,
            $processor->manifest,
            $processor->manifestFingerprint,
            $this->processorClosure($processor, $graph),
        );
        $plan = (new BuildPlanBuilder())->build(
            $processor->source->root,
            $processor->manifest,
            $selected,
            $processor->inventoryFor($selected),
            'fast',
            $processorGraph,
            false,
        );
        $written = (new BuildPlanWriter())->write($plan, $directory . DIRECTORY_SEPARATOR . 'build-plan.json');
        $result = (new CompilerAdapter($toolchain->compilerPath))->capture(
            ['compile', '--build-plan', $written->path, '--out', $artifact],
            $processor->source->root,
        );
        if (!$result->succeeded() || !is_file($artifact)) {
            throw $this->error(
                'B0411',
                'Processor Binary Could Not Be Built',
                trim($result->stderr) ?: "doriac did not produce {$artifact}.",
            );
        }
        $identity = $expected + ['binarySha256' => $this->fileHash($artifact, 'Processor Binary Is Invalid')];
        (new AtomicFileWriter())->write(
            $identityPath,
            $this->json($identity),
            'Processor Binary Identity Could Not Be Written',
        );

        return $artifact;
    }

    /** @return array<string, ResolvedPackage> */
    private function processorClosure(
        ResolvedPackage $processor,
        ResolvedDependencyGraph|ResolvedWorkspaceGraph $graph,
    ): array {
        $result = [];
        $visit = function (Schema2Manifest $manifest) use (&$visit, &$result, $graph): void {
            foreach ($manifest->dependencies as $dependency) {
                $resolved = $graph->packages[$dependency->package] ?? null;
                if ($resolved === null || isset($result[$dependency->package])) {
                    continue;
                }
                $result[$dependency->package] = $resolved;
                $visit($resolved->manifest);
            }
        };
        $visit($processor->manifest);
        ksort($result, SORT_STRING);

        return $result;
    }

    private function cachedResponse(string $cache, string $request): ?string
    {
        $complete = $cache . DIRECTORY_SEPARATOR . 'complete.json';
        $requestPath = $cache . DIRECTORY_SEPARATOR . 'request.json';
        $responsePath = $cache . DIRECTORY_SEPARATOR . 'response.json';
        if (!is_file($complete) || !is_file($requestPath) || !is_file($responsePath)) {
            return null;
        }
        $response = @file_get_contents($responsePath);
        if ($response === false
            || file_get_contents($requestPath) !== $request
            || hash_file('sha256', $responsePath) !== trim((string) file_get_contents($complete))
        ) {
            return null;
        }

        return $response;
    }

    private function execute(
        string $binary,
        string $request,
        string $workingDirectory,
        ProcessorDeclaration $declaration,
    ): string {
        if (!is_dir($workingDirectory) && !@mkdir($workingDirectory, 0o755, true) && !is_dir($workingDirectory)) {
            throw $this->error('B0412', 'Processor Execution Failed', $workingDirectory);
        }
        $result = (new BoundedProcessRunner())->run(
            [$binary],
            $workingDirectory,
            $this->sanitizedEnvironment(),
            $request,
            self::TIMEOUT_SECONDS,
            self::STDOUT_LIMIT,
            self::STDERR_LIMIT,
        );
        if ($result->timedOut) {
            throw $this->error('B0413', 'Processor Timed Out', $declaration->package());
        }
        if ($result->outputLimitStream !== null) {
            throw $this->error('B0414', 'Processor Output Is Too Large', $declaration->package());
        }
        $stdout = $result->stdout;
        $stderr = $result->stderr;
        if ($this->unsafeTerminal($stderr)) {
            throw $this->error('B0415', 'Processor Log Contains Terminal Controls', $declaration->package());
        }
        if ($stderr !== '') {
            fwrite(STDERR, "[processor {$declaration->package()} stderr]\n{$stderr}");
        }
        if ($result->exitCode !== 0 || $result->signaled) {
            $termination = $result->signaled
                ? 'signal ' . ($result->signal ?? 'unknown')
                : 'status ' . ($result->exitCode ?? 'unknown');
            throw $this->error(
                'B0412',
                'Processor Execution Failed',
                "Processor `{$declaration->package()}` exited with {$termination}.",
            );
        }

        return $stdout;
    }

    private function writeCache(string $cache, string $request, string $response, string $binaryHash): void
    {
        if (!is_dir($cache) && !@mkdir($cache, 0o755, true) && !is_dir($cache)) {
            throw $this->error('B0416', 'Processor Cache Could Not Be Written', $cache);
        }
        $files = new AtomicFileWriter();
        $files->write($cache . '/request.json', $request, 'Processor Cache Could Not Be Written');
        $files->write($cache . '/response.json', $response, 'Processor Cache Could Not Be Written');
        $files->write($cache . '/binary.sha256', $binaryHash . "\n", 'Processor Cache Could Not Be Written');
        $files->write(
            $cache . '/complete.json',
            hash('sha256', $response) . "\n",
            'Processor Cache Could Not Be Written',
        );
    }

    /**
     * @param ProcessorRequest $request
     * @return array{diagnostics: list<ProcessorDiagnostic>, generatedSources: list<array{relativePath: string, generatedFor: string, contents: string, contentHash: string}>}
     */
    private function response(string $json, array $request, SourceInventory $inventory): array
    {
        $value = $this->decode($json, 'B0415', 'Processor Response Is Invalid');
        $this->exactKeys($value, ['schemaVersion', 'graphFingerprint', 'diagnostics', 'generatedSources'], 'B0415', 'Processor Response Is Invalid');
        if ($value['schemaVersion'] !== self::PROTOCOL
            || $value['graphFingerprint'] !== $request['graphFingerprint']
            || !is_array($value['diagnostics'])
            || !is_array($value['generatedSources'])
        ) {
            throw $this->error('B0415', 'Processor Response Is Invalid', 'Protocol version, graph fingerprint, or response arrays are invalid.');
        }
        $handwritten = [];
        foreach ($inventory->sources as $source) {
            $handwritten[strtolower($source->relativePath)] = true;
        }
        $sources = [];
        $folded = [];
        foreach ($this->objectList($value['generatedSources'], 'Processor Response Is Invalid') as $source) {
            $this->exactKeys($source, ['relativePath', 'generatedFor', 'contents', 'contentHash'], 'B0415', 'Processor Response Is Invalid');
            $path = $source['relativePath'];
            $scope = $source['generatedFor'];
            $contents = $source['contents'];
            $hash = $source['contentHash'];
            if (!is_string($path)
                || !is_string($scope)
                || !is_string($contents)
                || !is_string($hash)
                || !$this->validGeneratedPath($path)
                || !in_array($scope, ['main', 'development'], true)
                || preg_match('//u', $contents) !== 1
                || hash('sha256', $contents) !== $hash
            ) {
                throw $this->error('B0415', 'Processor Response Is Invalid', 'Generated source path, scope, UTF-8, or content hash is invalid.');
            }
            $key = strtolower($path);
            if (isset($folded[$key]) || isset($handwritten[$key])) {
                throw $this->error('B0415', 'Processor Generated Source Collides', $path);
            }
            $folded[$key] = true;
            $sources[] = [
                'relativePath' => $path,
                'generatedFor' => $scope,
                'contents' => $contents,
                'contentHash' => $hash,
            ];
        }
        $diagnostics = [];
        foreach ($this->objectList($value['diagnostics'], 'Processor Diagnostic Is Invalid') as $diagnostic) {
            $this->exactKeys(
                $diagnostic,
                ['code', 'title', 'severity', 'message', 'labels', 'explanation', 'help'],
                'B0415',
                'Processor Diagnostic Is Invalid',
            );
            $code = $diagnostic['code'];
            $title = $diagnostic['title'];
            $severity = $diagnostic['severity'];
            $message = $diagnostic['message'];
            if (!is_string($code)
                || !preg_match('/^[A-Z][A-Z0-9_-]*$/D', $code)
                || str_starts_with($code, 'B')
                || str_starts_with($code, 'E')
                || str_starts_with($code, 'P')
                || !is_string($title)
                || preg_match('/^[A-Z][A-Za-z0-9]*(?: [A-Z][A-Za-z0-9]*)*$/D', $title) !== 1
                || !is_string($severity)
                || !in_array($severity, ['error', 'warning', 'information'], true)
                || !is_string($message)
                || $this->unsafeTerminal($this->json($diagnostic))
            ) {
                throw $this->error('B0415', 'Processor Diagnostic Is Invalid', 'Processor diagnostic fields are invalid or claim a reserved code.');
            }
            $diagnostics[] = [
                'code' => $code,
                'title' => $title,
                'severity' => $severity,
                'message' => $message,
                'labels' => $diagnostic['labels'],
                'explanation' => $diagnostic['explanation'],
                'help' => $diagnostic['help'],
            ];
        }

        return ['diagnostics' => $diagnostics, 'generatedSources' => $sources];
    }

    /** @param list<ProcessorDiagnostic> $diagnostics */
    private function renderDiagnostics(array $diagnostics, OutputInterface $output, ProcessorDeclaration $processor): void
    {
        $failed = false;
        foreach ($diagnostics as $diagnostic) {
            $output->writeln(
                "[processor {$processor->package()} {$diagnostic['severity']} {$diagnostic['code']}] "
                . "{$diagnostic['title']}: {$diagnostic['message']}",
            );
            $failed = $failed || $diagnostic['severity'] === 'error';
        }
        if ($failed) {
            throw $this->error('B0415', 'Processor Reported An Error', $processor->package());
        }
    }

    /**
     * @param list<array{relativePath: string, generatedFor: string, contents: string, contentHash: string}> $sources
     * @return list<array{relativePath: string, generatedFor: string, sha256: string}>
     */
    private function publish(string $ownerRoot, ProcessorDeclaration $processor, array $sources): array
    {
        $baseRelative = 'build/generated/' . $processor->package();
        $base = $ownerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $baseRelative);
        $parent = dirname($base);
        if (!is_dir($parent) && !@mkdir($parent, 0o755, true) && !is_dir($parent)) {
            throw $this->error('B0416', 'Processor Generated Sources Could Not Be Written', $parent);
        }
        $temporary = $parent . DIRECTORY_SEPARATOR . '.' . basename($base) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (!mkdir($temporary, 0o755, true)) {
            throw $this->error('B0416', 'Processor Generated Sources Could Not Be Written', $temporary);
        }
        $published = [];
        try {
            foreach ($sources as $source) {
                $relative = $source['generatedFor'] . '/' . $source['relativePath'];
                $path = $temporary . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o755, true) && !is_dir(dirname($path))) {
                    throw $this->error('B0416', 'Processor Generated Sources Could Not Be Written', $path);
                }
                if (file_put_contents($path, $source['contents'], LOCK_EX) !== strlen($source['contents'])) {
                    throw $this->error('B0416', 'Processor Generated Sources Could Not Be Written', $path);
                }
                $published[] = [
                    'relativePath' => $baseRelative . '/' . $relative,
                    'generatedFor' => $source['generatedFor'],
                    'sha256' => $source['contentHash'],
                ];
            }
            $backup = $base . '.previous';
            $this->removeTree($backup);
            if (is_dir($base) && !rename($base, $backup)) {
                throw $this->error('B0416', 'Processor Generated Sources Could Not Be Written', $base);
            }
            if (!rename($temporary, $base)) {
                if (is_dir($backup)) {
                    rename($backup, $base);
                }
                throw $this->error('B0416', 'Processor Generated Sources Could Not Be Written', $base);
            }
            $this->removeTree($backup);
        } catch (\Throwable $error) {
            $this->removeTree($temporary);
            throw $error;
        }

        return $published;
    }

    /** @return array<string, string> */
    private function sanitizedEnvironment(): array
    {
        $environment = [];
        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'HOME', 'USERPROFILE', 'TMP', 'TEMP', 'TMPDIR'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }
        $environment['NO_COLOR'] = '1';

        return $environment;
    }

    private function validGeneratedPath(string $path): bool
    {
        return $path !== ''
            && str_ends_with($path, '.doria')
            && !str_contains($path, "\0")
            && !str_contains($path, '\\')
            && !str_contains($path, '//')
            && !str_starts_with($path, '/')
            && preg_match('/^[A-Za-z]:\//', $path) !== 1
            && !in_array('..', explode('/', $path), true)
            && !in_array('.', explode('/', $path), true);
    }

    private function unsafeTerminal(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }

    /** @return array<string, mixed> */
    private function decode(string $json, string $code, string $heading): array
    {
        try {
            $value = json_decode($json, true, 256, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw $this->error($code, $heading, $error->getMessage());
        }
        if (!is_array($value) || array_is_list($value)) {
            throw $this->error($code, $heading, 'Expected a JSON object.');
        }
        $document = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw $this->error($code, $heading, 'Expected string JSON object keys.');
            }
            $document[$key] = $item;
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function exactKeys(array $value, array $keys, string $code, string $heading): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            throw $this->error($code, $heading, 'JSON object contains missing or unknown fields.');
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $heading): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw $this->error('B0415', $heading, 'Expected a JSON object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw $this->error('B0415', $heading, 'Expected string JSON object keys.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /** @return list<array<string, mixed>> */
    private function objectList(mixed $value, string $heading): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->error('B0415', $heading, 'Expected a JSON array.');
        }
        $objects = [];
        foreach ($value as $item) {
            $objects[] = $this->object($item, $heading);
        }

        return $objects;
    }

    private function fileHash(string $path, string $heading): string
    {
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw $this->error('B0411', $heading, $path);
        }

        return $hash;
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    private function error(string $code, string $heading, string $body): BatonError
    {
        return new BatonError($code, $heading, $body);
    }
}
