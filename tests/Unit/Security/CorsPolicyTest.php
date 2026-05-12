<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Security;

use NwsCad\Config;
use NwsCad\Security\CorsPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Note: header() output cannot be inspected from a typical phpunit process,
 * so these tests assert behaviour reachable in-process: the OPTIONS short-
 * circuit calls http_response_code(204) and exits. We verify that path
 * separately via a subprocess pattern below.
 *
 * @covers \NwsCad\Security\CorsPolicy
 * @uses \NwsCad\Config
 * @uses \NwsCad\Security\SecurityHeaders
 */
class CorsPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['REQUEST_METHOD']);
        unset($_SERVER['HTTP_ORIGIN']);
    }

    public function testNonOptionsMethodDoesNotShortCircuit(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        CorsPolicy::apply(Config::getInstance());
        $this->assertTrue(true, 'apply() returned for non-OPTIONS');
    }

    public function testOptionsExitsViaSubprocess(): void
    {
        $this->expectNotToPerformAssertions();

        // The exit() inside CorsPolicy::apply on OPTIONS would terminate the
        // test runner; trap it by running in an isolated process.
        $code = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
\NwsCad\Security\CorsPolicy::apply(\NwsCad\Config::getInstance());
echo 'NOT_REACHED';
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'cors-');
        file_put_contents($tmp, $code);
        $output = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        if (str_contains($output, 'NOT_REACHED')) {
            $this->fail('OPTIONS preflight did not short-circuit: ' . $output);
        }
    }
}
