<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

use NwsCad\Logger;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\SendResult;
use NwsCad\Notifications\TopicSanitizer;

final class NtfyChannel implements NotificationChannel
{
    /** @var int[] Backoff between retries, in milliseconds. */
    private const BACKOFF_MS = [1000, 3000, 9000];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authToken,
        /** @var array<string,mixed> */
        private readonly array $config,
        private readonly HttpPut $http = new HttpPut(),
        /** @var callable(int):void */
        private $sleeper = null,
    ) {
        if ($this->sleeper === null) {
            $this->sleeper = static fn (int $ms) => usleep($ms * 1000);
        }
    }

    public static function type(): string
    {
        return 'ntfy';
    }

    public function send(IncidentDto $incident, NotificationContext $context): array
    {
        $logger = Logger::getInstance();
        $results = [];
        $tags = $this->buildTags($incident);
        $priority = $this->buildPriority($incident);
        $messageBody = $this->buildBody($incident);
        $title = $this->buildTitle($incident);

        foreach ($context->topicsToNotify as $rawTopic) {
            $sanitized = TopicSanitizer::clean($rawTopic);
            if ($sanitized === null) {
                $logger->info('Skipping ntfy topic (empty after sanitize)', ['raw' => $rawTopic]);
                continue;
            }

            $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($sanitized);
            $headers = [
                'Content-Type' => 'text/plain',
                'Authorization' => $this->authToken,
                'Title' => $title,
                'Tags' => $tags,
                'Priority' => (string) $priority,
            ];
            if ($incident->mapUrl() !== null) {
                $headers['Attach'] = $incident->mapUrl();
            }

            $results[] = $this->sendWithRetry($url, $headers, $messageBody, $sanitized);
        }

        return $results;
    }

    private function sendWithRetry(string $url, array $headers, string $body, string $topic): SendResult
    {
        $logger = Logger::getInstance();
        $start = microtime(true);
        $attempt = 0;
        $lastStatus = null;
        $lastError = '';

        foreach (self::BACKOFF_MS as $i => $delayMs) {
            $attempt = $i + 1;
            $resp = $this->http->put($url, $headers, $body, 10);
            $lastStatus = $resp['status'];

            if ($resp['status'] >= 200 && $resp['status'] < 300) {
                $duration = (int) ((microtime(true) - $start) * 1000);
                return SendResult::ok($resp['status'], $duration, $topic);
            }

            $lastError = (string) $resp['body'];

            if ($resp['status'] >= 400 && $resp['status'] < 500) {
                $logger->warning('ntfy permanent failure', [
                    'topic' => $topic, 'http_status' => $resp['status'],
                    'attempt' => $attempt, 'body' => substr($lastError, 0, 500),
                ]);
                $duration = (int) ((microtime(true) - $start) * 1000);
                return SendResult::fail($resp['status'], $duration, $lastError, $topic);
            }

            // Transient — back off and retry, unless this was the last attempt.
            if ($i < count(self::BACKOFF_MS) - 1) {
                ($this->sleeper)($delayMs);
            }
        }

        $duration = (int) ((microtime(true) - $start) * 1000);
        $logger->error('ntfy retries exhausted', [
            'topic' => $topic, 'http_status' => $lastStatus,
            'attempt' => $attempt, 'error' => substr($lastError, 0, 500),
        ]);
        return SendResult::fail($lastStatus, $duration, $lastError ?: 'retries exhausted', $topic);
    }

    private function buildTags(IncidentDto $i): string
    {
        $map = $this->config['agency_tag_map'] ?? ['Fire' => 'fire_engine', 'Police' => 'police_car'];
        return $map[$i->agencyType ?? ''] ?? 'fire_engine,police_car';
    }

    private function buildPriority(IncidentDto $i): int
    {
        $alarmMap = $this->config['alarm_priority_map'] ?? null;
        if (is_array($alarmMap) && isset($alarmMap[(string) $i->alarmLevel])) {
            $p = (int) $alarmMap[(string) $i->alarmLevel];
        } else {
            $p = $i->alarmLevel + 2;
        }
        return max(1, min(5, $p));
    }

    private function buildTitle(IncidentDto $i): string
    {
        return sprintf('Call: %s %s', $i->callNumber, $i->callType ?? '');
    }

    private function buildBody(IncidentDto $i): string
    {
        return implode("\n", [
            'C-Name: ' . ($i->commonName ?? ''),
            'Loc: ' . ($i->fullAddress ?? ''),
            'Inc: ' . ($i->callType ?? ''),
            'Nature: ' . ($i->natureOfCall ?? ''),
            'Cross Rd: ' . ($i->nearestCrossStreets ?? ''),
            'Beat: ' . ($i->policeBeat ?? ''),
            'Quad: ' . ($i->fireQuadrant ?? ''),
            'Unit: ' . $i->units,
            'Time: ' . $i->createDateTime,
            'Narr: ' . ($i->narrative ?? ''),
        ]);
    }
}
