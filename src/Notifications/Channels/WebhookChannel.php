<?php

declare(strict_types=1);

namespace NwsCad\Notifications\Channels;

use InvalidArgumentException;
use NwsCad\Config;
use NwsCad\Logger;
use NwsCad\Notifications\ChannelDescriptor;
use NwsCad\Notifications\IncidentDto;
use NwsCad\Notifications\NotificationChannel;
use NwsCad\Notifications\NotificationContext;
use NwsCad\Notifications\SendResult;

final class WebhookChannel implements NotificationChannel
{
    private const DEFAULT_TIMEOUT_SEC = 10;
    private const RETRY_DELAYS_SEC    = [1, 3, 9];

    /** @var array<mixed> */
    private readonly array $template;
    private readonly ?string $authHeader;
    private readonly ?string $authToken;
    private readonly int $timeoutSec;
    private readonly HttpPost $http;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        public readonly string $baseUrl,
        array $config,
        ?string $authToken = null,
        ?HttpPost $http = null,
    ) {
        $template = $config['template'] ?? null;
        if (! is_array($template) || $template === []) {
            throw new InvalidArgumentException('webhook: template required');
        }
        $this->template = $template;

        $this->authHeader = isset($config['auth_header']) ? (string) $config['auth_header'] : null;

        if ($authToken !== null && preg_match('/[\r\n]/', $authToken) === 1) {
            throw new InvalidArgumentException('webhook: auth token contains CR/LF');
        }
        $this->authToken = $authToken;

        $this->timeoutSec = isset($config['timeout_sec']) ? max(1, (int) $config['timeout_sec']) : self::DEFAULT_TIMEOUT_SEC;
        $this->http       = $http ?? new HttpPost();
    }

    public static function descriptor(): ChannelDescriptor
    {
        return new ChannelDescriptor(
            type:          'webhook',
            label:         'Generic webhook',
            baseUrlEnv:    'WEBHOOK_BASE_URL',
            requiredEnvs:  [],   // varies by config; template-driven
            defaultConfig: [
                'template'    => ['text' => '{intent}: {call_type} at {full_address}', 'topics' => '${topics}'],
                'timeout_sec' => self::DEFAULT_TIMEOUT_SEC,
            ],
            factory: static function (array $row, Config $cfg): NotificationChannel {
                $raw    = $row['config_json'] ?? '';
                $config = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
                $token  = null;
                if (! empty($config['auth_token_env'])) {
                    $token = $cfg->secret((string) $config['auth_token_env']);
                }
                return new self(
                    baseUrl: (string) $row['base_url'],
                    config:  $config,
                    authToken: $token,
                );
            },
        );
    }

    /**
     * @return SendResult[]
     */
    public function send(IncidentDto $incident, NotificationContext $context): array
    {
        $body = self::buildPayload($this->template, $incident, $context);

        // buildPayload's output is canonical JSON; decode for postJson.
        $payload = json_decode($body, true);
        if ($payload === null) {
            Logger::getInstance()->error('Webhook channel: substituted template is not valid JSON', [
                'baseUrl' => $this->baseUrl,
            ]);
            return [SendResult::fail(
                httpStatus: 0,
                durationMs: 0,
                error: 'webhook: substituted template is not valid JSON',
            )];
        }

        $headers = [];
        if ($this->authHeader !== null && $this->authToken !== null) {
            $headers[$this->authHeader] = $this->authToken;
        }

        $lastStatus = 0;
        $lastError  = null;
        $startedAt  = microtime(true);

        foreach ([0, ...self::RETRY_DELAYS_SEC] as $delay) {
            if ($delay > 0) {
                sleep($delay);
            }

            $attemptStart = microtime(true);
            $result       = $this->http->postJson($this->baseUrl, $payload, $this->timeoutSec, $headers);
            $lastStatus   = $result['status'];
            $lastError    = $result['body'];
            $durationMs   = (int) ((microtime(true) - $attemptStart) * 1000);

            if ($lastStatus >= 200 && $lastStatus < 300) {
                return [SendResult::ok(httpStatus: $lastStatus, durationMs: $durationMs)];
            }
            if ($lastStatus >= 400 && $lastStatus < 500) {
                Logger::getInstance()->warning('Webhook channel: permanent failure', [
                    'baseUrl'    => $this->baseUrl,
                    'httpStatus' => $lastStatus,
                ]);
                return [SendResult::fail(
                    httpStatus: $lastStatus,
                    durationMs: (int) ((microtime(true) - $startedAt) * 1000),
                    error:      $lastError ?? 'unknown',
                )];
            }
            // 5xx and 0 (network) → retry
        }

        Logger::getInstance()->error('Webhook channel: send failed after retries', [
            'baseUrl'    => $this->baseUrl,
            'httpStatus' => $lastStatus,
            'attempts'   => count(self::RETRY_DELAYS_SEC) + 1,
        ]);

        return [SendResult::fail(
            httpStatus: $lastStatus,
            durationMs: (int) ((microtime(true) - $startedAt) * 1000),
            error: $lastError ?? 'unknown',
        )];
    }

    /**
     * Two-pass template substitution. Public + static for unit-testing without
     * needing to construct a full WebhookChannel.
     *
     * @param array<mixed> $template
     */
    public static function buildPayload(array $template, IncidentDto $dto, NotificationContext $context): string
    {
        $strVars = [
            '{intent}'          => $context->intent->value,
            '{call_id}'         => (string) $dto->callId,
            '{call_number}'     => $dto->callNumber,
            '{call_type}'       => (string) ($dto->callType ?? ''),
            '{full_address}'    => (string) ($dto->fullAddress ?? ''),
            '{create_datetime}' => $dto->createDateTime,
            '{alarm_level}'     => (string) $dto->alarmLevel,
            '{narrative}'       => (string) ($dto->narrative ?? ''),
            '{agency_type}'     => (string) ($dto->agencyType ?? ''),
            '{jurisdiction}'    => (string) ($dto->jurisdiction ?? ''),
            '{units}'           => $dto->units,
            '{topics}'          => implode(', ', $context->topicsToNotify),
        ];

        $walked = self::walk($template, $strVars);
        $json   = json_encode($walked, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('webhook: failed to encode template');
        }

        $rawVars = [
            '"${topics}"'       => json_encode($context->topicsToNotify, JSON_UNESCAPED_SLASHES),
            '"${units}"'        => json_encode(self::splitPipe($dto->units), JSON_UNESCAPED_SLASHES),
            '"${jurisdiction}"' => json_encode(self::splitPipe($dto->jurisdiction ?? ''), JSON_UNESCAPED_SLASHES),
        ];
        return strtr($json, $rawVars);
    }

    /** Raw-array sentinels that must not be touched by the string-var pass. */
    private const RAW_SENTINELS = ['${topics}', '${units}', '${jurisdiction}'];

    /**
     * @param array<mixed> $node
     * @param array<string,string> $vars
     * @return array<mixed>
     */
    private static function walk(array $node, array $vars): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::walk($v, $vars);
            } elseif (is_string($v) && ! in_array($v, self::RAW_SENTINELS, true)) {
                $out[$k] = strtr($v, $vars);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function splitPipe(string $value): array
    {
        if ($value === '') {
            return [];
        }
        return array_values(array_filter(
            array_map('trim', explode('|', $value)),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
