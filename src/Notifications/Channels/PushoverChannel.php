<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

use NwsCad\Logger;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\SendResult;

final class PushoverChannel implements NotificationChannel
{
    private const BACKOFF_MS = [1000, 3000, 9000];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $user,
        /** @var array<string,mixed> */
        private readonly array $config,
        private readonly HttpPost $http = new HttpPost(),
        /** @var callable(int):void */
        private $sleeper = null,
    ) {
        if ($this->sleeper === null) {
            $this->sleeper = static fn (int $ms) => usleep($ms * 1000);
        }
    }

    public static function type(): string
    {
        return 'pushover';
    }

    public function send(IncidentDto $incident, NotificationContext $context): array
    {
        $logger = Logger::getInstance();
        $start = microtime(true);
        $fields = [
            'token' => $this->token,
            'user' => $this->user,
            'title' => sprintf('Call: %s %s', $incident->callNumber, $incident->callType ?? ''),
            'message' => implode("\n", [
                'Loc: ' . ($incident->fullAddress ?? ''),
                'Inc: ' . ($incident->callType ?? ''),
                'Nature: ' . ($incident->natureOfCall ?? ''),
                'Unit: ' . $incident->units,
                'Time: ' . $incident->createDateTime,
                'Narr: ' . ($incident->narrative ?? ''),
            ]),
            'sound' => $this->config['sound'] ?? 'bike',
            'html' => '1',
        ];
        if ($incident->mapUrl() !== null) {
            $fields['url'] = $incident->mapUrl();
            $fields['url_title'] = 'Driving Directions';
        }

        $lastStatus = null;
        $lastError = '';

        foreach (self::BACKOFF_MS as $i => $delayMs) {
            $resp = $this->http->post($this->baseUrl, $fields, 30);
            $lastStatus = $resp['status'];
            $body = $resp['body'];

            if ($resp['status'] >= 200 && $resp['status'] < 300) {
                $payload = json_decode($body, true);
                if (is_array($payload) && ($payload['status'] ?? null) === 1) {
                    $duration = (int) ((microtime(true) - $start) * 1000);
                    return [SendResult::ok($resp['status'], $duration)];
                }
                $lastError = is_array($payload) ? json_encode($payload) : 'invalid JSON';
            } else {
                $lastError = $body;
            }

            if ($resp['status'] >= 400 && $resp['status'] < 500) {
                $logger->warning('pushover permanent failure', [
                    'http_status' => $resp['status'],
                    'attempt' => $i + 1,
                    'body' => substr($lastError, 0, 500),
                ]);
                break;
            }

            if ($i < count(self::BACKOFF_MS) - 1) {
                ($this->sleeper)($delayMs);
            }
        }

        $duration = (int) ((microtime(true) - $start) * 1000);
        $logger->error('pushover retries exhausted', [
            'http_status' => $lastStatus,
            'error' => substr($lastError, 0, 500),
        ]);
        return [SendResult::fail($lastStatus, $duration, $lastError ?: 'retries exhausted')];
    }
}
