<?php

use App\Services\RateResolver;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Shop-level Pricing settings: which price list an invoice uses and when a changed POS rate is allowed.
 *
 * @internal
 */
final class RateResolverTest extends CIUnitTestCase
{
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'wholesale_price_list_enabled' => 1,
            'default_price_list' => 'retail',
            'walkin_price_list' => 'retail',
            'pos_rate_change_mode' => 'reason_required',
            'pos_price_list_switch' => 0,
        ], $overrides);
    }

    public function testCustomerPriceListWinsAndShopDefaultFollowsTheShop(): void
    {
        $shop = $this->settings(['default_price_list' => 'wholesale']);

        $this->assertSame('retail', RateResolver::defaultPriceList($shop, ['customer_type' => 'retail']));
        $this->assertSame('wholesale', RateResolver::defaultPriceList($shop, ['customer_type' => 'wholesale']));
        $this->assertSame('wholesale', RateResolver::defaultPriceList($shop, ['customer_type' => 'shop_default']));
        $this->assertSame('retail', RateResolver::defaultPriceList($this->settings(), ['customer_type' => 'shop_default']));
    }

    public function testWalkInUsesTheWalkInPriceList(): void
    {
        $this->assertSame('retail', RateResolver::defaultPriceList($this->settings(), null));
        $this->assertSame('wholesale', RateResolver::defaultPriceList($this->settings(['walkin_price_list' => 'wholesale']), null));
    }

    public function testWholesaleOffForcesRetailEverywhere(): void
    {
        $shop = $this->settings(['wholesale_price_list_enabled' => 0, 'default_price_list' => 'wholesale', 'walkin_price_list' => 'wholesale']);

        $this->assertSame('retail', RateResolver::defaultPriceList($shop, ['customer_type' => 'wholesale']));
        $this->assertSame('retail', RateResolver::defaultPriceList($shop, null));
    }

    public function testInvoiceSwitchNeedsTheSetting(): void
    {
        $customer = ['customer_type' => 'retail'];

        $this->assertSame('retail', RateResolver::pickPriceList($this->settings(), $customer, 'retail'), 'Sending the default list is not a switch.');
        $this->assertSame('retail', RateResolver::pickPriceList($this->settings(), $customer, ''));

        $this->expectException(RuntimeException::class);
        RateResolver::pickPriceList($this->settings(), $customer, 'wholesale');
    }

    public function testInvoiceSwitchWhenAllowed(): void
    {
        $shop = $this->settings(['pos_price_list_switch' => 1]);

        $this->assertSame('wholesale', RateResolver::pickPriceList($shop, null, 'wholesale'));
        $this->assertSame('retail', RateResolver::pickPriceList($shop, ['customer_type' => 'wholesale'], 'retail'));
    }

    public function testInvoiceSwitchCannotPickADisabledWholesaleList(): void
    {
        $this->expectException(RuntimeException::class);
        RateResolver::pickPriceList($this->settings(['pos_price_list_switch' => 1, 'wholesale_price_list_enabled' => 0]), null, 'wholesale');
    }

    public function testUnchangedRatesNeedNoPermission(): void
    {
        RateResolver::assertRateChange($this->settings(['pos_rate_change_mode' => 'not_allowed']), false, null);
        $this->assertTrue(true);
    }

    public function testChangedRateRules(): void
    {
        RateResolver::assertRateChange($this->settings(['pos_rate_change_mode' => 'allowed']), true, null);
        RateResolver::assertRateChange($this->settings(), true, 'Regular customer');

        foreach ([['not_allowed', 'Any reason'], ['reason_required', '  ']] as [$mode, $reason]) {
            try {
                RateResolver::assertRateChange($this->settings(['pos_rate_change_mode' => $mode]), true, $reason);
                $this->fail("Mode {$mode} must reject the changed rate.");
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testRecentPosTimeMeansNow(): void
    {
        $now = strtotime('2026-10-21 20:33:41');
        $this->assertSame($now, RateResolver::pricingTime(strtotime('2026-10-21 20:33:00'), $now));
        $this->assertSame($now, RateResolver::pricingTime(strtotime('2026-10-21 20:24:00'), $now));
        $this->assertSame(strtotime('2026-10-21 20:23:00'), RateResolver::pricingTime(strtotime('2026-10-21 20:23:00'), $now));
        $this->assertSame(strtotime('2026-10-21 20:40:00'), RateResolver::pricingTime(strtotime('2026-10-21 20:40:00'), $now));
    }

    public function testBackdatedPriceRules(): void
    {
        RateResolver::assertBackdatedPrices($this->settings(['pos_rate_change_mode' => 'allowed']), null);
        RateResolver::assertBackdatedPrices($this->settings(), 'Entering yesterday\'s sale');

        foreach ([['not_allowed', 'Any reason'], ['reason_required', '']] as [$mode, $reason]) {
            try {
                RateResolver::assertBackdatedPrices($this->settings(['pos_rate_change_mode' => $mode]), $reason);
                $this->fail("Mode {$mode} must reject backdated prices.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('in the past', $e->getMessage());
            }
        }
    }
}
