<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Build\Schema2ProjectContextFactory;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\TargetSelector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'check',
    description: 'Type-check the current project without producing a binary',
)]
final class CheckCommand extends BatonCommand
{
    protected function configure(): void
    {
        TargetOptions::configure($this);
        CompilerOptions::configure($this);
        DependencyOptions::configureOffline($this);
        WorkspaceOptions::configure($this, true);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $selection = WorkspaceOptions::select($input, true, 'check');
        if ($selection->aggregate) {
            return $this->checkWorkspace($input, $output, $selection->workspace);
        }
        $projectRoot = $selection->projectRoot;
        $manifest = $selection->manifest;
        if ($manifest === null) {
            throw new \LogicException('Package check requires one selected package.');
        }
        [$binary, $library] = TargetOptions::read($input);
        $selected = (new TargetSelector())->select($manifest, $binary, $library, 'check');
        $toolchain = CompilerOptions::locate($input);

        if ($manifest instanceof Manifest) {
            // Schema 1 deliberately retains its direct compiler boundary.
            return (new CompilerAdapter($toolchain->compilerPath))
                ->passthrough(['check', $manifest->entry], $projectRoot);
        }

        $context = (new Schema2ProjectContextFactory())->create(
            $projectRoot,
            $manifest,
            $selected,
            $toolchain,
            'development',
            network: DependencyOptions::network($input),
            workspace: $selection->workspace,
            output: $output,
        );

        return (new CompilerAdapter($toolchain->compilerPath))->passthrough(
            ['check', '--build-plan', $context->buildPlan->path],
            $projectRoot,
        );
    }

    private function checkWorkspace(
        InputInterface $input,
        OutputInterface $output,
        ?\Doria\Baton\Workspace\WorkspaceContext $workspace,
    ): int {
        if ($workspace === null) {
            throw new \LogicException('Aggregate check requires a workspace.');
        }
        [$binary, $library] = TargetOptions::read($input);
        if ($binary !== null || $library) {
            throw new \Doria\Baton\Diagnostics\BatonError(
                'B0398',
                'Workspace Target Selection Is Invalid',
                '`check --workspace` checks every declared member target; do not combine it with a target selector.',
            );
        }
        $toolchain = CompilerOptions::locate($input);
        foreach ($workspace->sortedMembers() as $member) {
            $targets = $member->manifest->targets->all();
            usort($targets, static fn ($left, $right): int => strcmp(
                $left->kind() . "\0" . $left->name(),
                $right->kind() . "\0" . $right->name(),
            ));
            foreach ($targets as $target) {
                $context = (new Schema2ProjectContextFactory())->create(
                    $member->root,
                    $member->manifest,
                    new \Doria\Baton\Manifest\SelectedPackageTarget($target),
                    $toolchain,
                    'development',
                    network: DependencyOptions::network($input),
                    workspace: $workspace,
                    output: $output,
                );
                $exit = (new CompilerAdapter($toolchain->compilerPath))->passthrough(
                    ['check', '--build-plan', $context->buildPlan->path],
                    $member->root,
                );
                if ($exit !== self::SUCCESS) {
                    return $exit;
                }
            }
        }

        return self::SUCCESS;
    }
}
