<?php

use App\Services\SalesService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * Guards SalesService::customerBalances() (bulk) against customerBalance() (per customer).
 *
 * Opt-in: runs only against a MySQL/MariaDB "tests" group, e.g.
 *   database.tests.DBDriver=MySQLi database.tests.hostname=localhost database.tests.database=<scratch_db>
 *   database.tests.username=root database.tests.password= database.tests.DBPrefix= php vendor/bin/phpunit --filter CustomerBalancesParity
 * Everything happens inside a transaction that is rolled back.
 *
 * @internal
 */
final class CustomerBalancesParityTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->db = Database::connect();
            $usable   = $this->db->DBDriver === 'MySQLi' && $this->db->tableExists('customers') && $this->db->tableExists('shop_settings');
        } catch (\Throwable $e) {
            $usable = false;
        }
        if (!$usable) {
            $this->db = null;
            $this->markTestSkipped('Requires a MySQL tests database with the LPG schema (see class docblock).');
        }
        $this->db->transBegin();
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->transRollback();
        }
        parent::tearDown();
    }

    /** @return array<int,array<string,mixed>> */
    private function seed(int $locationId): array
    {
        $ids = [];
        foreach ([[100.5, 0], [0, 0], [250, 1]] as $i => [$opening, $inactive]) {
            $this->db->table('customers')->insert([
                'code' => 'PARITY' . $i, 'name' => 'Parity ' . $i, 'opening_balance' => $opening,
                'is_active' => $inactive ? 0 : 1,
            ]);
            $ids[$i] = (int) $this->db->insertID();
        }
        $sale = static fn(int $customer, string $no, string $status, float $credit, float $ledger) => [
            'sale_no' => $no, 'location_id' => $locationId, 'customer_id' => $customer, 'transaction_type' => 'gas_sale',
            'status' => $status, 'credit_amount' => $credit, 'return_gas_ledger_amount' => $ledger,
        ];
        $this->db->table('sales')->insertBatch([
            $sale($ids[0], 'PAR-1', 'posted', 300, 20),
            $sale($ids[0], 'PAR-2', 'posted', 50, 0),
            $sale($ids[0], 'PAR-3', 'voided', 999, 999),   // voided sales must not count
            $sale($ids[2], 'PAR-4', 'posted', 10, 5),
        ]);
        $receipt = static fn(int $customer, string $no, string $status, float $amount) => [
            'receipt_no' => $no, 'location_id' => $locationId, 'customer_id' => $customer, 'status' => $status,
            'amount' => $amount, 'payment_mode' => 'cash',
        ];
        $this->db->table('customer_receipts')->insertBatch([
            $receipt($ids[0], 'PAR-R1', 'posted', 120),
            $receipt($ids[0], 'PAR-R2', 'voided', 500),    // voided receipts must not count
            $receipt($ids[2], 'PAR-R3', 'posted', 300),
        ]);
        $this->db->table('customer_security_deposits')->insertBatch([
            ['location_id' => $locationId, 'customer_id' => $ids[0], 'entry_type' => 'hold', 'amount' => 1000],
            ['location_id' => $locationId, 'customer_id' => $ids[0], 'entry_type' => 'refund', 'amount' => 400],
            ['location_id' => $locationId, 'customer_id' => $ids[2], 'entry_type' => 'refund', 'amount' => 50], // negative -> clamped to 0
        ]);

        return $this->db->table('customers')->whereIn('id', $ids)->get()->getResultArray();
    }

    /** @dataProvider depositSettingProvider */
    public function testBulkBalancesMatchPerCustomerBalances(int $includeDeposit): void
    {
        $location = $this->db->table('locations')->get(1)->getRowArray();
        if (!$location) {
            $this->db->table('locations')->insert(['code' => 'PARITY', 'name' => 'Parity Location']);
            $locationId = (int) $this->db->insertID();
        } else {
            $locationId = (int) $location['id'];
        }
        $existing = $this->db->table('shop_settings')->where('location_id', $locationId)->get()->getRowArray();
        if ($existing) {
            $this->db->table('shop_settings')->where('id', $existing['id'])->update(['include_security_deposit_in_os' => $includeDeposit]);
        } else {
            $this->db->table('shop_settings')->insert(['location_id' => $locationId, 'include_security_deposit_in_os' => $includeDeposit]);
        }

        session()->set('location_id', $locationId);
        $customers = $this->seed($locationId);
        $service   = new SalesService();
        $bulk      = $service->customerBalances($customers);

        $this->assertCount(3, $bulk);
        foreach ($customers as $customer) {
            $id = (int) $customer['id'];
            $this->assertEqualsWithDelta($service->customerBalance($id), $bulk[$id], 0.0001, 'customer ' . $customer['code']);
        }
    }

    public static function depositSettingProvider(): array
    {
        return ['deposit excluded from OS' => [0], 'deposit included in OS' => [1]];
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], (new SalesService())->customerBalances([]));
    }
}
