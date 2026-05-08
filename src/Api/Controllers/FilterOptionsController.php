<?php
declare(strict_types=1);

namespace NwsCad\Api\Controllers;

use NwsCad\Api\Filtering\FilterOptionsCache;
use NwsCad\Api\Request;
use NwsCad\Api\Response;
use NwsCad\Database;
use PDO;

final class FilterOptionsController
{
    private const SUPPORTED_FIELDS = ['agency', 'ori', 'fdid', 'beat', 'area', 'city', 'call_type', 'incident_type', 'unit'];
    private const DERIVED_FIELDS   = ['city', 'call_type', 'incident_type', 'unit'];
    private const DERIVED_LIMIT    = 1000;

    public function index(): void
    {
        $rawFields = (string) Request::query('fields', implode(',', self::SUPPORTED_FIELDS));
        $fields = array_values(array_filter(array_map('trim', explode(',', $rawFields))));

        foreach ($fields as $field) {
            if (!in_array($field, self::SUPPORTED_FIELDS, true)) {
                Response::error("Unsupported field: {$field}", 400);
                return;
            }
        }

        $db = Database::getConnection();
        $data = [];
        foreach ($fields as $field) {
            $cached = FilterOptionsCache::get($field);
            if ($cached !== null) {
                $data[$field] = $cached;
                continue;
            }
            $data[$field] = $this->loadField($db, $field);
            FilterOptionsCache::put($field, $data[$field]);
        }

        header('Cache-Control: max-age=30, stale-while-revalidate=300');
        Response::success($data);
    }

    /** @return array<int, mixed> */
    private function loadField(PDO $db, string $field): array
    {
        return match ($field) {
            'agency'        => $this->fetchAgencies($db),
            'ori'           => $this->fetchOris($db),
            'fdid'          => $this->fetchFdids($db),
            'beat'          => $this->fetchBeats($db),
            'area'          => $this->fetchAreas($db),
            'city'          => $this->fetchDistinct($db, 'locations', 'city'),
            'call_type'     => $this->fetchDistinct($db, 'agency_contexts', 'call_type'),
            'incident_type' => $this->fetchDistinct($db, 'incidents', 'incident_type'),
            'unit'          => $this->fetchDistinct($db, 'units', 'unit_number'),
        };
    }

    private function fetchAgencies(PDO $db): array
    {
        $stmt = $db->query('SELECT code AS value, label, kind, ori, fdid FROM ref_agencies WHERE active = 1 ORDER BY sort_order, label');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchOris(PDO $db): array
    {
        $stmt = $db->query('SELECT ori AS value, label, kind FROM ref_oris ORDER BY ori');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchFdids(PDO $db): array
    {
        $stmt = $db->query('SELECT fdid AS value, label FROM ref_fdids ORDER BY fdid');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchBeats(PDO $db): array
    {
        $stmt = $db->query('SELECT code AS value, label, kind, jurisdiction FROM ref_beats WHERE active = 1 ORDER BY code');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchAreas(PDO $db): array
    {
        $stmt = $db->query('SELECT code AS value, label, kind FROM ref_areas WHERE active = 1 ORDER BY code');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return string[] */
    private function fetchDistinct(PDO $db, string $table, string $column): array
    {
        // Table and column come from the closed SUPPORTED_FIELDS list — never user input.
        $sql = "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} LIMIT " . self::DERIVED_LIMIT;
        return array_map(static fn ($row) => (string) $row[$column], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
