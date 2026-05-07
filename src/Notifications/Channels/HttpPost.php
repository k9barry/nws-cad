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
}
