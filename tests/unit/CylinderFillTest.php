<?php

use App\Services\CylinderFillService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Fill Empty Cylinders changes stock only.
 *
 * @internal
 */
final class CylinderFillTest extends CIUnitTestCase
{
    public function testWholeQuantityIsAccepted(): void
    {
        $this->assertSame(3, CylinderFillService::parseQuantity(' 3 '));
    }

    public static function badQuantities(): array
    {
        return [['0'], ['-1'], ['1.5'], ['abc'], ['']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badQuantities')]
    public function testBadQuantityIsRefused(string $quantity): void
    {
        $this->expectException(RuntimeException::class);
        CylinderFillService::parseQuantity($quantity);
    }

    public function testFillStaysOutOfPurchasesAndCash(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Services/CylinderFillService.php');

        foreach (["table('purchases')", 'purchase_items', 'supplier', 'cash_transactions', 'CashService', "table('sales')"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    public function testDataRefreshClearsHistory(): void
    {
        $this->assertStringContainsString("'cylinder_fills'", (string) file_get_contents(APPPATH . 'Services/TransactionalDataRefreshService.php'));
    }
}
