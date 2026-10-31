<?php

namespace App\Services;

use Config\Database;
use RuntimeException;

/**
 * Converts shop cylinders of one type between empty and full.
 * Empty to full fills to capacity. Full to empty removes the gas in the cylinder, needs a reason, and takes
 * the cylinders holding the most gas first, so part-used cylinders are only emptied after the full ones.
 * Only changes stock: no purchase, sale, cost or cash is recorded.
 */
class CylinderFillService
{
    public const SOURCE_TYPE = 'cylinder_fill';
    public const EMPTY_TO_FULL = 'empty_to_full';
    public const FULL_TO_EMPTY = 'full_to_empty';

    protected $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** Whole cylinders only: returns the quantity or throws. */
    public static function parseQuantity(string $quantity): int
    {
        $quantity = trim($quantity);
        if (!ctype_digit($quantity) || (int)$quantity < 1) throw new RuntimeException('Enter the number of cylinders as a whole number of at least 1.');
        return (int)$quantity;
    }

    /** Empty and full shop cylinders per type, keyed by type id. */
    public function stockByType(int $locationId): array
    {
        $rows = $this->db->table('cylinder_units')->select('cylinder_type_id,status,COUNT(*) n')
            ->where('location_id', $locationId)->whereIn('status', ['empty', 'filled'])
            ->groupBy(['cylinder_type_id', 'status'])->get()->getResultArray();
        $stock = [];
        foreach ($rows as $row) {
            $stock[(int)$row['cylinder_type_id']][(string)$row['status']] = (int)$row['n'];
        }
        return $stock;
    }

    public function convert(string $direction, int $typeId, string $quantity, string $notes, int $userId, int $locationId): array
    {
        if (!in_array($direction, [self::EMPTY_TO_FULL, self::FULL_TO_EMPTY], true)) throw new RuntimeException('Select what to convert.');
        $toFull = $direction === self::EMPTY_TO_FULL;
        $count = self::parseQuantity($quantity);
        $type = $this->db->table('cylinder_types')->where(['id' => $typeId, 'is_active' => 1])->get()->getRowArray();
        $capacity = (float)($type['capacity_kg'] ?? 0);
        if (!$type || $capacity <= 0) throw new RuntimeException('Select a cylinder type.');
        $notes = mb_substr(trim($notes), 0, 255) ?: null;
        if (!$toFull && $notes === null) throw new RuntimeException('Enter the reason for emptying these cylinders.');

        $this->db->transBegin();
        try {
            // Locks every candidate of this type, so two users cannot convert the same cylinders.
            $sources = $this->db->query(
                $toFull
                    ? "SELECT id,gas_weight_kg FROM cylinder_units WHERE location_id=? AND cylinder_type_id=? AND status='empty' ORDER BY id FOR UPDATE"
                    : "SELECT id,gas_weight_kg FROM cylinder_units WHERE location_id=? AND cylinder_type_id=? AND status='filled' ORDER BY gas_weight_kg DESC,id DESC FOR UPDATE",
                [$locationId, $typeId]
            )->getResultArray();
            $available = count($sources);
            if ($available === 0) throw new RuntimeException($toFull ? 'No empty cylinders are available for this cylinder type.' : 'No full cylinders are available for this cylinder type.');
            if ($count > $available && $toFull) throw new RuntimeException('Only ' . $available . ' empty cylinder(s) are available.');
            if ($count > $available) throw new RuntimeException('Only ' . $available . ' full cylinder(s) are available.');
            $emptyBefore = $this->db->table('cylinder_units')->where(['location_id' => $locationId, 'cylinder_type_id' => $typeId, 'status' => 'empty'])->countAllResults();

            $chosen = array_slice($sources, 0, $count);
            $totalGas = $toFull ? round($capacity * $count, 3) : round(array_sum(array_map(static fn($u) => (float)$u['gas_weight_kg'], $chosen)), 3);
            $now = date('Y-m-d H:i:s');
            $this->db->table('cylinder_fills')->insert([
                'location_id' => $locationId, 'direction' => $direction, 'cylinder_type_id' => $typeId, 'quantity' => $count, 'total_gas_kg' => $totalGas,
                'empty_before' => $emptyBefore, 'empty_after' => $emptyBefore + ($toFull ? -$count : $count),
                'notes' => $notes, 'created_by' => $userId, 'created_at' => $now,
            ]);
            $fillId = (int)$this->db->insertID();
            if ($fillId <= 0) throw new RuntimeException('Cylinders could not be converted.');

            foreach ($chosen as $unit) {
                $unitId = (int)$unit['id'];
                $base = ['location_id' => $locationId, 'cylinder_type_id' => $typeId, 'movement_at' => $now, 'source_type' => self::SOURCE_TYPE, 'source_id' => $fillId, 'cylinder_unit_id' => $unitId, 'created_by' => $userId];
                if ($toFull) {
                    $this->db->table('cylinder_units')->where('id', $unitId)->update(['status' => 'filled', 'gas_weight_kg' => $capacity]);
                    $this->db->table('inventory_movements')->insert($base + ['inventory_type' => 'empty_cylinder', 'quantity' => 1, 'direction' => 'out', 'notes' => 'Empty cylinder converted to full']);
                    $this->db->table('inventory_movements')->insert($base + ['inventory_type' => 'filled_cylinder', 'quantity' => 1, 'direction' => 'in', 'notes' => 'Empty cylinder converted to full']);
                    $this->db->table('inventory_movements')->insert(array_merge($base, ['inventory_type' => 'gas_kg', 'cylinder_type_id' => null, 'quantity' => $capacity, 'direction' => 'in', 'notes' => 'Gas added when converting an empty cylinder to full']));
                } else {
                    $this->db->table('cylinder_units')->where('id', $unitId)->update(['status' => 'empty', 'gas_weight_kg' => 0, 'filled_purchase_id' => null]);
                    $this->db->table('inventory_movements')->insert($base + ['inventory_type' => 'filled_cylinder', 'quantity' => 1, 'direction' => 'out', 'notes' => 'Full cylinder converted to empty']);
                    $this->db->table('inventory_movements')->insert($base + ['inventory_type' => 'empty_cylinder', 'quantity' => 1, 'direction' => 'in', 'notes' => 'Full cylinder converted to empty']);
                    if ((float)$unit['gas_weight_kg'] > 0) {
                        $this->db->table('inventory_movements')->insert(array_merge($base, ['inventory_type' => 'gas_kg', 'cylinder_type_id' => null, 'quantity' => (float)$unit['gas_weight_kg'], 'direction' => 'out', 'notes' => 'Gas removed when converting a full cylinder to empty']));
                    }
                }
            }
            AuditService::log('CREATE', 'cylinder_fill', $fillId, null, ['direction' => $direction, 'cylinder_type_id' => $typeId, 'quantity' => $count, 'total_gas_kg' => $totalGas, 'notes' => $notes], $userId, $locationId);
            if (!$this->db->transStatus()) throw new RuntimeException('Cylinders could not be converted.');
            $this->db->transCommit();
            return ['quantity' => $count, 'direction' => $direction, 'total_gas' => $totalGas];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function history(int $locationId, int $limit = 200): array
    {
        return $this->db->table('cylinder_fills f')
            ->select('f.*,ct.code cylinder_code,ct.name cylinder_name,u.full_name created_by_name')
            ->join('cylinder_types ct', 'ct.id=f.cylinder_type_id')->join('users u', 'u.id=f.created_by', 'left')
            ->where('f.location_id', $locationId)->orderBy('f.id', 'DESC')->limit($limit)->get()->getResultArray();
    }
}
