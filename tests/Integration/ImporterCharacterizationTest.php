<?php

declare(strict_types=1);

namespace NwsCad\Tests\Integration;

use NwsCad\AegisXmlParser;
use NwsCad\Api\DbHelper;
use NwsCad\Database;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of the full XML → 13-table ingest, asserting exact row
 * contents after processing the fixtures in tests/fixtures/xml/. This is the
 * behavior-preserving safety net for the importer refactor (#49): it must pass
 * unchanged, on both MySQL and PostgreSQL, before and after that refactor.
 *
 * @covers \NwsCad\AegisXmlParser
 * @uses \NwsCad\Import\DateTimeParser
 * @uses \NwsCad\Import\ValueCaster
 * @uses \NwsCad\Import\XmlLoader
 * @uses \NwsCad\Import\XmlValidator
 * @uses \NwsCad\Db\UpsertBuilder
 * @uses \NwsCad\Api\DbHelper
 * @uses \NwsCad\Config
 * @uses \NwsCad\Database
 * @uses \NwsCad\FilenameParser
 * @uses \NwsCad\Logger
 * @uses \NwsCad\Logging\RedactingProcessor
 * @uses \NwsCad\Logging\SecretRegistry
 * @uses \NwsCad\Api\Filtering\FilterOptionsCache
 * @uses \NwsCad\Notifications\EventDispatcher
 * @uses \NwsCad\Notifications\Events\CallProcessedEvent
 * @uses \NwsCad\Notifications\Events\Intent
 * @uses \NwsCad\Notifications\IntentResolver
 */
class ImporterCharacterizationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/xml';

    protected function setUp(): void
    {
        parent::setUp();
        // Driver-aware "is a DB configured?" gate: check the host of whichever
        // driver DB_TYPE selects, not MYSQL_HOST unconditionally.
        $hostVar = (getenv('DB_TYPE') ?: 'mysql') === 'pgsql' ? 'POSTGRES_HOST' : 'MYSQL_HOST';
        if (!getenv($hostVar)) {
            $this->markTestSkipped('Database not configured for testing');
        }
        cleanTestDatabase();
    }

    private function ingest(string $fixture): void
    {
        $parser = new AegisXmlParser();
        $ok = $parser->processFile(self::FIXTURES . '/' . $fixture);
        $this->assertTrue($ok, "Fixture {$fixture} should ingest successfully");
    }

    private function callDbId(int $callId): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM calls WHERE call_id = ?");
        $stmt->execute([$callId]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, "calls row for call_id={$callId} must exist");
        return (int) $id;
    }

    /**
     * @return array<string,mixed>
     */
    private function oneRow(string $sql, int $bind): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare($sql);
        $stmt->execute([$bind]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "expected exactly one row for: {$sql}");
        return $row;
    }

    private function countFor(string $table, int $dbCallId): int
    {
        // $table is always a hard-coded literal here, but validate it against
        // the same identifier allowlist production SQL uses before interpolating
        // (compliance: never interpolate an unvalidated identifier).
        DbHelper::validateIdentifier($table, 'table');
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE call_id = ?");
        $stmt->execute([$dbCallId]);
        return (int) $stmt->fetchColumn();
    }

    public function testFullCallPopulatesEveryChildTableWithExactValues(): void
    {
        $this->ingest('full_call.xml');
        $id = $this->callDbId(700001);

        // calls
        $call = $this->oneRow("SELECT * FROM calls WHERE id = ?", $id);
        $this->assertSame('2026-700001', $call['call_number']);
        $this->assertSame('911', $call['call_source']);
        $this->assertSame('Ada Lovelace', $call['caller_name']);
        $this->assertSame('555-0100', $call['caller_phone']);
        $this->assertSame('Structure Fire', $call['nature_of_call']);
        $this->assertSame('2026-02-01 08:15:00', $call['create_datetime']);
        $this->assertSame(0, (int) $call['closed_flag']);
        $this->assertSame(0, (int) $call['canceled_flag']);
        $this->assertSame(0, (int) $call['reopened_flag']);
        $this->assertSame(2, (int) $call['alarm_level']);

        // agency_contexts
        $ac = $this->oneRow("SELECT * FROM agency_contexts WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('agency_contexts', $id));
        $this->assertSame('Fire', $ac['agency_type']);
        $this->assertSame('Structure Fire', $ac['call_type']);
        $this->assertSame('High', $ac['priority']);
        $this->assertSame('Active', $ac['status']);
        $this->assertSame('48013', $ac['fdid']);
        $this->assertSame(0, (int) $ac['closed_flag']);

        // locations
        $loc = $this->oneRow("SELECT * FROM locations WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('locations', $id));
        $this->assertSame('742 Evergreen Terrace', $loc['full_address']);
        $this->assertSame('742', $loc['house_number']);
        $this->assertSame('Evergreen', $loc['street_name']);
        $this->assertSame('Terrace', $loc['street_type']);
        $this->assertSame('Springfield', $loc['city']);
        $this->assertSame('IL', $loc['state']);
        $this->assertSame('62704', $loc['zip']);

        // incidents
        $inc = $this->oneRow("SELECT * FROM incidents WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('incidents', $id));
        $this->assertSame('INC-700001', $inc['incident_number']);
        $this->assertSame('Fire', $inc['incident_type']);
        $this->assertSame('City', $inc['jurisdiction']);
        $this->assertSame('2026-02-01 08:15:30', $inc['create_datetime']);

        // narratives
        $nar = $this->oneRow("SELECT * FROM narratives WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('narratives', $id));
        $this->assertSame('2026-02-01 08:16:00', $nar['create_datetime']);
        $this->assertSame('dispatcher01', $nar['create_user']);
        $this->assertSame('Dispatch', $nar['narrative_type']);
        $this->assertSame('Smoke showing from second floor.', $nar['text']);
        $this->assertSame('None', $nar['restriction']);

        // persons
        $per = $this->oneRow("SELECT * FROM persons WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('persons', $id));
        $this->assertSame('Grace', $per['first_name']);
        $this->assertSame('Hopper', $per['last_name']);
        $this->assertSame('Witness', $per['role']);

        // vehicles
        $veh = $this->oneRow("SELECT * FROM vehicles WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('vehicles', $id));
        $this->assertSame('NAVY01', $veh['license_plate']);
        $this->assertSame('IL', $veh['license_state']);
        $this->assertSame('Ford', $veh['make']);
        $this->assertSame('Bronco', $veh['model']);
        $this->assertSame(2022, (int) $veh['year']);

        // call_dispositions
        $cd = $this->oneRow("SELECT * FROM call_dispositions WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('call_dispositions', $id));
        $this->assertSame('Fire Out', $cd['disposition_name']);
        $this->assertSame(1, (int) $cd['count']);
        $this->assertSame('2026-02-01 09:30:00', $cd['disposition_datetime']);

        // units
        $unit = $this->oneRow("SELECT * FROM units WHERE call_id = ?", $id);
        $this->assertSame(1, $this->countFor('units', $id));
        $unitId = (int) $unit['id'];
        $this->assertSame('E12', $unit['unit_number']);
        $this->assertSame('Engine', $unit['unit_type']);
        $this->assertSame(1, (int) $unit['is_primary']);
        $this->assertSame('City', $unit['jurisdiction']);
        $this->assertSame('2026-02-01 08:15:10', $unit['assigned_datetime']);
        $this->assertSame('2026-02-01 08:15:20', $unit['dispatch_datetime']);
        $this->assertSame('2026-02-01 08:16:00', $unit['enroute_datetime']);
        $this->assertSame('2026-02-01 08:20:00', $unit['arrive_datetime']);
        $this->assertSame('2026-02-01 09:45:00', $unit['clear_datetime']);

        // unit_personnel
        $up = $this->oneRow("SELECT * FROM unit_personnel WHERE unit_id = ?", $unitId);
        $this->assertSame('John', $up['first_name']);
        $this->assertSame('Q', $up['middle_name']);
        $this->assertSame('Firefighter', $up['last_name']);
        $this->assertSame('FF-001', $up['id_number']);
        $this->assertSame('1234', $up['shield_number']);
        $this->assertSame(1, (int) $up['is_primary_officer']);
        $this->assertSame('City', $up['jurisdiction']);

        // unit_logs
        $ul = $this->oneRow("SELECT * FROM unit_logs WHERE unit_id = ?", $unitId);
        $this->assertSame('2026-02-01 08:20:00', $ul['log_datetime']);
        $this->assertSame('OnScene', $ul['status']);
        $this->assertSame('742 Evergreen Terrace', $ul['location']);

        // unit_dispositions
        $ud = $this->oneRow("SELECT * FROM unit_dispositions WHERE unit_id = ?", $unitId);
        $this->assertSame('Extinguished', $ud['disposition_name']);
        $this->assertSame('Fire fully extinguished', $ud['description']);
        $this->assertSame(1, (int) $ud['count']);
        $this->assertSame('2026-02-01 09:30:00', $ud['disposition_datetime']);
    }

    public function testMinimalCallInsertsOnlyTheCallRow(): void
    {
        $this->ingest('minimal_call.xml');
        $id = $this->callDbId(700002);

        $call = $this->oneRow("SELECT * FROM calls WHERE id = ?", $id);
        $this->assertSame('2026-700002', $call['call_number']);
        $this->assertSame('2026-02-01 10:00:00', $call['create_datetime']);
        $this->assertSame(0, (int) $call['closed_flag']);

        foreach (['agency_contexts', 'locations', 'incidents', 'narratives',
                  'persons', 'vehicles', 'call_dispositions', 'units'] as $table) {
            $this->assertSame(0, $this->countFor($table, $id),
                "{$table} must have no rows for a minimal call");
        }
    }

    public function testNilFieldsAreCoercedToNull(): void
    {
        $this->ingest('nil_fields.xml');
        $id = $this->callDbId(700003);

        $call = $this->oneRow("SELECT * FROM calls WHERE id = ?", $id);
        $this->assertNull($call['call_source'], 'xsi:nil call source must be NULL');
        $this->assertNull($call['caller_name'], 'xsi:nil caller name must be NULL');
        $this->assertNull($call['close_datetime'], 'xsi:nil close datetime must be NULL');
        $this->assertNull($call['alarm_level'], 'xsi:nil alarm level must be NULL');

        $loc = $this->oneRow("SELECT * FROM locations WHERE call_id = ?", $id);
        $this->assertSame('1 Unknown Way', $loc['full_address']);
        $this->assertNull($loc['house_number'], 'xsi:nil house number must be NULL');
        $this->assertNull($loc['zip'], 'xsi:nil zip must be NULL');

        $ac = $this->oneRow("SELECT * FROM agency_contexts WHERE call_id = ?", $id);
        $this->assertNull($ac['priority'], 'xsi:nil priority must be NULL');
        $this->assertSame('Active', $ac['status']);
    }

    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function bomProvider(): array
    {
        return [
            'UTF-8 BOM'    => ['bom_utf8.xml', 700004],
            'UTF-16BE BOM' => ['bom_utf16be.xml', 700005],
            'UTF-16LE BOM' => ['bom_utf16le.xml', 700006],
        ];
    }

    /**
     * @dataProvider bomProvider
     */
    public function testBomPrefixedFilesIngestCleanly(string $fixture, int $callId): void
    {
        $this->ingest($fixture);
        $call = $this->oneRow("SELECT * FROM calls WHERE call_id = ?", $callId);
        $this->assertSame((string) $callId, (string) $call['call_id']);
    }
}
