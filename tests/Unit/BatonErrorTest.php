<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Tests\TestCase;

/**
 * The rendered shape of a Baton diagnostic is a public contract, and the part
 * that matters most to a reader is the remedy: a failure that a command can fix
 * has to name that command instead of leaving it to be recalled.
 */
final class BatonErrorTest extends TestCase
{
    public function testRendersHeadingAndBodyWithoutAHelpSectionWhenThereIsNoRemedy(): void
    {
        $error = new BatonError('B0102', 'Not Available', 'Deferred to a later toolchain.');

        self::assertSame(
            "Error[B0102]: Not Available\n\nDeferred to a later toolchain.",
            $error->render()
        );
        self::assertStringNotContainsString('Help', $error->render());
    }

    public function testRendersRunnableCommandsUnderHelp(): void
    {
        $error = new BatonError(
            'B0201',
            'Incompatible Doria Compiler',
            'The compiler does not belong to this Baton toolchain.',
            ['Check which component is behind:'],
            ['baton doctor'],
        );

        self::assertSame(
            "Error[B0201]: Incompatible Doria Compiler\n\n"
                . "The compiler does not belong to this Baton toolchain.\n\n"
                . "Help\n"
                . "Check which component is behind:\n\n"
                . '    baton doctor',
            $error->render()
        );
    }

    public function testRendersEveryCommandInTheOrderGiven(): void
    {
        $error = new BatonError(
            'B0404',
            'Built Program Could Not Be Started',
            'Failed to run the artifact.',
            ['Rebuild the artifact, then run it again:'],
            ['baton build', 'baton run'],
        );

        self::assertStringContainsString("\n    baton build\n    baton run", $error->render());
    }

    public function testRendersHelpWithoutCommandsWhenNoCommandFixesTheProblem(): void
    {
        $error = new BatonError(
            'B0204',
            'Unsupported Host Architecture',
            'Baton has no toolchain architecture name for this machine.',
            ['No command fixes this. Doria toolchains are published for x86_64 and aarch64.'],
        );

        $rendered = $error->render();
        self::assertStringContainsString('Help', $rendered);
        self::assertStringContainsString('No command fixes this.', $rendered);
        self::assertStringNotContainsString('    baton', $rendered);
    }

    /**
     * The commands are data, not prose to be parsed back out of the rendering,
     * so `doctor` and editor integrations can offer them directly.
     */
    public function testExposesTheRemedyAsStructuredData(): void
    {
        $error = new BatonError('B0301', 'No Baton Project Found', '', [], ['baton new <name>']);

        self::assertSame(['baton new <name>'], $error->run);
    }
}
