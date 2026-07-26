<?php

declare(strict_types=1);

namespace Doria\Baton;

use Doria\Baton\Commands\CheckCommand;
use Doria\Baton\Commands\DoctorCommand;
use Doria\Baton\Commands\NewCommand;
use Doria\Baton\Commands\StageGatedCommand;
use Doria\Baton\Commands\VersionCommand;
use Doria\Baton\Diagnostics\BatonError;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Builds the Baton CLI and freezes the bootstrap command boundary (plan B0).
 *
 * Available commands map to compiler capabilities that already exist. Commands
 * reserved for later stages are still *recognized* — they emit a stage-aware
 * diagnostic instead of an "unknown command" error, so the accepted language and
 * package design are never approximated by a temporary convention.
 */
final class Application
{
    /** Toolchain CalVer (zero-padded month), distinct from a package SemVer. */
    public const VERSION = '2026.07.1-canary';

    public static function create(): SymfonyApplication
    {
        $application = new SymfonyApplication('baton', self::VERSION);

        $application->addCommands([
            new NewCommand(),
            new CheckCommand(),
            new DoctorCommand(),
            new VersionCommand(),
        ]);

        $application->addCommands(self::stageGatedCommands());

        return $application;
    }

    /**
     * Commands recognized by the frozen boundary but not yet available in this
     * bootstrap build. Each returns a Baton diagnostic rather than executing.
     *
     * @return list<StageGatedCommand>
     */
    private static function stageGatedCommands(): array
    {
        $bootstrapPending = static fn (string $name): BatonError => new BatonError(
            'B0101',
            "`baton {$name}` Is Not Yet Implemented in This Build",
            "This command is part of the Baton bootstrap and is planned, but this\n"
                . "build does not implement it yet."
        );

        $stage33 = static fn (string $name, string $reason): BatonError => new BatonError(
            'B0102',
            "`baton {$name}` Is Not Available in This Toolchain",
            $reason
        );

        return [
            // Planned for the bootstrap, implemented in a later increment.
            new StageGatedCommand('build', 'Compile the current project', $bootstrapPending('build')),
            new StageGatedCommand('run', 'Compile and run the current project', $bootstrapPending('run')),

            // Deliberately deferred to the Stage 33 Baton MVP or later.
            new StageGatedCommand('test', 'Run project tests', $stage33(
                'test',
                "The Doria test convention requires #[Test] attribute support and\n"
                    . "lands with the Stage 33 Baton MVP."
            )),
            new StageGatedCommand('add', 'Add a dependency', $stage33(
                'add',
                "Dependency management (resolver and Baton.lock) lands with the\n"
                    . "Stage 33 Baton MVP."
            )),
            new StageGatedCommand('remove', 'Remove a dependency', $stage33(
                'remove',
                "Dependency management (resolver and Baton.lock) lands with the\n"
                    . "Stage 33 Baton MVP."
            )),
            new StageGatedCommand('publish', 'Publish the package', $stage33(
                'publish',
                "Publishing lands after the Stage 33 package-security model is\n"
                    . "established."
            )),
            new StageGatedCommand('bench', 'Run project benchmarks', $stage33(
                'bench',
                "Benchmark running lands with the Stage 33 Baton MVP."
            )),
        ];
    }
}
