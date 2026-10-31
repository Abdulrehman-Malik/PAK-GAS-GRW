<?php

use App\Services\OldIssuedCylinderService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Row rules of Old Issued Cylinders, and the promise that an entry stays out of sales, cash and shop stock.
 *
 * @internal
 */
final class OldIssuedCylinderTest extends CIUnitTestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge(['issued_on' => '2026-09-15', 'customer_id' => '1', 'cylinder_type_id' => '2', 'quantity' => '3', 'rate_per_cylinder' => '8000', 'amount_received' => '10000'], $overrides);
    }

    private function validate(array $row): array
    {
        $service = (new ReflectionClass(OldIssuedCylinderService::class))->newInstanceWithoutConstructor();

        return $service->validateRow($row, '2026-10-31');
    }

    public function testAmountAndOutstandingAreCalculated(): void
    {
        $row = $this->validate($this->row());

        $this->assertSame(24000.0, $row['amount']);
        $this->assertSame(14000.0, $row['outstanding']);
        $this->assertSame(3, $row['quantity']);
    }

    public function testBlankRateAndReceivedMeanZero(): void
    {
        $row = $this->validate($this->row(['rate_per_cylinder' => '', 'amount_received' => '']));

        $this->assertSame(0.0, $row['amount']);
        $this->assertSame(0.0, $row['outstanding']);
    }

    public static function badRows(): array
    {
        return [
            'future date' => [['issued_on' => '2026-11-01'], 'future'],
            'impossible date' => [['issued_on' => '2026-02-31'], 'valid date'],
            'walk-in has no customer' => [['customer_id' => ''], 'customer'],
            'no cylinder' => [['cylinder_type_id' => ''], 'cylinder type'],
            'zero quantity' => [['quantity' => '0'], 'Quantity'],
            'part quantity' => [['quantity' => '1.5'], 'Quantity'],
            'too many' => [['quantity' => '501'], 'Quantity'],
            'negative rate' => [['rate_per_cylinder' => '-1'], 'Rate'],
            'received over amount' => [['amount_received' => '24000.01'], 'Received amount'],
        ];
    }

    /** @dataProvider badRows */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRows')]
    public function testBadRowsAreRefused(array $overrides, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $this->validate($this->row($overrides));
    }

    public function testEntryStaysOutOfSalesCashAndStock(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Services/OldIssuedCylinderService.php');

        foreach (["table('sales')", 'sale_payments', 'inventory_movements', 'customer_security_deposits', 'cash_transactions', 'CashService'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testDataRefreshClearsEntries(): void
    {
        $this->assertStringContainsString("'old_issued_cylinders'", (string) file_get_contents(APPPATH . 'Services/TransactionalDataRefreshService.php'));
    }
}
