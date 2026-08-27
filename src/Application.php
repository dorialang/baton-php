<?php

declare(strict_types=1);

namespace Doria\Baton;

use Doria\Baton\Commands\BuildCommand;
use Doria\Baton\Commands\CheckCommand;
use Doria\Baton\Commands\DoctorCommand;
use Doria\Baton\Commands\NewCommand;
use Doria\Baton\Commands\RunCommand;
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
    public const VERSION = '2026.03.1-canary';

    public static function create(): SymfonyApplication
    {
        $application = new SymfonyApplication('baton', self::VERSION);

        $application->addCommands([
            new NewCommand(),
            new CheckCommand(),
            new BuildCommand(),
            new RunCommand(),
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
        $stage33 = static fn (string $name, string $reason): BatonError => new BatonError(
            'B0102',
            "`baton {$name}` Is Not Available in This Toolchain",
            $reason
        );

        return [
            // Deliberately deferred to the Stage 33 Baton MVP or later.
            new StageGatedCommand('test', 'Run project tests', $stage33(
                'test',
                "Compiler-owned #[Test] metadata is available. Test discovery and\n"
                    . "execution land in Stage 33 Slice 3."
            )),
            new StageGatedCommand('install', 'Install package dependencies', $stage33(
                'install',
                "Dependency resolution, Baton.lock, and installation land in\n"
                    . "Stage 33 Slice 2."
            )),
            new StageGatedCommand('add', 'Add a dependency', $stage33(
                'add',
                "Dependency mutation and resolution land in Stage 33 Slice 2."
            )),
            new StageGatedCommand('remove', 'Remove a dependency', $stage33(
                'remove',
                "Dependency mutation and resolution land in Stage 33 Slice 2."
            )),
            new StageGatedCommand('update', 'Update package dependencies', $stage33(
                'update',
                "Dependency updates and lockfile ownership land in Stage 33 Slice 2."
            )),
            new StageGatedCommand('fetch', 'Fetch package sources', $stage33(
                'fetch',
                "Package source fetching and caching land in Stage 33 Slice 2."
            )),
            new StageGatedCommand('tree', 'Show the dependency tree', $stage33(
                'tree',
                "Resolved dependency graph inspection lands in Stage 33 Slice 2."
            )),
            new StageGatedCommand('why', 'Explain why a dependency is present', $stage33(
                'why',
                "Resolved dependency graph inspection lands in Stage 33 Slice 2."
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
