<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

use DateTimeImmutable;
use NwsCad\Config;

final class FilterSqlBuilder
{
    /**
     * Stale-open guardrail cutoff. A call older than this is reclassified as
     * `closed` even if its raw row has `close_datetime IS NULL`. Returned as
     * an ISO `Y-m-d H:i:s` string so it binds cleanly under both MySQL and
     * PostgreSQL without per-driver INTERVAL syntax.
     *
     * Shared with StatsController so the SQL CASE expressions and WHERE
     * clauses there use the same cutoff as the status filter here.
     */
    public static function staleCutoff(): string
    {
        $hours = (int) Config::getInstance()->get('calls.stale_open_hours', 72);
        if ($hours <= 0) {
            $hours = 72;
        }
        return (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');
    }

    /**
     * Mirror of the SQL stale-open predicate for one already-fetched call row.
     * True iff the row WOULD be classified `open` or `reopened` (canceled=0
     * and either no close_datetime or reopened_flag=1) but its create_datetime
     * is older than the guardrail cutoff. Controllers surface this as the
     * `is_stale` field so client-side badges agree with the server filter.
     */
    public static function isStaleRow(array $row, ?string $staleCutoff = null): bool
    {
        $cutoff = $staleCutoff ?? self::staleCutoff();
        if ((int) ($row['canceled_flag'] ?? 0) === 1) {
            return false;
        }
        $create = (string) ($row['create_datetime'] ?? '');
        if ($create === '' || $create >= $cutoff) {
            return false;
        }
        $hasClose = ($row['close_datetime'] ?? null) !== null;
        $reopened = (int) ($row['reopened_flag'] ?? 0) === 1;
        return !$hasClose || $reopened;
    }


    public function build(FilterCriteria $f, FilterContext $ctx): SqlFragment
    {
        $clauses = [];
        $params  = [];
        $joins   = [];

        $needsAgencyContexts = $f->callType || $f->fdid || $f->agency;
        $needsLocations      = $f->ori || $f->beat || $f->area || $f->city || $f->location !== null;
        $needsIncidents      = $f->incidentType !== [];
        $needsUnits          = $f->unit !== [];

        if ($ctx->unitsBase) {
            // Base table is `units`; joins reach back through units.call_id
            if ($needsAgencyContexts && !$ctx->isJoined('agency_contexts')) {
                $joins[] = 'LEFT JOIN agency_contexts ON agency_contexts.call_id = units.call_id';
            }
            if ($needsLocations && !$ctx->isJoined('locations')) {
                $joins[] = 'LEFT JOIN locations ON locations.call_id = units.call_id';
            }
            if ($needsIncidents && !$ctx->isJoined('incidents')) {
                $joins[] = 'LEFT JOIN incidents ON incidents.call_id = units.call_id';
            }
            // units is already the base — no self-join needed
            // Join calls when date filters or call_id filters reference calls columns
            $needsCalls = $f->dateRange !== null || $f->callId !== [] || $f->status !== [] || $f->natureOfCall !== null || $f->search !== null;
            if ($needsCalls && !$ctx->isJoined('calls')) {
                $joins[] = 'LEFT JOIN calls ON calls.id = units.call_id';
            }
        } else {
            // Base table is `calls`; joins go forward
            if ($needsAgencyContexts && !$ctx->isJoined('agency_contexts')) {
                $joins[] = 'LEFT JOIN agency_contexts ON agency_contexts.call_id = calls.id';
            }
            if ($needsLocations && !$ctx->isJoined('locations')) {
                $joins[] = 'LEFT JOIN locations ON locations.call_id = calls.id';
            }
            if ($needsIncidents && !$ctx->isJoined('incidents')) {
                $joins[] = 'LEFT JOIN incidents ON incidents.call_id = calls.id';
            }
            if ($needsUnits && !$ctx->isJoined('units')) {
                $joins[] = 'LEFT JOIN units ON units.call_id = calls.id';
            }
        }

        // Date range
        if ($f->dateRange !== null) {
            $col = $f->dateField === 'closed' ? 'calls.close_datetime' : 'calls.create_datetime';
            $clauses[] = "{$col} >= :date_from";
            $clauses[] = "{$col} <= :date_to";
            $params['date_from'] = $f->dateRange->from->format('Y-m-d H:i:s');
            $params['date_to']   = $f->dateRange->to->format('Y-m-d H:i:s');
        }

        // Single-column IN() filters
        $simpleIn = [
            'call_type'     => ['column' => 'agency_contexts.call_type',  'values' => $f->callType,     'prefix' => 'call_type'],
            'incident_type' => ['column' => 'incidents.incident_type',    'values' => $f->incidentType, 'prefix' => 'incident_type'],
            'fdid'          => ['column' => 'agency_contexts.fdid',       'values' => $f->fdid,         'prefix' => 'fdid'],
            'beat'          => ['column' => 'locations.police_beat',      'values' => $f->beat,         'prefix' => 'beat'],
            'city'          => ['column' => 'locations.city',             'values' => $f->city,         'prefix' => 'city'],
            'call_id'       => ['column' => 'calls.call_number',          'values' => $f->callId,       'prefix' => 'call_id'],
            'unit'          => ['column' => 'units.unit_number',          'values' => $f->unit,         'prefix' => 'unit'],
            'agency'        => ['column' => 'agency_contexts.agency_type','values' => $f->agency,       'prefix' => 'agency'],
        ];
        foreach ($simpleIn as $cfg) {
            if ($cfg['values'] === []) continue;
            $placeholders = [];
            foreach ($cfg['values'] as $i => $v) {
                $name = $cfg['prefix'] . '_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $v;
            }
            $clauses[] = $cfg['column'] . ' IN (' . implode(', ', $placeholders) . ')';
        }

        // ORI: matches across police_ori OR ems_ori OR fire_ori.
        // PDO named parameters cannot appear more than once per query, so we
        // generate separate parameter names for each column reference.
        if ($f->ori !== []) {
            $policePh = [];
            $emsPh    = [];
            $firePh   = [];
            foreach ($f->ori as $i => $v) {
                $polN = 'ori_police_' . $i;
                $emsN = 'ori_ems_'    . $i;
                $firN = 'ori_fire_'   . $i;
                $policePh[] = ':' . $polN;
                $emsPh[]    = ':' . $emsN;
                $firePh[]   = ':' . $firN;
                $params[$polN] = $v;
                $params[$emsN] = $v;
                $params[$firN] = $v;
            }
            $clauses[] = '(locations.police_ori IN (' . implode(', ', $policePh) . ')'
                . ' OR locations.ems_ori IN (' . implode(', ', $emsPh) . ')'
                . ' OR locations.fire_ori IN (' . implode(', ', $firePh) . '))';
        }

        // Area: matches fire_quadrant OR ems_district.
        // Same PDO duplicate-parameter constraint as ORI above.
        if ($f->area !== []) {
            $firePh = [];
            $emsPh  = [];
            foreach ($f->area as $i => $v) {
                $firN = 'area_fire_' . $i;
                $emsN = 'area_ems_'  . $i;
                $firePh[] = ':' . $firN;
                $emsPh[]  = ':' . $emsN;
                $params[$firN] = $v;
                $params[$emsN] = $v;
            }
            $clauses[] = '(locations.fire_quadrant IN (' . implode(', ', $firePh) . ')'
                . ' OR locations.ems_district IN (' . implode(', ', $emsPh) . '))';
        }

        // LIKE filters (location, nature_of_call). Escape % and _ so users typing them are taken literally.
        // location matches two columns — use distinct parameter names to avoid PDO duplicate-parameter error.
        if ($f->location !== null) {
            $escaped = '%' . self::escapeLike($f->location) . '%';
            $params['location_addr']   = $escaped;
            $params['location_cname']  = $escaped;
            $clauses[] = '(locations.full_address LIKE :location_addr OR locations.common_name LIKE :location_cname)';
        }
        if ($f->natureOfCall !== null) {
            $params['nature_of_call'] = '%' . self::escapeLike($f->natureOfCall) . '%';
            $clauses[] = 'calls.nature_of_call LIKE :nature_of_call';
        }

        // Free-text q across narratives + caller + incident #. Joins narratives if used.
        // Three columns → three distinct parameter names (PDO duplicate-parameter constraint).
        if ($f->search !== null && $f->search !== '') {
            if (!$ctx->isJoined('narratives')) {
                $joins[] = 'LEFT JOIN narratives ON narratives.call_id = calls.id';
            }
            if (!$needsIncidents && !$ctx->isJoined('incidents')) {
                $joins[] = 'LEFT JOIN incidents ON incidents.call_id = calls.id';
            }
            $escaped = '%' . self::escapeLike($f->search) . '%';
            $params['q_note']     = $escaped;
            $params['q_caller']   = $escaped;
            $params['q_incident'] = $escaped;
            $clauses[] = '(narratives.note LIKE :q_note OR calls.caller_name LIKE :q_caller OR incidents.incident_number LIKE :q_incident)';
        }

        // Status: each selected value becomes a parenthesised clause; multiple OR'd.
        // open and closed key off close_datetime (not closed_flag) because the
        // CAD source occasionally sends post-close XMLs with ClosedFlag=false
        // alongside a populated CloseDateTime; trusting the timestamp matches
        // operational reality. reopened_flag, set by the parser when a closed
        // call receives new unit activity, surfaces legitimate reopens under
        // both open and the dedicated reopened filter.
        //
        // Stale-open guardrail: a call that would otherwise be `open` or
        // `reopened` but whose create_datetime predates :stale_cutoff is
        // reclassified as `closed` here so old, never-formally-closed CAD
        // records stop polluting the open queues. See StaleCutoff::staleCutoff().
        if ($f->status !== []) {
            $statusClauses = [];
            foreach ($f->status as $s) {
                $statusClauses[] = match ($s) {
                    'open'     => '(calls.canceled_flag = 0 AND (calls.close_datetime IS NULL OR calls.reopened_flag = 1) AND calls.create_datetime >= :stale_cutoff)',
                    'closed'   => '(calls.canceled_flag = 0 AND ((calls.close_datetime IS NOT NULL AND calls.reopened_flag = 0) OR calls.create_datetime < :stale_cutoff))',
                    'reopened' => '(calls.canceled_flag = 0 AND calls.reopened_flag = 1 AND calls.create_datetime >= :stale_cutoff)',
                    'canceled' => '(calls.canceled_flag = 1)',
                };
            }
            $clauses[] = '(' . implode(' OR ', $statusClauses) . ')';

            // Bind the stale cutoff only when at least one selected status
            // arm references it. A `canceled`-only filter doesn't need it.
            if (array_intersect($f->status, ['open', 'closed', 'reopened']) !== []) {
                $params['stale_cutoff'] = self::staleCutoff();
            }
        }

        return new SqlFragment(
            whereClause: $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '',
            params: $params,
            joins: $joins,
        );
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
