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

        // Compute the project root from the test file location. `tests/Unit/Security/CorsPolicyTest.php`
        // → project root = three levels up from this directory.
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);

        // Heredoc (NOT nowdoc — note the double-quote-style PHP <<<PHP rather than
        // <<<'PHP') so that $autoload is interpolated. PHP-language $/dollar
        // references inside must be backslash-escaped.
        $code = <<<PHP
<?php
require {$autoload};
\$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
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
        // Sanity: confirm autoload + class load actually worked. If they didn't,
        // the test would still pass (no NOT_REACHED) but for the wrong reason.
        // A successful exit() leaves $output empty (no fatal). A failed autoload
        // produces a fatal error mentioning 'autoload' or 'class'.
        if (str_contains($output, 'autoload') || str_contains($output, 'Fatal error')) {
            $this->fail('Subprocess failed before reaching CorsPolicy: ' . $output);
        }
    }
}
