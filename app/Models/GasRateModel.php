<?php
namespace App\Models;
/**
 * Effective rate lookups for one shop (location) and price list.
 * The rate in force at a time is the latest row that has started; when that row was
 * stopped (effective_to reached) there is no rate in this list until a newer row starts.
 */
class GasRateModel extends RateCardModel
{
    protected function forKey(int $locationId, string $priceList, string $rateType, ?int $cylinderTypeId)
    {
        return $this->where('location_id',$locationId)->where('price_list',$priceList)->where('rate_type',$rateType)->where('cylinder_type_id',$cylinderTypeId);
    }

    public function effectiveRow(int $locationId, string $priceList, string $rateType, ?int $cylinderTypeId, ?string $asOf=null): ?array
    {
        $at=$asOf??date('Y-m-d H:i:s');
        $row=$this->forKey($locationId,$priceList,$rateType,$cylinderTypeId)->where('effective_from <=',$at)->orderBy('effective_from','DESC')->orderBy('id','DESC')->first();
        if(!$row || ($row['effective_to']!==null && $row['effective_to']<=$at)) return null;
        return $row;
    }

    public function effective(int $locationId, string $priceList, string $rateType, ?int $cylinderTypeId, ?string $asOf=null): ?float
    {
        $row=$this->effectiveRow($locationId,$priceList,$rateType,$cylinderTypeId,$asOf);
        return $row?(float)$row['rate_value']:null;
    }

    /** The next scheduled rate after $asOf, if any. */
    public function nextRow(int $locationId, string $priceList, string $rateType, ?int $cylinderTypeId, ?string $asOf=null): ?array
    {
        return $this->forKey($locationId,$priceList,$rateType,$cylinderTypeId)->where('effective_from >',$asOf??date('Y-m-d H:i:s'))->orderBy('effective_from','ASC')->orderBy('id','ASC')->first();
    }
}
