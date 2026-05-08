<?php
declare(strict_types=1);

namespace NwsCad\Api\Filtering;

final class FilterSqlBuilder
{
    public function build(FilterCriteria $f, FilterContext $ctx): SqlFragment
    {
        $clauses = [];
        $params  = [];
        $joins   = [];

        $needsAgencyContexts = $f->callType || $f->fdid || $f->agency;
        $needsLocations      = $f->ori || $f->beat || $f->area || $f->city || $f->location !== null;
        $needsIncidents      = $f->incidentType !== [];
        $needsUnits          = $f->unit !== [];

        if ($needsAgencyContexts && !$ctx->isJoined('agency_contexts')) {
            $joins[] = 'LEFT JOIN agency_contexts ac ON ac.call_id = calls.id';
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

        // ORI: matches across police_ori OR ems_ori OR fire_ori
        if ($f->ori !== []) {
            $placeholders = [];
            foreach ($f->ori as $i => $v) {
                $name = 'ori_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $v;
            }
            $list = implode(', ', $placeholders);
            $clauses[] = "(locations.police_ori IN ({$list}) OR locations.ems_ori IN ({$list}) OR locations.fire_ori IN ({$list}))";
        }

        // Area: matches fire_quadrant OR ems_district
        if ($f->area !== []) {
            $placeholders = [];
            foreach ($f->area as $i => $v) {
                $name = 'area_' . $i;
                $placeholders[] = ':' . $name;
                $params[$name] = $v;
            }
            $list = implode(', ', $placeholders);
            $clauses[] = "(locations.fire_quadrant IN ({$list}) OR locations.ems_district IN ({$list}))";
        }

        // LIKE filters (location, nature_of_call). Escape % and _ so users typing them are taken literally.
        if ($f->location !== null) {
            $params['location'] = '%' . self::escapeLike($f->location) . '%';
            $clauses[] = '(locations.full_address LIKE :location OR locations.common_name LIKE :location)';
        }
        if ($f->natureOfCall !== null) {
            $params['nature_of_call'] = '%' . self::escapeLike($f->natureOfCall) . '%';
            $clauses[] = 'calls.nature_of_call LIKE :nature_of_call';
        }

        // Free-text q across narratives + caller + incident #. Joins narratives if used.
        if ($f->search !== null && $f->search !== '') {
            if (!$ctx->isJoined('narratives')) {
                $joins[] = 'LEFT JOIN narratives ON narratives.call_id = calls.id';
            }
            if (!$needsIncidents && !$ctx->isJoined('incidents')) {
                $joins[] = 'LEFT JOIN incidents ON incidents.call_id = calls.id';
            }
            $params['q'] = '%' . self::escapeLike($f->search) . '%';
            $clauses[] = '(narratives.note LIKE :q OR calls.caller_name LIKE :q OR incidents.incident_number LIKE :q)';
        }

        // Status: each selected value becomes a parenthesised clause; multiple OR'd
        if ($f->status !== []) {
            $statusClauses = [];
            foreach ($f->status as $s) {
                $statusClauses[] = match ($s) {
                    'open'     => '(calls.closed_flag = 0 AND calls.canceled_flag = 0)',
                    'closed'   => '(calls.closed_flag = 1 AND calls.canceled_flag = 0)',
                    'canceled' => '(calls.canceled_flag = 1)',
                };
            }
            $clauses[] = '(' . implode(' OR ', $statusClauses) . ')';
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
