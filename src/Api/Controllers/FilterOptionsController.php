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
            'agency'        => $this->fetchDistinct($db, 'agency_contexts', 'agency_type'),
            'ori'           => $this->fetchOris($db),
            'fdid'          => $this->fetchDistinct($db, 'agency_contexts', 'fdid'),
            'beat'          => $this->fetchDistinct($db, 'locations', 'police_beat'),
            'area'          => $this->fetchAreas($db),
            'city'          => $this->fetchDistinct($db, 'locations', 'city'),
            'call_type'     => $this->fetchDistinct($db, 'agency_contexts', 'call_type'),
            'incident_type' => $this->fetchDistinct($db, 'incidents', 'incident_type'),
            'unit'          => $this->fetchDistinct($db, 'units', 'unit_number'),
        };
    }

    /**
     * ORIs live in three columns on the locations table (police_ori, ems_ori,
     * fire_ori). Union-distinct across all three so the dropdown shows every
     * ORI the system has actually seen.
     *
     * @return string[]
     */
    private function fetchOris(PDO $db): array
    {
        $sql = "
            SELECT DISTINCT police_ori AS ori FROM locations WHERE police_ori IS NOT NULL AND police_ori <> ''
            UNION
            SELECT DISTINCT ems_ori    AS ori FROM locations WHERE ems_ori    IS NOT NULL AND ems_ori    <> ''
            UNION
            SELECT DISTINCT fire_ori   AS ori FROM locations WHERE fire_ori   IS NOT NULL AND fire_ori   <> ''
            ORDER BY ori
            LIMIT " . self::DERIVED_LIMIT;
        return array_map(static fn ($row) => (string) $row['ori'], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Areas span fire_quadrant + ems_district. Union-distinct.
     *
     * @return string[]
     */
    private function fetchAreas(PDO $db): array
    {
        $sql = "
            SELECT DISTINCT fire_quadrant AS area FROM locations WHERE fire_quadrant IS NOT NULL AND fire_quadrant <> ''
            UNION
            SELECT DISTINCT ems_district  AS area FROM locations WHERE ems_district  IS NOT NULL AND ems_district  <> ''
            ORDER BY area
            LIMIT " . self::DERIVED_LIMIT;
        return array_map(static fn ($row) => (string) $row['area'], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return string[] */
    private function fetchDistinct(PDO $db, string $table, string $column): array
    {
        // Table and column come from the closed SUPPORTED_FIELDS dispatch — never user input.
        $sql = "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} LIMIT " . self::DERIVED_LIMIT;
        return array_map(static fn ($row) => (string) $row[$column], $db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
