<?php

namespace App\Services;

use App\Models\CustomerRateModel;
use App\Models\GasRateModel;
use App\Models\ShopSettingsModel;
use RuntimeException;

/**
 * Single source of POS pricing for one shop.
 *
 * Price list: invoice switch (when allowed) -> walk-in list -> customer's list
 * (Retail / Wholesale / Shop default -> shop Default rate).
 * Rate: the customer's special rate (when the shop has Customer special rates on)
 * -> the list's own price (per KG and per full cylinder convert into each other)
 * -> Retail when Wholesale has no price -> Cylinder Type price (empty cylinders only) -> none.
 * Gas inside a whole cylinder (Cylinder Sale, Security Deposit) prefers the full-cylinder price.
 * A backdated posting whose prices differ from today's counts as a rate change.
 */
class RateResolver
{
    public const PRICE_LISTS = ['retail','wholesale'];
    public const RATE_CHANGE_MODES = ['not_allowed','allowed','reason_required'];
    /** Pseudo price list used internally for a customer's special rates. */
    protected const CUSTOMER = 'customer';
    public const NOW_WINDOW_SECONDS = 600;

    protected int $locationId;
    protected array $settings;
    protected GasRateModel $rates;
    protected CustomerRateModel $customerRates;
    protected ?int $customerId = null;
    protected array $cache = [];

    public function __construct(int $locationId, ?array $settings = null)
    {
        $this->locationId = $locationId;
        $this->settings = $settings ?? (new ShopSettingsModel())->forLocation($locationId);
        $this->rates = new GasRateModel();
        $this->customerRates = new CustomerRateModel();
    }

    /**
     * Applies this customer's special rates to every lookup. Walk-in (null) never has special
     * rates, and nothing applies when Customer special rates is off in Shop Settings.
     */
    public function forCustomer(?array $customer): self
    {
        $enabled = (int)($this->settings['customer_special_rates_enabled'] ?? 1) === 1;
        $this->customerId = ($enabled && $customer !== null && !empty($customer['id'])) ? (int)$customer['id'] : null;
        return $this;
    }

    public function settings(): array
    {
        return $this->settings;
    }

    /** The price list a customer (null = walk-in) gets before any invoice switch. */
    public static function defaultPriceList(array $settings, ?array $customer): string
    {
        if (!(int)($settings['wholesale_price_list_enabled'] ?? 1)) {
            return 'retail';
        }
        if ($customer === null) {
            $list = (string)($settings['walkin_price_list'] ?? 'retail');
        } else {
            $list = (string)($customer['customer_type'] ?? 'shop_default');
            if (!in_array($list, self::PRICE_LISTS, true)) {
                $list = (string)($settings['default_price_list'] ?? 'retail');
            }
        }
        return in_array($list, self::PRICE_LISTS, true) ? $list : 'retail';
    }

    /** Applies an optional invoice-level price list switch and enforces the shop settings. */
    public static function pickPriceList(array $settings, ?array $customer, ?string $requested): string
    {
        $default = self::defaultPriceList($settings, $customer);
        $requested = trim((string)$requested);
        if ($requested === '' || $requested === $default) {
            return $default;
        }
        if (!in_array($requested, self::PRICE_LISTS, true)) {
            throw new RuntimeException('Invalid price list.');
        }
        if ($requested === 'wholesale' && !(int)($settings['wholesale_price_list_enabled'] ?? 1)) {
            throw new RuntimeException('The Wholesale price list is turned off in Shop Settings.');
        }
        if (!(int)($settings['pos_price_list_switch'] ?? 0)) {
            throw new RuntimeException('Changing the price list on the invoice is not allowed in Shop Settings.');
        }
        return $requested;
    }

    /** Throws when a changed POS rate is not permitted, or needs a reason that was not given. */
    public static function assertRateChange(array $settings, bool $rateChanged, ?string $reason): void
    {
        if (!$rateChanged) {
            return;
        }
        $mode = (string)($settings['pos_rate_change_mode'] ?? 'reason_required');
        if ($mode === 'not_allowed') {
            throw new RuntimeException('Changing the rate at POS is not allowed in Shop Settings. Remove the changed rate and post again.');
        }
        if ($mode === 'reason_required' && trim((string)$reason) === '') {
            throw new RuntimeException('A rate was changed. Enter the reason for the rate change before posting.');
        }
    }

    /**
     * The POS time field is filled in (to the minute) when the screen opens, so a time up to
     * NOW_WINDOW_SECONDS old means "now": the sale gets the prices in force when it is posted,
     * including a price saved a moment ago. Older times are backdated.
     */
    public static function pricingTime(int $ts, ?int $now = null): int
    {
        $now = $now ?? time();
        return ($ts <= $now && $ts > $now - self::NOW_WINDOW_SECONDS) ? $now : $ts;
    }

    /** Throws when a backdated posting gets different prices from today's and Shop Settings does not permit that. */
    public static function assertBackdatedPrices(array $settings, ?string $reason): void
    {
        $mode = (string)($settings['pos_rate_change_mode'] ?? 'reason_required');
        if ($mode === 'not_allowed') {
            throw new RuntimeException('The transaction time is in the past and its prices differ from today\'s. Changing the rate at POS is not allowed in Shop Settings, so post it with the current time.');
        }
        if ($mode === 'reason_required' && trim((string)$reason) === '') {
            throw new RuntimeException('The transaction time is in the past and its prices differ from today\'s. Enter the reason before posting.');
        }
    }

