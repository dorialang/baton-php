<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Diagnostics\BatonError;

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
            'artifact' => $artifactRecord,
        ];
        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        ) . "\n";
        $this->files->write($context->layout->receipt, $json, 'Build Metadata Could Not Be Written');
    }
}
