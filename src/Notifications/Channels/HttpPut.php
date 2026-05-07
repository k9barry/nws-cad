<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

class HttpPut
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function put(string $url, array $headers, string $body, int $timeoutSec): array
    {
        $ch = curl_init();
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
