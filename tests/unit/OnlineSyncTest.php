<?php

use App\Services\OnlineSyncService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Connection parsing and schema comparison of the online sync.
 *
 * @internal
 */
final class OnlineSyncTest extends CIUnitTestCase
{
    public function testHostAloneIsAccepted(): void
    {
        $this->assertSame(['host' => 'db.example.com', 'port' => null, 'database' => null], OnlineSyncService::parseTarget(' db.example.com '));
    }

    public function testFullUrlGivesPortAndDatabase(): void
    {
        $this->assertSame(['host' => 'db.example.com', 'port' => 3307, 'database' => 'shop'], OnlineSyncService::parseTarget('mysql://db.example.com:3307/shop'));
    }

    public function testHostWithPortIsAccepted(): void
    {
        $this->assertSame(['host' => 'db.example.com', 'port' => 3307, 'database' => null], OnlineSyncService::parseTarget('db.example.com:3307'));
    }

    public static function badTargets(): array
    {
        return [[''], ['   '], ['bad host'], ['db;drop']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTargets')]
    public function testBadTargetIsRefused(string $target): void
    {
        $this->expectException(RuntimeException::class);
        OnlineSyncService::parseTarget($target);
    }

    public function testMatchingSchemasHaveNoDifferences(): void
    {
        $schema = ['sales' => ['id' => 'bigint unsigned NO']];

        $this->assertSame([], OnlineSyncService::schemaDifferences($schema, $schema));
    }

    public function testMissingExtraAndChangedColumnsAreReported(): void
    {
        $local = ['sales' => ['id' => 'bigint NO', 'total' => 'decimal(14,2) NO']];
        $online = ['sales' => ['id' => 'int NO', 'extra' => 'int YES']];

        $this->assertSame([
            'sales.id is int NO online but bigint NO here',
            'sales.total is missing online',
            'sales.extra exists only online',
        ], OnlineSyncService::schemaDifferences($local, $online));
    }

    public function testMissingTableIsNotADifferenceBecauseItIsCreated(): void
    {
        $this->assertSame([], OnlineSyncService::schemaDifferences(['new_table' => ['id' => 'int NO']], []));
    }

    public function testConnectionAndHistoryStayOnThisComputer(): void
    {
        foreach (['online_sync_settings', 'online_sync_log', 'lpg_migration_history'] as $table) {
            $this->assertContains($table, OnlineSyncService::LOCAL_ONLY_TABLES);
        }
    }

    public function testInstallerSchedulesTheHourlySync(): void
    {
        $this->assertStringContainsString('hourly=spark sync:online', (string) file_get_contents(ROOTPATH . 'installer/app.ini'));
        $this->assertStringContainsString('hourly.ps1', (string) file_get_contents(ROOTPATH . 'installer/setup.iss'));
    }
}
