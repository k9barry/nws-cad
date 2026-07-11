<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use InvalidArgumentException;
use NwsCad\Db\UpsertBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Db\UpsertBuilder
 * @uses \NwsCad\Api\DbHelper
 */
class UpsertBuilderTest extends TestCase
{
    public function testMysqlUpsert(): void
    {
        $sql = UpsertBuilder::upsert(
            'mysql',
            'incidents',
            ['call_id', 'incident_number', 'incident_type'],
            ['call_id', 'incident_number'],
            ['incident_type']
        );

        $this->assertSame(
            'INSERT INTO incidents (call_id, incident_number, incident_type) '
            . 'VALUES (:call_id, :incident_number, :incident_type) AS new_row '
            . 'ON DUPLICATE KEY UPDATE incident_type = new_row.incident_type, '
            . 'updated_at = CURRENT_TIMESTAMP',
            $sql
        );
    }

    public function testPostgresUpsert(): void
    {
        $sql = UpsertBuilder::upsert(
            'pgsql',
            'incidents',
            ['call_id', 'incident_number', 'incident_type'],
            ['call_id', 'incident_number'],
            ['incident_type']
        );

        $this->assertSame(
            'INSERT INTO incidents (call_id, incident_number, incident_type) '
            . 'VALUES (:call_id, :incident_number, :incident_type) '
            . 'ON CONFLICT (call_id, incident_number) DO UPDATE SET '
            . 'incident_type = EXCLUDED.incident_type, updated_at = CURRENT_TIMESTAMP',
            $sql
        );
    }

    public function testPostgresUpsertReturningId(): void
    {
        $sql = UpsertBuilder::upsert(
            'pgsql',
            'units',
            ['call_id', 'unit_number'],
            ['call_id', 'unit_number'],
            ['unit_number'],
            true
        );

        $this->assertStringEndsWith(' RETURNING id', $sql);
    }

    public function testMysqlUpsertNeverReturnsId(): void
    {
        $sql = UpsertBuilder::upsert(
            'mysql',
            'units',
            ['call_id', 'unit_number'],
            ['call_id', 'unit_number'],
            ['unit_number'],
            true
        );

        $this->assertStringNotContainsString('RETURNING', $sql);
    }

    public function testMysqlInsertIgnore(): void
    {
        $sql = UpsertBuilder::insertIgnore(
            'mysql',
            'unit_logs',
            ['unit_id', 'log_datetime', 'status', 'location'],
            ['unit_id', 'log_datetime', 'status', 'location']
        );

        $this->assertSame(
            'INSERT IGNORE INTO unit_logs (unit_id, log_datetime, status, location) '
            . 'VALUES (:unit_id, :log_datetime, :status, :location)',
            $sql
        );
    }

    public function testPostgresInsertIgnore(): void
    {
        $sql = UpsertBuilder::insertIgnore(
            'pgsql',
            'narratives',
            ['call_id', 'create_datetime', 'create_user', 'text'],
            ['call_id', 'create_datetime', 'create_user', 'text']
        );

        $this->assertSame(
            'INSERT INTO narratives (call_id, create_datetime, create_user, text) '
            . 'VALUES (:call_id, :create_datetime, :create_user, :text) '
            . 'ON CONFLICT (call_id, create_datetime, create_user, text) DO NOTHING',
            $sql
        );
    }

    public function testRejectsInvalidIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UpsertBuilder::upsert('mysql', 'units; DROP TABLE calls', ['a'], ['a'], ['a']);
    }
}
