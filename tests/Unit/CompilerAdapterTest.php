<?php

declare(strict_types=1);

namespace Doria\Baton\Tests\Unit;

use Doria\Baton\Compiler\CompilerAdapter;
use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Tests\TestCase;

final class CompilerAdapterTest extends TestCase
{
    public function testCaptureReturnsExactProcessResult(): void
    {
        $root = $this->temporaryDirectory('compiler capture');
        $script = $root . '/compiler.php';
        self::assertNotFalse(file_put_contents(
            $script,
            "<?php fwrite(STDOUT, \"identity\\n\"); fwrite(STDERR, \"detail\\n\"); exit(7);\n",
        ));

        $result = (new CompilerAdapter(PHP_BINARY))->capture([$script]);

        self::assertSame(7, $result->exitCode);
        self::assertSame("identity\n", $result->stdout);
        self::assertSame("detail\n", $result->stderr);
    }

    public function testCaptureTerminatesAnUnresponsiveCompiler(): void
    {
        $root = $this->temporaryDirectory('compiler timeout');
        $script = $root . '/compiler.php';
        self::assertNotFalse(file_put_contents($script, "<?php usleep(500000);\n"));

        try {
            (new CompilerAdapter(PHP_BINARY))->capture([$script], timeoutSeconds: 0.05);
            self::fail('the unresponsive compiler should time out');
        } catch (BatonError $error) {
            self::assertSame('B0203', $error->diagnosticCode);
            self::assertSame('Doria Compiler Did Not Respond', $error->heading);
            self::assertStringContainsString('Use a compiled doriac artifact', $error->body);
        }
    }
}
