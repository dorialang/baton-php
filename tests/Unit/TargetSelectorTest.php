<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\BinaryTarget;
use Doria\Baton\Manifest\LibraryTarget;
use Doria\Baton\Manifest\Manifest;
use Doria\Baton\Manifest\PackageDefinition;
use Doria\Baton\Manifest\Schema2Manifest;
use Doria\Baton\Manifest\TargetCollection;
use Doria\Baton\Manifest\TargetSelector;
use Doria\Baton\Manifest\AutoloadConfiguration;
use Doria\Baton\Tests\TestCase;

final class TargetSelectorTest extends TestCase
{
    public function testSelectionRulesAreSharedAcrossCommands(): void
    {
        $manifest = $this->manifest(
            new LibraryTarget('blog'),
            [new BinaryTarget('web', 'src/web.doria'), new BinaryTarget('worker', 'src/worker.doria')],
        );
        $selector = new TargetSelector();

        self::assertSame('web', $selector->select($manifest, 'web', false, 'build')->name());
        self::assertSame('blog', $selector->select($manifest, null, true, 'check')->name());

        foreach (['check', 'build', 'run'] as $command) {
            try {
                $selector->select($manifest, null, false, $command);
                self::fail("{$command} should require an explicit target.");
            } catch (BatonError $error) {
                self::assertSame('Target Selection Is Ambiguous', $error->heading);
                self::assertStringContainsString('Binary: web, worker', $error->body);
                self::assertStringContainsString('Library: blog', $error->body);
            }
        }
    }

    public function testOneTargetAutoSelectsButRunNeverSelectsALibrary(): void
    {
        $selector = new TargetSelector();
        $binary = $this->manifest(null, [new BinaryTarget('web', 'src/web.doria')]);
        self::assertSame('web', $selector->select($binary, null, false, 'run')->name());

        $library = $this->manifest(new LibraryTarget('blog'), []);
        self::assertSame('blog', $selector->select($library, null, false, 'check')->name());
        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Library Target Cannot Be Run');
        $selector->select($library, null, false, 'run');
    }

    public function testConflictingAndUnknownSelectorsArePrecise(): void
    {
        $manifest = $this->manifest(null, [new BinaryTarget('web', 'src/web.doria')]);
        $selector = new TargetSelector();

        try {
            $selector->select($manifest, 'web', true, 'check');
            self::fail('Conflicting selectors should fail.');
        } catch (BatonError $error) {
            self::assertSame('Target Selectors Conflict', $error->heading);
            self::assertStringNotContainsString('--target', $error->render());
        }

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Binary Target Is Unknown');
        $selector->select($manifest, 'missing', false, 'check');
    }

    public function testSchemaOneKeepsItsImplicitBinaryBoundary(): void
    {
        $manifest = new Manifest(1, 'legacy', '0.1.0', 'binary', 'src/main.doria');
        $selector = new TargetSelector();

        self::assertSame('legacy', $selector->select($manifest, null, false, 'build')->name());
        self::assertSame('legacy', $selector->select($manifest, 'legacy', false, 'build')->name());

        $this->expectException(BatonError::class);
        $this->expectExceptionMessage('Library Target Is Not Declared');
        $selector->select($manifest, null, true, 'build');
    }

    /** @param list<BinaryTarget> $binaries */
    private function manifest(?LibraryTarget $library, array $binaries): Schema2Manifest
    {
        return new Schema2Manifest(
            new PackageDefinition('acme/blog', 'acme/blog', '1.0.0', '2026', true),
            new TargetCollection($library, $binaries),
            new AutoloadConfiguration([], []),
        );
    }
}
