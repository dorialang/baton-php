<?php

declare(strict_types=1);

namespace Doria\Baton\Build;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Inventory\ManagedInventoryStore;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class Schema2BuildService
{
    public function build(
        Schema2ProjectContext $context,
        OutputInterface $output,
        ?string $explicitOutput = null,
    ): int {
        if (!$context->selected->isBinary()) {
            if ($explicitOutput !== null) {
                throw new BatonError(
                    'B0406',
                    'Library Target Has No Artifact',
                    '`--out` cannot be used for a library. Stage 33 Slice 1 validates '
                        . 'library source through the compiler but does not invent an archive.',
                );
            }
            $this->removePrevious($context->layout->receipt);
            $exitCode = $this->compiler(
                $context,
                ['check', '--build-plan', $context->buildPlan->path],
                $output,
            );
            if ($exitCode !== 0) {
                return $exitCode;
            }
            (new BuildReceiptWriter())->write($context, null);
            (new ManagedInventoryStore())->recordSuccessfulOutput($context->storageRoot, $context, null);

            return 0;
        }

        $artifact = $explicitOutput ?? $context->layout->artifact;
        $directory = dirname($artifact);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw $this->outputError($directory);
        }
        $this->removePrevious($artifact);
        if ($explicitOutput === null) {
            $this->removePrevious($context->layout->receipt);
        }

        $exitCode = $this->compiler(
            $context,
            ['compile', '--build-plan', $context->buildPlan->path, '--out', $artifact],
            $output,
        );
        if ($exitCode !== 0) {
            $this->removePrevious($artifact);

            return $exitCode;
        }
        if (!is_file($artifact)) {
            throw new BatonError(
                'B0402',
                'Compiler Did Not Produce Build Artifact',
                "The compiler exited successfully without writing:\n    {$artifact}",
                ['Rebuild with compiler and build detail shown, so the cause is visible:'],
                ['baton build -vv'],
            );
        }
        if ($explicitOutput === null) {
            (new BuildReceiptWriter())->write($context, $artifact);
        }
        (new ManagedInventoryStore())->recordSuccessfulOutput($context->storageRoot, $context, $artifact);

        return 0;
    }

    /** @param list<string> $arguments */
    private function compiler(
        Schema2ProjectContext $context,
        array $arguments,
        OutputInterface $output,
    ): int {
        $adapter = new CompilerAdapter($context->toolchain->compilerPath);
        if (!$output instanceof BufferedOutput) {
            return $adapter->passthrough($arguments, $context->projectRoot);
        }

        $result = $adapter->capture($arguments, $context->projectRoot);
        if ($result->stdout !== '') {
            $output->write($result->stdout);
        }
        if ($result->stderr !== '') {
            $output->write($result->stderr);
        }

        return $result->exitCode;
    }

    private function removePrevious(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) || !@unlink($path)) {
            throw $this->outputError($path);
        }
    }

    private function outputError(string $path): BatonError
    {
        return new BatonError(
            'B0401',
            'Build Output Could Not Be Prepared',
            "Failed to prepare:\n    {$path}",
            ['Check that the build and output locations are writable:'],
            ['baton doctor'],
        );
    }
}
