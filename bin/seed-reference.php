<?php
declare(strict_types=1);

// Note: ON DUPLICATE KEY UPDATE is MySQL syntax (v1). For PostgreSQL, replace with
// ON CONFLICT (...) DO UPDATE SET ... — a follow-up once Postgres operators need it.

require __DIR__ . '/../vendor/autoload.php';

use NwsCad\Database;

$path = $argv[1] ?? __DIR__ . '/../database/seeds/reference.json';
if (!is_readable($path)) {
    fwrite(STDERR, "Seed file not found: {$path}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$db = Database::getConnection();
$db->beginTransaction();
try {
    $agencyIdsByCode = [];
    $upAgency = $db->prepare(
        'INSERT INTO ref_agencies (code, label, kind, ori, fdid, active, sort_order)
         VALUES (:code, :label, :kind, :ori, :fdid, :active, :sort_order)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind),
            ori = VALUES(ori), fdid = VALUES(fdid), active = VALUES(active),
            sort_order = VALUES(sort_order)'
    );
    foreach ($data['agencies'] ?? [] as $a) {
        $upAgency->execute([
            ':code' => $a['code'], ':label' => $a['label'], ':kind' => $a['kind'],
            ':ori'  => $a['ori'],  ':fdid'  => $a['fdid'],  ':active' => $a['active'] ?? 1,
            ':sort_order' => $a['sort_order'] ?? 100,
        ]);
        $idStmt = $db->prepare('SELECT id FROM ref_agencies WHERE code = :code');
        $idStmt->execute([':code' => $a['code']]);
        $agencyIdsByCode[$a['code']] = (int) $idStmt->fetchColumn();
    }

    $upOri = $db->prepare(
        'INSERT INTO ref_oris (ori, label, kind, agency_id) VALUES (:ori, :label, :kind, :agency_id)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind), agency_id = VALUES(agency_id)'
    );
    foreach ($data['oris'] ?? [] as $o) {
        $upOri->execute([
            ':ori' => $o['ori'], ':label' => $o['label'], ':kind' => $o['kind'],
            ':agency_id' => isset($o['agency_code']) ? ($agencyIdsByCode[$o['agency_code']] ?? null) : null,
        ]);
    }

    $upFdid = $db->prepare(
        'INSERT INTO ref_fdids (fdid, label, agency_id) VALUES (:fdid, :label, :agency_id)
         ON DUPLICATE KEY UPDATE label = VALUES(label), agency_id = VALUES(agency_id)'
    );
    foreach ($data['fdids'] ?? [] as $f) {
        $upFdid->execute([
            ':fdid' => $f['fdid'], ':label' => $f['label'],
            ':agency_id' => isset($f['agency_code']) ? ($agencyIdsByCode[$f['agency_code']] ?? null) : null,
        ]);
    }

    $upBeat = $db->prepare(
        'INSERT INTO ref_beats (code, label, kind, jurisdiction, active)
         VALUES (:code, :label, :kind, :jurisdiction, :active)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind),
            jurisdiction = VALUES(jurisdiction), active = VALUES(active)'
    );
    foreach ($data['beats'] ?? [] as $b) {
        $upBeat->execute([
            ':code' => $b['code'], ':label' => $b['label'], ':kind' => $b['kind'],
            ':jurisdiction' => $b['jurisdiction'] ?? null, ':active' => $b['active'] ?? 1,
        ]);
    }

    $upArea = $db->prepare(
        'INSERT INTO ref_areas (code, label, kind, active)
         VALUES (:code, :label, :kind, :active)
         ON DUPLICATE KEY UPDATE label = VALUES(label), kind = VALUES(kind), active = VALUES(active)'
    );
    foreach ($data['areas'] ?? [] as $a) {
        $upArea->execute([
            ':code' => $a['code'], ':label' => $a['label'], ':kind' => $a['kind'],
            ':active' => $a['active'] ?? 1,
        ]);
    }

    $db->commit();
    $totals = [
        'agencies' => count($data['agencies'] ?? []),
        'oris'     => count($data['oris'] ?? []),
        'fdids'    => count($data['fdids'] ?? []),
        'beats'    => count($data['beats'] ?? []),
        'areas'    => count($data['areas'] ?? []),
    ];
    echo "Seeded reference data: " . json_encode($totals) . "\n";
} catch (\Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
    exit(2);
}