    /** A configured, positive rate from one price list (or the customer's special rates) only, no fallback. */
    protected function own(string $list, string $rateType, ?int $cylinderTypeId, string $at): ?float
    {
        if ($list === self::CUSTOMER && $this->customerId === null) {
            return null;
        }
        $key = $list.'|'.($list === self::CUSTOMER ? $this->customerId : '').'|'.$rateType.'|'.(int)$cylinderTypeId.'|'.$at;
        if (!array_key_exists($key, $this->cache)) {
            $rate = $list === self::CUSTOMER
                ? $this->customerRates->effective($this->locationId, (int)$this->customerId, $rateType, $cylinderTypeId, $at)
                : $this->rates->effective($this->locationId, $list, $rateType, $cylinderTypeId, $at);
            $this->cache[$key] = ($rate !== null && $rate > 0) ? $rate : null;
        }
        return $this->cache[$key];
    }

    /** Gas price per KG within one list: its per-KG rate, else its full-cylinder price spread over the capacity. */
    protected function ownGasPerKg(string $list, array $type, string $at): ?float
    {
        $kg = $this->own($list, 'gas_per_kg', null, $at);
        if ($kg !== null) {
            return $kg;
        }
        $cylinder = $this->own($list, 'gas_per_cylinder', (int)$type['id'], $at);
        $capacity = (float)($type['capacity_kg'] ?? 0);
        return ($cylinder !== null && $capacity > 0) ? $cylinder / $capacity : null;
    }

    /** Gas price of one full cylinder within one list: its full-cylinder rate, else per-KG rate x capacity. */
    protected function ownGasPerCylinder(string $list, array $type, string $at): ?float
    {
        $cylinder = $this->own($list, 'gas_per_cylinder', (int)$type['id'], $at);
        if ($cylinder !== null) {
            return $cylinder;
        }
        $kg = $this->own($list, 'gas_per_kg', null, $at);
        $capacity = (float)($type['capacity_kg'] ?? 0);
        return ($kg !== null && $capacity > 0) ? $kg * $capacity : null;
    }

    /** Gas per KG inside a whole cylinder of this type: its full-cylinder price spread over the capacity, else the per-KG rate. */
    protected function ownCylinderGasPerKg(string $list, array $type, string $at): ?float
    {
        $cylinder = $this->own($list, 'gas_per_cylinder', (int)$type['id'], $at);
        $capacity = (float)($type['capacity_kg'] ?? 0);
        if ($cylinder !== null && $capacity > 0) {
            return $cylinder / $capacity;
        }
        return $this->own($list, 'gas_per_kg', null, $at);
    }

    /** Wholesale falls back to Retail when it has no price of its own. */
    protected function withFallback(string $list, callable $lookup): array
    {
        $rate = $lookup($list);
        if ($rate !== null) {
            return ['rate' => $rate, 'source' => $list];
        }
        if ($list === 'wholesale') {
            $rate = $lookup('retail');
            if ($rate !== null) {
                return ['rate' => $rate, 'source' => 'retail_fallback'];
            }
        }
        return ['rate' => null, 'source' => 'none'];
    }

    /** The customer's special rate when set, else the price list with its Retail fallback. */
    protected function withSpecial(string $list, callable $lookup): array
    {
        $special = $this->customerId !== null ? $lookup(self::CUSTOMER) : null;
        if ($special !== null) {
            return ['rate' => $special, 'source' => 'customer_special'];
        }
        return $this->withFallback($list, $lookup);
    }

    /** Gas price per KG: ['rate' => ?float, 'source' => customer_special|retail|wholesale|retail_fallback|none]. */
    public function gasPerKg(string $list, array $type, string $at): array
    {
        return $this->withSpecial($list, fn (string $l) => $this->ownGasPerKg($l, $type, $at));
    }

    /** Gas price of one full cylinder of this type. */
    public function gasPerCylinder(string $list, array $type, string $at): array
    {
        return $this->withSpecial($list, fn (string $l) => $this->ownGasPerCylinder($l, $type, $at));
    }

    /**
     * Gas per KG for gas sold inside a cylinder (Cylinder Sale, Security Deposit): a full cylinder
     * costs exactly the full-cylinder price and a part-filled one is pro-rated.
     */
    public function cylinderGasPerKg(string $list, array $type, string $at): array
    {
        return $this->withSpecial($list, fn (string $l) => $this->ownCylinderGasPerKg($l, $type, $at));
    }

    /** Empty-cylinder price: special rate, the list's rate (Wholesale falls back to Retail), else the Cylinder Type master price. */
    public function emptyCylinder(string $list, array $type, string $at): array
    {
        $result = $this->withSpecial($list, fn (string $l) => $this->own($l, 'empty_cylinder', (int)$type['id'], $at));
        if ($result['rate'] !== null) {
            return $result;
        }
        $master = (float)($type['empty_cylinder_price'] ?? 0);
        return ['rate' => $master > 0 ? $master : null, 'source' => $master > 0 ? 'cylinder_type' : 'none'];
    }

    /** All rates POS needs for one price list, keyed by cylinder type id. */
    public function priceBook(string $list, array $types, string $at): array
    {
        $book = [];
        foreach ($types as $type) {
            $book[(string)$type['id']] = [
                'gas_per_kg' => $this->gasPerKg($list, $type, $at),
                'gas_per_cylinder' => $this->gasPerCylinder($list, $type, $at),
                'cylinder_gas_per_kg' => $this->cylinderGasPerKg($list, $type, $at),
                'empty_cylinder' => $this->emptyCylinder($list, $type, $at),
            ];
        }
        return $book;
    }
}
