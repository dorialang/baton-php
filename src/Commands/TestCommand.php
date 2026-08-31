<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\NetworkPolicy;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Testing\TestPackageRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'test', description: 'Discover, compile, and run project tests')]
final class TestCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Select tests containing this case-sensitive substring')
            ->addOption('release', null, InputOption::VALUE_NONE, 'Compile the test dispatcher with the release profile')
            ->addOption('show-output', null, InputOption::VALUE_NONE, 'Show stdout and stderr for passing tests');
        CompilerOptions::configure($this);
        DependencyOptions::configureOffline($this);
        WorkspaceOptions::configure($this, true);
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $selection = WorkspaceOptions::select($input, true, 'test');
        $toolchain = CompilerOptions::locate($input);
        /** @var string|null $filter */
        $filter = $input->getOption('filter');
        $members = [];
        if ($selection->aggregate) {
            $workspace = $selection->workspace ?? throw new \LogicException('Workspace test selection is missing.');
            $members = $workspace->sortedMembers();
            usort($members, static fn ($left, $right): int => strcmp(
                $left->manifest->package->compilerIdentity,
                $right->manifest->package->compilerIdentity,
            ));
        } elseif ($selection->manifest instanceof Schema2Manifest) {
            $members[] = new \Doria\Baton\Workspace\WorkspaceMember(
                $selection->projectRoot,
                '.',
                $selection->projectRoot . DIRECTORY_SEPARATOR . 'Baton.toml',
                $selection->manifest,
            );
        } else {
            throw new BatonError('B0421', 'Tests Require Manifest Schema 2', '`baton test` requires a schema-2 package.');
        }

        $selected = 0;
        $passed = 0;
        $assertionFailed = 0;
        $unexpectedCheckedError = 0;
        $fatalPanic = 0;
        $abnormalProcessFailure = 0;
        $packageFailures = 0;
        foreach ($members as $member) {
            try {
                $result = (new TestPackageRunner())->run(
                    $member->root,
                    $selection->lockRoot,
                    $member->manifest,
                    $selection->workspace,
                    $toolchain,
                    (bool) $input->getOption('offline') ? NetworkPolicy::Offline : NetworkPolicy::Online,
                    (bool) $input->getOption('release'),
                    $filter,
                    (bool) $input->getOption('show-output'),
                    $output,
                );
                $selected += $result['selected'];
                $passed += $result['passed'];
                $assertionFailed += $result['assertionFailed'];
                $unexpectedCheckedError += $result['unexpectedCheckedError'];
                $fatalPanic += $result['fatalPanic'];
                $abnormalProcessFailure += $result['abnormalProcessFailure'];
            } catch (BatonError $error) {
                ++$packageFailures;
                $this->errorOutput($output)->writeln($error->render());
            }
        }
        if ($filter !== null && $selected === 0 && $packageFailures === 0) {
            throw new BatonError(
                'B0422',
                'No Tests Match The Filter',
                "No test display name contains the case-sensitive substring `{$filter}`.",
            );
        }
        $output->writeln('');
        $output->writeln('Test Summary');
        $output->writeln('');
        $output->writeln(sprintf('  Passed:                    %d', $passed));
        $output->writeln(sprintf('  Assertion Failed:          %d', $assertionFailed));
        $output->writeln(sprintf('  Unexpected Checked Error:  %d', $unexpectedCheckedError));
        $output->writeln(sprintf('  Fatal Panic:               %d', $fatalPanic));
        $output->writeln(sprintf('  Abnormal Process Failure:  %d', $abnormalProcessFailure + $packageFailures));
        $output->writeln(sprintf('  Total:                     %d', $selected + $packageFailures));

        $failed = $assertionFailed
            + $unexpectedCheckedError
            + $fatalPanic
            + $abnormalProcessFailure
            + $packageFailures;

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
