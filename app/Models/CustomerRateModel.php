<?php
namespace App\Models;

use CodeIgniter\Model;

/**
 * Customer special rates for one shop: a customer's own price for an item, overriding their price list.
 * Same rules as rate_cards: the rate in force is the latest started row, unless it was stopped.
 */
class CustomerRateModel extends Model
{
    protected $table='customer_rates';
    protected $primaryKey='id';
    protected $returnType='array';
    protected $useTimestamps=false;
    protected $allowedFields=['location_id','customer_id','rate_type','cylinder_type_id','rate_value','effective_from','effective_to','created_by','created_at'];

    protected function forKey(int $locationId, int $customerId, string $rateType, ?int $cylinderTypeId)
    {
        return $this->where('location_id',$locationId)->where('customer_id',$customerId)->where('rate_type',$rateType)->where('cylinder_type_id',$cylinderTypeId);
    }

    public function effectiveRow(int $locationId, int $customerId, string $rateType, ?int $cylinderTypeId, ?string $asOf=null): ?array
    {
        $at=$asOf??date('Y-m-d H:i:s');
        $row=$this->forKey($locationId,$customerId,$rateType,$cylinderTypeId)->where('effective_from <=',$at)->orderBy('effective_from','DESC')->orderBy('id','DESC')->first();
        if(!$row || ($row['effective_to']!==null && $row['effective_to']<=$at)) return null;
        return $row;
    }

    public function effective(int $locationId, int $customerId, string $rateType, ?int $cylinderTypeId, ?string $asOf=null): ?float
    {
        $row=$this->effectiveRow($locationId,$customerId,$rateType,$cylinderTypeId,$asOf);
        return $row?(float)$row['rate_value']:null;
    }

    public function nextRow(int $locationId, int $customerId, string $rateType, ?int $cylinderTypeId, ?string $asOf=null): ?array
    {
        return $this->forKey($locationId,$customerId,$rateType,$cylinderTypeId)->where('effective_from >',$asOf??date('Y-m-d H:i:s'))->orderBy('effective_from','ASC')->orderBy('id','ASC')->first();
    }

    /** Every special rate row of one customer in this shop, newest first. */
    public function history(int $locationId, int $customerId): array
    {
        return $this->select('customer_rates.*, cylinder_types.code AS cylinder_code, cylinder_types.name AS cylinder_name, users.full_name AS created_by_name')
            ->join('cylinder_types','cylinder_types.id=customer_rates.cylinder_type_id','left')
            ->join('users','users.id=customer_rates.created_by','left')
            ->where('customer_rates.location_id',$locationId)->where('customer_rates.customer_id',$customerId)
            ->orderBy('effective_from','DESC')->orderBy('customer_rates.id','DESC')->findAll();
    }

    /** Customers of this shop with special prices in force or scheduled: [customer_id => number of items]. */
    public function activeCounts(int $locationId, string $now): array
    {
        $rows=$this->db->table('customer_rates')->select('customer_id,rate_type,cylinder_type_id,effective_from,effective_to,id')
            ->where('location_id',$locationId)->orderBy('effective_from','DESC')->orderBy('id','DESC')->get()->getResultArray();
        $items=[];
        foreach($rows as $row){
            $key=$row['customer_id'].'|'.$row['rate_type'].'|'.(int)$row['cylinder_type_id'];
            if(isset($items[$key])) continue;
            if($row['effective_from']>$now){ $items[$key]=(int)$row['customer_id']; continue; } // scheduled
            // The latest started row decides: it counts unless it was stopped.
            $items[$key]=($row['effective_to']!==null && $row['effective_to']<=$now)?0:(int)$row['customer_id'];
        }
        $counts=[];
        foreach($items as $customerId) if($customerId) $counts[$customerId]=($counts[$customerId]??0)+1;
        return $counts;
    }
}
