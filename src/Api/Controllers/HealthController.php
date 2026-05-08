<?php

declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Response;
use NwsCad\Database;
use PDO;
use Throwable;

final class HealthController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function index(): void
    {
        try {
            $row = $this->db->query('SELECT 1 AS ok')->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int) ($row['ok'] ?? 0) !== 1) {
                Response::error('Database unreachable', 503, ['db' => 'unreachable']);
                return;
            }
            Response::success([
                'status'    => 'ok',
                'db'        => 'ok',
                'timestamp' => date('c'),
            ]);
        } catch (Throwable $e) {
            Response::error('Database unreachable', 503, ['db' => 'unreachable']);
        }
    }
}
