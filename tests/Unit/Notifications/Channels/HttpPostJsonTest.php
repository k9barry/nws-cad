<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit\Notifications\Channels;

use NwsCad\Notifications\Channels\HttpPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpPost::class)]
final class HttpPostJsonTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('socket_create_listen')) {
            $this->markTestSkipped('ext-sockets not available — needed for ephemeral local server');
        }
    }

    public function testPostJsonHits200OnLocalServer(): void
    {
        $server = $this->startEchoServer();
        try {
            $http   = new HttpPost();
            $result = $http->postJson(
                url: "http://127.0.0.1:{$server['port']}/",
                payload: ['intent' => 'Created', 'topics' => ['A', 'B']],
                timeoutSec: 5,
                headers: ['Authorization' => 'Bearer test-token'],
            );

            $this->assertSame(200, $result['status']);
            $this->assertStringContainsString('Created', $result['body']);
            $this->assertStringContainsString('Bearer test-token', $result['body']);
            $this->assertStringContainsString('application/json', $result['body']);
        } finally {
            $this->stopEchoServer($server);
        }
    }

    /**
     * @return array{proc:resource,port:int,scriptPath:string}
     */
    private function startEchoServer(): array
    {
        $port = $this->findFreePort();
        $script = tempnam(sys_get_temp_dir(), 'echo') . '.php';
        file_put_contents($script, <<<'PHP'
<?php
$body = file_get_contents('php://input') ?: '';
$ct   = $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
header('Content-Type: text/plain');
echo "body=$body|ct=$ct|auth=$auth";
PHP);
        $cmd = sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($script));
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($proc)) {
            $this->fail('failed to start local echo server');
        }
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock !== false) { fclose($sock); break; }
            usleep(50_000);
        }
        return ['proc' => $proc, 'port' => $port, 'scriptPath' => $script];
    }

    private function stopEchoServer(array $server): void
    {
        if (isset($server['proc']) && is_resource($server['proc'])) {
            proc_terminate($server['proc']);
            proc_close($server['proc']);
        }
        if (isset($server['scriptPath']) && file_exists($server['scriptPath'])) {
            @unlink($server['scriptPath']);
        }
    }

    private function findFreePort(): int
    {
        $sock = socket_create_listen(0);
        if ($sock === false) { $this->fail('socket_create_listen failed'); }
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        return (int) $port;
    }
}
