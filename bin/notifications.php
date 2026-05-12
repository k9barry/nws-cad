#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NwsCad\Database;

$args = $_SERVER['argv'] ?? [];
array_shift($args);
$cmd = $args[0] ?? 'help';

function help(): never
{
    echo <<<TXT
Usage: php bin/notifications.php <command>

Commands:
  list                                 Show all channels with status.
  enable <type> [--base-url=URL]       Enable (or create) a channel of the given type ('ntfy'|'pushover').
  disable <type>                       Disable all channels of the given type.
  test <type>                          Send a synthetic notification through the first enabled channel of <type>.

Secrets are read from environment (.env). Required env vars per type:
  ntfy:     NTFY_AUTH_TOKEN
  pushover: PUSHOVER_TOKEN, PUSHOVER_USER

TXT;
    exit(0);
}

switch ($cmd) {
    case 'list':
        $db = Database::getConnection();
        $rows = $db->query("SELECT id, name, type, enabled, base_url, last_error_at, last_error_message
                            FROM notification_channels ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            echo "(no channels configured)\n";
            exit(0);
        }
        foreach ($rows as $r) {
            $flag = $r['enabled'] ? 'enabled ' : 'disabled';
            $err = $r['last_error_at'] ? "  ⚠ {$r['last_error_at']}: {$r['last_error_message']}" : '';
            echo "  [{$flag}] #{$r['id']} {$r['name']} ({$r['type']}) {$r['base_url']}{$err}\n";
        }
        break;

    case 'enable':
        $type = $args[1] ?? null;
        if (! in_array($type, ['ntfy', 'pushover'], true)) {
            fwrite(STDERR, "enable requires <type> = ntfy | pushover\n");
            exit(1);
        }
        $baseUrl = '';
        foreach ($args as $a) {
            if (str_starts_with($a, '--base-url=')) {
                $baseUrl = substr($a, 11);
            }
        }
        if ($baseUrl === '') {
            $envKey = $type === 'ntfy' ? 'NTFY_BASE_URL' : 'PUSHOVER_BASE_URL';
            $baseUrl = $_ENV[$envKey] ?? getenv($envKey) ?: '';
            if ($baseUrl === '') {
                fwrite(STDERR, "Provide --base-url=URL or set {$envKey} in environment.\n");
                exit(1);
            }
        }

        $check = \NwsCad\Security\UrlValidator::validateChannelBaseUrl(
            $baseUrl,
            \NwsCad\Config::getInstance()
        );
        if (! $check['ok']) {
            fwrite(STDERR, "Invalid base_url: {$check['reason']}\n");
            exit(1);
        }

        $defaultConfig = $type === 'ntfy'
            ? '{"auth_token_env":"NTFY_AUTH_TOKEN","alarm_priority_map":{"1":3,"2":4,"3":5}}'
            : '{"token_env":"PUSHOVER_TOKEN","user_env":"PUSHOVER_USER"}';

        $db = Database::getConnection();
        $name = "{$type}_primary";
        $stmt = $db->prepare("SELECT id FROM notification_channels WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetchColumn()) {
            $db->prepare("UPDATE notification_channels
                          SET enabled=1, base_url=?, updated_at=CURRENT_TIMESTAMP
                          WHERE name=?")->execute([$baseUrl, $name]);
            echo "Re-enabled channel {$name}\n";
        } else {
            $db->prepare("INSERT INTO notification_channels (name, type, enabled, base_url, config_json)
                          VALUES (?, ?, 1, ?, ?)")->execute([$name, $type, $baseUrl, $defaultConfig]);
            echo "Created and enabled channel {$name}\n";
        }
        break;

    case 'disable':
        $type = $args[1] ?? null;
        if (! in_array($type, ['ntfy', 'pushover'], true)) {
            fwrite(STDERR, "disable requires <type>\n");
            exit(1);
        }
        $db = Database::getConnection();
        $db->prepare("UPDATE notification_channels
                      SET enabled=0, updated_at=CURRENT_TIMESTAMP WHERE type=?")->execute([$type]);
        echo "Disabled all '{$type}' channels.\n";
        break;

    case 'test':
        fwrite(STDERR, "test command requires PR #4 (event wiring) to send a synthetic event.\n");
        exit(1);

    case 'help':
    case '-h':
    case '--help':
    default:
        help();
}
