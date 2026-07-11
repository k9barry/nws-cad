<?php

declare(strict_types=1);

namespace NwsCad\Import;

use NwsCad\Database;
use PDO;
use SimpleXMLElement;

/**
 * Pre/post snapshots and reopen detection for a call ingest (#49, extracted from
 * AegisXmlParser). The existing (pre-write) snapshot and incoming (from-XML)
 * snapshot feed IntentResolver; detectReopen() distinguishes a genuine
 * dispatcher reopen from CAD-source ClosedFlag noise.
 *
 * snapshotExisting() reads through Database::getConnection() — the same singleton
 * the parser's open transaction uses at the point this is called, so it observes
 * within-transaction state exactly as the inlined version did.
 */
final class ReopenDetector
{
    /**
     * Snapshot of the call's current DB state, or null if the call is new.
     *
     * @return array<string,mixed>|null
     */
    public function snapshotExisting(int $xmlCallId): ?array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id FROM calls WHERE call_id = ?");
        $stmt->execute([$xmlCallId]);
        $row = $stmt->fetch();
        if (! $row) {
            return null;
        }
        $dbCallId = (int) $row['id'];

        $scalar = function (string $sql) use ($db, $dbCallId): string {
            $st = $db->prepare($sql);
            $st->execute([$dbCallId]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? '' : (string) $v;
        };
        $scalarInt = function (string $sql) use ($db, $dbCallId): int {
            $st = $db->prepare($sql);
            $st->execute([$dbCallId]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? 0 : (int) $v;
        };
        $list = function (string $sql) use ($db, $dbCallId): string {
            $st = $db->prepare($sql);
            $st->execute([$dbCallId]);
            $rows = $st->fetchAll(PDO::FETCH_COLUMN);
            return implode('|', $rows ?: []);
        };

        return [
            'call_type'     => $scalar("SELECT call_type FROM agency_contexts WHERE call_id = ? ORDER BY id LIMIT 1"),
            'full_address'  => $scalar("SELECT full_address FROM locations WHERE call_id = ? ORDER BY id LIMIT 1"),
            'alarm_level'   => $scalarInt("SELECT alarm_level FROM calls WHERE id = ?"),
            'units'         => $list("SELECT unit_number FROM units WHERE call_id = ? ORDER BY unit_number"),
            'jurisdictions' => $list("SELECT DISTINCT jurisdiction FROM incidents WHERE call_id = ? AND jurisdiction IS NOT NULL ORDER BY jurisdiction"),
            'agencies'      => $list("SELECT DISTINCT agency_type FROM agency_contexts WHERE call_id = ? AND agency_type IS NOT NULL ORDER BY agency_type"),
            'close_datetime' => $scalar("SELECT close_datetime FROM calls WHERE id = ?"),
        ];
    }

    /**
     * True when an incoming XML represents a reopen of a previously-closed call:
     * a unit assigned after the prior close timestamp with no clear time yet.
     * Distinguishes legitimate dispatcher reopens from CAD-source ClosedFlag
     * inconsistency (the latter carries no fresh unit activity).
     *
     * @param array<string,mixed>|null $existingSnapshot
     */
    public function detectReopen(SimpleXMLElement $xml, ?array $existingSnapshot): bool
    {
        if ($existingSnapshot === null) {
            return false;
        }
        $existingClose = (string) ($existingSnapshot['close_datetime'] ?? '');
        if ($existingClose === '') {
            return false;
        }
        // Convert DB datetime ("YYYY-MM-DD HH:MM:SS") to ISO ("YYYY-MM-DDTHH:MM:SS")
        // for direct string comparison against the XML's AssignedDateTime values.
        $existingCloseIso = str_replace(' ', 'T', $existingClose);

        foreach ($xml->AssignedUnits->Unit ?? [] as $u) {
            $clear = trim((string) $u->ClearDateTime);
            $assigned = trim((string) $u->AssignedDateTime);
            // ClearDateTime nil yields '' after cast; AssignedDateTime is ISO 8601
            // with optional Z suffix. Strip Z so the lexicographic compare against
            // the DB-format-derived ISO string works consistently.
            $assignedNorm = rtrim($assigned, 'Z');
            if ($clear === '' && $assignedNorm !== '' && $assignedNorm > $existingCloseIso) {
                return true;
            }
        }
        return false;
    }

    /**
     * Snapshot of the incoming XML's salient fields (for IntentResolver).
     *
     * @return array<string,mixed>
     */
    public function snapshotIncoming(SimpleXMLElement $xml): array
    {
        $units = [];
        foreach ($xml->AssignedUnits->Unit ?? [] as $u) {
            $n = trim((string) $u->UnitNumber);
            if ($n !== '') {
                $units[] = $n;
            }
        }
        $junctions = [];
        foreach ($xml->Incidents->Incident ?? [] as $inc) {
            $j = trim((string) $inc->Jurisdiction);
            if ($j !== '') {
                $junctions[] = $j;
            }
        }
        $agencies = [];
        $callType = '';
        foreach ($xml->AgencyContexts->AgencyContext ?? [] as $ac) {
            $a = trim((string) $ac->AgencyType);
            if ($a !== '') {
                $agencies[] = $a;
            }
            if ($callType === '') {
                $callType = trim((string) $ac->CallType);
            }
        }
        return [
            'call_type' => $callType,
            'full_address' => trim((string) ($xml->Location->FullAddress ?? '')),
            'alarm_level' => (int) ($xml->AlarmLevel ?? 0),
            'units' => implode('|', array_values(array_unique($units))),
            'jurisdictions' => implode('|', array_values(array_unique($junctions))),
            'agencies' => implode('|', array_values(array_unique($agencies))),
            'closed_flag' => (bool) ValueCaster::toBool((string) ($xml->ClosedFlag ?? 'false')),
            'create_datetime' => (string) ($xml->CreateDateTime ?? ''),
        ];
    }
}
