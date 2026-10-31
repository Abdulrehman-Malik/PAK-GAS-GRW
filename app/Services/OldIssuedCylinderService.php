<?php

namespace App\Services;

use Config\Database;
use RuntimeException;

/**
 * Cylinders customers already held before the system was used.
 *
 * An entry adds physical cylinders to the customer's custody and its outstanding amount to the customer's
 * opening balance. It touches no sale, cash, deposit or shop stock: the cylinders join shop stock when
 * they come back through the normal Cylinder Return. The cylinders are treated as full.
 */
class OldIssuedCylinderService
{
    public const SOURCE_TYPE = 'old_issue';
    public const MAX_QUANTITY = 500;

    protected $db;
    protected CylinderUnitService $cylinders;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->cylinders = new CylinderUnitService();
    }

    /** Checks one posted row and returns it with its amounts. Throws with the problem found. */
    public function validateRow(array $row, ?string $today = null): array
    {
        $date = trim((string)($row['issued_on'] ?? ''));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Enter a valid date.');
        if ($date > ($today ?? date('Y-m-d'))) throw new RuntimeException('The date cannot be in the future.');
        $customerId = (int)($row['customer_id'] ?? 0);
        if ($customerId <= 0) throw new RuntimeException('Select a customer.');
        $typeId = (int)($row['cylinder_type_id'] ?? 0);
        if ($typeId <= 0) throw new RuntimeException('Select a cylinder type.');
        $quantity = trim((string)($row['quantity'] ?? ''));
        if (!ctype_digit($quantity) || (int)$quantity < 1 || (int)$quantity > self::MAX_QUANTITY) throw new RuntimeException('Quantity must be a whole number from 1 to 500.');
        foreach (['rate_per_cylinder' => 'Rate', 'amount_received' => 'Received amount'] as $field => $label) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '' && (!is_numeric($value) || (float)$value < 0)) throw new RuntimeException($label . ' must be a number that is not negative.');
        }
        $rate = round((float)($row['rate_per_cylinder'] ?? 0), 2);
        $amount = round((int)$quantity * $rate, 2);
        $received = round((float)($row['amount_received'] ?? 0), 2);
        if ($received > $amount) throw new RuntimeException('Received amount cannot be more than the amount.');
        return [
            'issued_on' => $date, 'customer_id' => $customerId, 'cylinder_type_id' => $typeId, 'quantity' => (int)$quantity,
            'rate_per_cylinder' => $rate, 'amount' => $amount, 'amount_received' => $received, 'outstanding' => round($amount - $received, 2),
        ];
    }

    /** Saves all rows or none. Throws "Row N: ..." naming the first bad row. */
    public function save(array $rows, string $note, int $userId, int $locationId): array
    {
        $clean = [];
        foreach (array_values($rows) as $i => $row) {
            try {
                $clean[] = $this->validateRow($row);
            } catch (RuntimeException $e) {
                throw new RuntimeException('Row ' . ($i + 1) . ': ' . $e->getMessage());
            }
        }
        if (!$clean) throw new RuntimeException('Add at least one row.');
        $note = mb_substr(trim($note), 0, 255) ?: null;

        $this->db->transBegin();
        try {
            $cylinderCount = 0;
            $outstandingTotal = 0.0;
            foreach ($clean as $i => $row) {
                $customer = $this->db->query('SELECT id,is_active FROM customers WHERE id=? FOR UPDATE', [$row['customer_id']])->getRowArray();
                if (!$customer || !(int)$customer['is_active']) throw new RuntimeException('Row ' . ($i + 1) . ': Customer is unavailable.');
                $type = $this->db->table('cylinder_types')->where(['id' => $row['cylinder_type_id'], 'is_active' => 1])->get()->getRowArray();
                $capacity = (float)($type['capacity_kg'] ?? 0);
                if (!$type || $capacity <= 0) throw new RuntimeException('Row ' . ($i + 1) . ': Cylinder type is unavailable.');

                $this->db->table('old_issued_cylinders')->insert($row + ['location_id' => $locationId, 'status' => 'posted', 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
                $entryId = (int)$this->db->insertID();
                if ($entryId <= 0) throw new RuntimeException('Old issued cylinders could not be saved.');

                $unitIds = $this->cylinders->createUnits($locationId, (int)$type['id'], $row['quantity'], 'custody', $capacity, $userId, self::SOURCE_TYPE, $entryId);
                foreach ($unitIds as $unitId) {
                    $this->db->table('cylinder_units')->where('id', $unitId)->update(['custody_customer_id' => $row['customer_id']]);
                    $this->db->table('cylinder_custody')->insert([
                        'location_id' => $locationId, 'customer_id' => $row['customer_id'], 'cylinder_unit_id' => $unitId, 'status' => 'issued',
                        'deposit_amount' => 0, 'issued_gas_weight_kg' => $capacity, 'issued_gas_rate' => round($row['rate_per_cylinder'] / $capacity, 4),
                        'issued_condition' => 'filled', 'issued_at' => $row['issued_on'] . ' 00:00:00', 'created_by' => $userId, 'notes' => $note,
                    ]);
                }
                if ($row['outstanding'] > 0) {
                    $this->db->table('customers')->where('id', $row['customer_id'])->set('opening_balance', 'opening_balance+' . number_format($row['outstanding'], 2, '.', ''), false)->update();
                }
                AuditService::log('CREATE', 'old_issued_cylinder', $entryId, null, $row + ['note' => $note], $userId, $locationId);
                $cylinderCount += $row['quantity'];
                $outstandingTotal += $row['outstanding'];
            }
            if (!$this->db->transStatus()) throw new RuntimeException('Old issued cylinders could not be saved.');
            $this->db->transCommit();
            return ['rows' => count($clean), 'cylinders' => $cylinderCount, 'outstanding' => round($outstandingTotal, 2)];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /** Reverses an entry made by mistake. Blocked once any of its cylinders was returned or used in a sale. */
    public function void(int $entryId, string $reason, int $userId, int $locationId): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') throw new RuntimeException('Enter the reason for voiding this entry.');

        $this->db->transBegin();
        try {
            $entry = $this->db->query('SELECT * FROM old_issued_cylinders WHERE id=? AND location_id=? FOR UPDATE', [$entryId, $locationId])->getRowArray();
            if (!$entry) throw new RuntimeException('Entry not found.');
            if ($entry['status'] !== 'posted') throw new RuntimeException('This entry is already voided.');
            $this->db->query('SELECT id FROM customers WHERE id=? FOR UPDATE', [$entry['customer_id']]);

            $units = $this->db->query("SELECT id FROM cylinder_units WHERE source_type=? AND source_id=? AND location_id=? FOR UPDATE", [self::SOURCE_TYPE, $entryId, $locationId])->getResultArray();
            $unitIds = array_map('intval', array_column($units, 'id'));
            if (!$unitIds) throw new RuntimeException('Entry not found.');
            $stillIssued = $this->db->table('cylinder_custody')->whereIn('cylinder_unit_id', $unitIds)->where('status', 'issued')->countAllResults();
            $everCustody = $this->db->table('cylinder_custody')->whereIn('cylinder_unit_id', $unitIds)->countAllResults();
            if ($stillIssued !== count($unitIds) || $everCustody !== count($unitIds)) throw new RuntimeException('This entry cannot be voided because one or more of its cylinders has been returned.');
            if ($this->db->table('sale_items')->whereIn('customer_cylinder_unit_id', $unitIds)->countAllResults() > 0) throw new RuntimeException('This entry cannot be voided because one or more of its cylinders has been used in a sale.');

            $now = date('Y-m-d H:i:s');
            $this->db->table('cylinder_custody')->whereIn('cylinder_unit_id', $unitIds)->update(['status' => 'voided', 'updated_by' => $userId]);
            $this->db->table('cylinder_units')->whereIn('id', $unitIds)->update(['status' => 'voided', 'custody_customer_id' => null, 'gas_weight_kg' => 0]);
            if ((float)$entry['outstanding'] > 0) {
                $this->db->table('customers')->where('id', $entry['customer_id'])->set('opening_balance', 'GREATEST(0,opening_balance-' . number_format((float)$entry['outstanding'], 2, '.', '') . ')', false)->update();
            }
            $this->db->table('old_issued_cylinders')->where('id', $entryId)->update(['status' => 'voided', 'void_reason' => $reason, 'voided_by' => $userId, 'voided_at' => $now]);
            AuditService::log('VOID', 'old_issued_cylinder', $entryId, $entry, ['status' => 'voided', 'void_reason' => $reason], $userId, $locationId);
            if (!$this->db->transStatus()) throw new RuntimeException('This entry could not be voided.');
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function recent(int $locationId, int $limit = 100): array
    {
        return $this->db->table('old_issued_cylinders e')
            ->select('e.*,c.name customer_name,ct.code cylinder_code,ct.name cylinder_name')
            ->join('customers c', 'c.id=e.customer_id')->join('cylinder_types ct', 'ct.id=e.cylinder_type_id')
            ->where('e.location_id', $locationId)->orderBy('e.id', 'DESC')->limit($limit)->get()->getResultArray();
    }
}
