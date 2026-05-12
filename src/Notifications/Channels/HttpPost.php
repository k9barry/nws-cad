<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

class HttpPost
{
    /**
     * @param array<string,string|int> $fields
     * @return array{status:int, body:string}
     */
    public function post(string $url, array $fields, int $timeoutSec): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return ['status' => 0, 'body' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $body === false ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => $err];
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * POST a JSON-encoded body and return the status + body.
     *
     * @param array<mixed>          $payload Encoded with JSON_THROW_ON_ERROR.
     * @param array<string,string>  $headers Additional headers (Content-Type is added automatically; user-supplied Content-Type overrides).
     *
     * @return array{status:int, body:string}
     */
    public function postJson(string $url, array $payload, int $timeoutSec, array $headers = []): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return ['status' => 0, 'body' => 'curl_init failed'];
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $k => $v) {
            // Allow user-supplied Content-Type to override the default.
            if (strcasecmp($k, 'Content-Type') === 0) {
                $headerLines[0] = "Content-Type: {$v}";
                continue;
            }
            $headerLines[] = "{$k}: {$v}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FAILONERROR    => false,
        ]);

        $responseBody = curl_exec($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error        = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return ['status' => $status, 'body' => "curl error: {$error}"];
        }

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
