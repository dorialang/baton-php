<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Dependency\PathContentFingerprint;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Dependency\ResolvedWorkspaceGraph;

final readonly class BuildReceiptWriter
{
    public function __construct(private AtomicFileWriter $files = new AtomicFileWriter())
    {
    }

    public function write(Schema2ProjectContext $context, ?string $artifact): void
    {
        $artifactRecord = null;
        if ($artifact !== null) {
            $hash = hash_file('sha256', $artifact);
            if ($hash === false) {
                throw new BatonError(
                    'B0405',
                    'Build Metadata Could Not Be Written',
                    "The build artifact could not be hashed:\n    {$artifact}",
                    ['Check that the artifact remains readable, then rebuild:'],
                    ['baton build'],
                );
            }
            $artifactRecord = [
                'path' => basename($artifact),
                'sha256' => $hash,
            ];
        }

        $document = [
            'schemaVersion' => 1,
            'manifestVersion' => 2,
            'package' => $context->manifest->package->name,
            'compilerPackage' => $context->manifest->package->compilerIdentity,
            'packageVersion' => $context->manifest->package->version,
            'publishable' => $context->manifest->package->publishable,
            'edition' => $context->manifest->package->edition,
            'target' => [
                'name' => $context->selected->name(),
                'kind' => $context->selected->kind(),
            ],
            'toolchain' => [
                'version' => $context->toolchain->identity->toolchainVersion,
                'compilerCommit' => $context->toolchain->identity->commit,
            ],
            'hostTarget' => $context->toolchain->identity->target,
            'profile' => $context->profile,
            'buildPlan' => basename($context->buildPlan->path),
            'buildPlanSha256' => $context->buildPlan->sha256,
            'lock' => $context->lockSha256 === null ? null : [
                'path' => 'Baton.lock',
                'sha256' => $context->lockSha256,
            ],
            'pathDependencies' => $this->pathDependencies($context),
            'artifact' => $artifactRecord,
        ];
        if ($context->graph instanceof ResolvedWorkspaceGraph) {
            $document['workspace'] = [
                'lockSha256' => $context->lockSha256,
                'selectedMember' => $context->manifest->package->compilerIdentity,
            ];
        }
        $generatedSources = $this->generatedSources($context);
        if ($generatedSources !== []) {
            $document['generatedSources'] = $generatedSources;
        }
        if ($context->processorFacts !== []) {
            $document['processors'] = array_map(static fn (array $fact): array => [
                'owningPackage' => $fact['owner'],
                'package' => $fact['processor'],
                'sourceIdentitySha256' => $fact['sourceIdentitySha256'],
                'binarySha256' => $fact['binarySha256'],
                'requestSha256' => $fact['requestSha256'],
                'responseSha256' => $fact['responseSha256'],
            ], $context->processorFacts);
        }
        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->files->write($context->layout->receipt, $json, 'Build Metadata Could Not Be Written');
    }

    /** @return list<array{package: string, compilerPackage: string, contentSha256: string}> */
    private function pathDependencies(Schema2ProjectContext $context): array
    {
        $fingerprints = new PathContentFingerprint();
        $dependencies = [];
        foreach ($context->graph->sortedPackages() as $package) {
            if ($package->source->kind !== 'path') {
                continue;
            }
            $dependencies[] = [
                'package' => $package->manifest->package->name,
                'compilerPackage' => $package->manifest->package->compilerIdentity,
                'contentSha256' => $fingerprints->calculate(
                    $package->manifestFingerprint,
                    $package->inventory,
                ),
            ];
        }

        return $dependencies;
    }

    /**
     * @return list<array{producerPackage: string, owningPackage: string, generatedFor: string, relativePath: string, contentSha256: string}>
     */
    private function generatedSources(Schema2ProjectContext $context): array
    {
        $sources = [];
        foreach ($context->generatedSources as $source) {
            if ($source->producer === null) {
                continue;
            }
            $sources[] = [
                'producerPackage' => $source->producer,
                'owningPackage' => $source->owner ?? $context->manifest->package->compilerIdentity,
                'generatedFor' => $source->generatedFor,
                'relativePath' => str_replace('\\', '/', $source->relativePath),
                'contentSha256' => $source->contentHash,
            ];
        }
        usort($sources, static fn (array $left, array $right): int => strcmp(
            $left['owningPackage'] . "\0" . $left['generatedFor'] . "\0" . $left['relativePath'],
            $right['owningPackage'] . "\0" . $right['generatedFor'] . "\0" . $right['relativePath'],
        ));

        return $sources;
    }
}
