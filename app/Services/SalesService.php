<?php

namespace App\Services;

use App\Models\CylinderTypeModel;
use App\Models\CustomerModel;
use App\Models\ShopSettingsModel;
use Config\Database;
use RuntimeException;

class SalesService
{
    protected $db;
    protected $types;
    protected $customers;
    protected ?RateResolver $resolver = null;
    protected string $priceList = 'retail';
    protected ?string $rateChangeReason = null;
    protected bool $backdated = false;
    protected bool $backdatedPriceDiffers = false;
    // A POS page's time can be a few minutes off; beyond these, a time counts as future / backdated.
    protected const FUTURE_GRACE_SECONDS = 300;
    protected const BACKDATE_GRACE_SECONDS = RateResolver::NOW_WINDOW_SECONDS;
    protected $cash;
    protected $cylinders;
    protected array $inventoryLocks = [];
    protected array $creditLocks = [];
    protected ?int $currentLocationId = null;

    public function __construct()
    {
        $this->currentLocationId=(int)session()->get('location_id');
        $this->db=Database::connect();
        $this->types=new CylinderTypeModel();
        $this->customers=new CustomerModel();
        $this->cash=new CashService();
        $this->cylinders=new CylinderUnitService();
    }

    protected function nextSaleNo(string $transactionAt,int $locationId): string
    {
        $date=date('Ymd',strtotime($transactionAt));
        $prefix='S'.$date.'-';
        $row=$this->db->query(
            "SELECT MAX(CAST(SUBSTRING(sale_no,11) AS UNSIGNED)) AS max_seq
             FROM sales
             WHERE location_id=? AND sale_no LIKE ?",
            [$locationId,$prefix.'%']
        )->getRowArray();
        $next=((int)($row['max_seq']??0))+1;
        return $prefix.str_pad((string)$next,5,'0',STR_PAD_LEFT);
    }

    public function post(array $payload,int $userId,int $locationId): array
    {
        $headerType=(string)($payload['transaction_type']??'');
        if(!in_array($headerType,['gas_sale','cylinder_sale','security_deposit','cylinder_return'],true)) throw new RuntimeException('Unsupported POS transaction type.');
        return $this->postHeaderTransaction($payload,$userId,$locationId,$headerType);
    }

    protected function postHeaderTransaction(array $payload,int $userId,int $locationId,string $headerType): array
    {
        $customerId=!empty($payload['customer_id'])?(int)$payload['customer_id']:null;
        $transactionAt=str_replace('T',' ',trim((string)($payload['transaction_at']??date('Y-m-d H:i:s'))));
        $transactionTs=strtotime($transactionAt);
        if($transactionTs===false) throw new RuntimeException('Invalid transaction date/time.');
        if($transactionTs>time()+self::FUTURE_GRACE_SECONDS) throw new RuntimeException('Transaction date/time cannot be in the future.');
        $transactionTs=RateResolver::pricingTime($transactionTs);
        $transactionAt=date('Y-m-d H:i:s',$transactionTs);
        $this->backdated=$transactionTs<time()-self::BACKDATE_GRACE_SECONDS;
        $this->backdatedPriceDiffers=false;
        $notes=trim((string)($payload['notes']??''))?:null;
        $lines=is_array($payload['lines']??null)?$payload['lines']:[];
        $payments=is_array($payload['payments']??null)?$payload['payments']:[];
        $deposit=max(0,(float)($payload['security_deposit_amount']??0));
        $custodyUnitIds=array_values(array_unique(array_map('intval',is_array($payload['custody_unit_ids']??null)?$payload['custody_unit_ids']:[])));
        $returnUnitIds=array_values(array_unique(array_map('intval',is_array($payload['return_unit_ids']??null)?$payload['return_unit_ids']:[])));

        $customer=$customerId?$this->customers->find($customerId):null;
        if($customerId && (!$customer || !(int)$customer['is_active'])) throw new RuntimeException('Customer is invalid or inactive.');
        if($headerType==='security_deposit' || $headerType==='cylinder_return'){
            if(!$customerId) throw new RuntimeException('A named customer is required for cylinder custody transactions.');
        }
        if($headerType!=='security_deposit' && $deposit>0) throw new RuntimeException('Security Deposit Amount is only allowed on a Security Deposit transaction.');

        // One pricing context per posting: this shop's rates and the price list this invoice uses.
        // Named customers get their special rates first (when enabled); walk-in never does.
        $this->resolver=(new RateResolver($locationId))->forCustomer($customer);
        $this->priceList=RateResolver::pickPriceList($this->resolver->settings(),$customer,isset($payload['price_list'])?(string)$payload['price_list']:null);
        $this->rateChangeReason=trim((string)($payload['rate_change_reason']??''))?:null;

        if($headerType==='gas_sale') return $this->postGasSaleHeader($payload,$userId,$locationId,$customerId,$transactionAt,$notes,$lines,$payments);
        if($headerType==='cylinder_sale') return $this->postCylinderSaleHeader($payload,$userId,$locationId,$customerId,$transactionAt,$notes,$lines,$payments);
        if($headerType==='security_deposit') return $this->postSecurityDepositHeader($payload,$userId,$locationId,$customerId,$transactionAt,$notes,$payments,$custodyUnitIds,$deposit);
        return $this->postCylinderReturnHeader($payload,$userId,$locationId,$customerId,$transactionAt,$notes,$returnUnitIds);
    }

    /**
     * Price-list rate at the transaction time. A backdated posting that gets a different price
     * than today's is treated like a rate change, so backdating cannot pick an old price silently.
     */
    protected function standardPrice(string $method,array $type,string $at): array
    {
        $price=$this->resolver->$method($this->priceList,$type,$at);
        if($this->backdated && !$this->backdatedPriceDiffers){
            $now=$this->resolver->$method($this->priceList,$type,date('Y-m-d H:i:s'));
            $this->backdatedPriceDiffers=($price['rate']===null)!==($now['rate']===null)
                || ($price['rate']!==null && abs($price['rate']-$now['rate'])>0.00001);
        }
        return $price;
    }

    /** Checks changed POS rates (and backdated prices) against Shop Settings; returns the reason to store, if any. */
    /**
     * SQL condition for sales revenue, used by the Dashboard and Reports: Gas Sale, Cylinder Sale
     * and the gas charged on a Security Deposit (its total_amount excludes the deposit, which is a
     * refundable hold). Cylinder Return and deposit-only issues (nothing charged) are not sales.
     */
    public static function revenueSaleCondition(string $alias = ''): string
    {
        $c = $alias !== '' ? $alias.'.' : '';
        return "({$c}transaction_type NOT IN ('security_deposit','cylinder_return') OR ({$c}transaction_type='security_deposit' AND {$c}total_amount>0))";
    }

    protected function rateChangeReasonFor(bool $rateChanged): ?string
    {
        if($this->backdatedPriceDiffers) RateResolver::assertBackdatedPrices($this->resolver->settings(),$this->rateChangeReason);
        RateResolver::assertRateChange($this->resolver->settings(),$rateChanged,$this->rateChangeReason);
        return ($rateChanged||$this->backdatedPriceDiffers)?$this->rateChangeReason:null;
    }

    protected function postGasSaleHeader(array $payload,int $userId,int $locationId,?int $customerId,string $transactionAt,?string $notes,array $lines,array $payments): array
    {
        if(!$lines) throw new RuntimeException('At least one gas line is required.');
        if(!$payments) throw new RuntimeException('At least one payment is required.');
        $shopSettings=(new ShopSettingsModel())->forLocation($locationId);
        $allowSourceSelection=(int)($shopSettings['allow_pos_source_cylinder_selection']??0)===1;
        // Cylinder-count entry allocates gas across available filled cylinders by stock order.
        if ((string)($lines[0]['gas_entry_mode']??'') === 'cylinders') $allowSourceSelection=false;

        $prepared=[];$subtotal=0;$totalKg=0;$customRate=false;
        foreach($lines as $i=>$line){
            $n=$i+1;
            $typeId=isset($line['cylinder_type_id'])&&$line['cylinder_type_id']!==''?(int)$line['cylinder_type_id']:0;
            $sourceId=isset($line['source_cylinder_unit_id'])&&$line['source_cylinder_unit_id']!==''?(int)$line['source_cylinder_unit_id']:0;
            $qty=(float)($line['quantity']??0);
            $entryMode=(string)($line['gas_entry_mode']??'quantity');
            $cylinderQty=(float)($line['cylinder_quantity']??0);
            $enteredAmount=trim((string)($line['entered_amount']??''))===''?null:(float)$line['entered_amount'];
            $gasRateInput=trim((string)($line['gas_rate']??''))===''?null:(float)$line['gas_rate'];
            $targetId=isset($line['customer_cylinder_unit_id'])&&$line['customer_cylinder_unit_id']!==''?(int)$line['customer_cylinder_unit_id']:null;

            if(!$typeId) throw new RuntimeException('Cylinder type is required on gas line '.$n.'.');
            if($allowSourceSelection && !$sourceId) throw new RuntimeException('Source filled cylinder is required on gas line '.$n.'.');
            if(!$allowSourceSelection) $sourceId=0;
            $type=$this->types->find($typeId);
            if(!$type||!(int)$type['is_active']) throw new RuntimeException('Invalid or inactive cylinder type on gas line '.$n.'.');
            if($entryMode==='cylinders'){
                if($cylinderQty<=0 || floor($cylinderQty)!==$cylinderQty) throw new RuntimeException('Cylinder quantity on gas line '.$n.' must be a positive whole number.');
                $capacity=(float)($type['capacity_kg']??0);
                if($capacity<=0) throw new RuntimeException('A positive cylinder capacity is required for '.$type['name'].'.');
                $qty=$cylinderQty*$capacity;
                $enteredAmount=null;
                // Cylinder-count entry uses normal gas/kg pricing and stock allocation.
                $sourceId=0;
            }
            if($enteredAmount!==null && $enteredAmount<=0) throw new RuntimeException('Entered amount on gas line '.$n.' must be greater than zero.');
            if($gasRateInput!==null&&$gasRateInput<0) throw new RuntimeException('Gas rate cannot be negative on line '.$n.'.');

            if($entryMode==='cylinders'){
                // Cylinder-count lines are priced per full cylinder; the entered rate is the
                // price per cylinder. Gas stock is still consumed by KG.
                $capacity=(float)$type['capacity_kg'];
                $standardCylinder=$this->standardPrice('gasPerCylinder',$type,$transactionAt);
                $standardCyl=$standardCylinder['rate'];
                $cylRate=$gasRateInput??$standardCyl;
                if($cylRate===null) throw new RuntimeException('No full-cylinder or per-KG gas rate is configured for '.$type['name'].'. Set it in LPG Rates or enter a price per cylinder.');
                if($cylRate<=0) throw new RuntimeException('Cylinder rate must be greater than zero on line '.$n.'.');
                $custom=$gasRateInput!==null && ($standardCyl===null || abs($cylRate-$standardCyl)>0.00001);
                $customRate=$customRate||$custom;
                $lineTotal=$cylinderQty*$cylRate;
                // applied_rate stays a per-KG equivalent so quantity x rate still reconciles with the line total.
                $prepared[]=[
                    'line_no'=>$n,'type_id'=>$typeId,'source_id'=>0,'quantity'=>$qty,'gas_weight_kg'=>$qty,
                    'gas_rate'=>$cylRate/$capacity,'standard_rate'=>$standardCyl!==null?$standardCyl/$capacity:null,'custom_rate_flag'=>$custom?1:0,
                    'rate_source'=>$custom?'manual':$standardCylinder['source'],
                    'target_id'=>$targetId,'line_total'=>$lineTotal,
                    'note_extra'=>' | '.(int)$cylinderQty.' cylinder(s) x Rs. '.number_format($cylRate,2).' per cylinder'
                ];
                $subtotal+=$lineTotal;$totalKg+=$qty;
                continue;
            }

            $standard=$this->standardPrice('gasPerKg',$type,$transactionAt);
            $standardRate=$standard['rate'];

            // Amount mode uses the entered gas rate when supplied; otherwise it falls
            // back to the configured rate. The server calculates KG from that rate so
            // stock, sale items, receipts, and customer balances use the same values.
            if($enteredAmount!==null){
                $gasRate=$gasRateInput??$standardRate;
                if($gasRate===null || $gasRate<=0){
                    throw new RuntimeException($standardRate===null
                        ? 'Enter a positive Gas Rate for Amount entry or configure an effective rate in Gas Rate Settings.'
                        : 'Gas rate must be greater than zero on line '.$n.'.');
                }
                $qty=$enteredAmount/$gasRate;
                if($qty<=0) throw new RuntimeException('Calculated gas quantity on line '.$n.' must be greater than zero.');
                $lineTotal=$enteredAmount;
            }else{
                if($qty<=0) throw new RuntimeException('Gas quantity on line '.$n.' must be greater than zero.');
                $gasRate=$gasRateInput??$standardRate;
                if($gasRate===null || $gasRate<=0){
                    throw new RuntimeException($standardRate===null
                        ? 'No effective gas/kg rate exists. Enter a positive Gas Rate for this line or configure an effective rate in Gas Rate Settings.'
                        : 'Gas rate must be greater than zero on line '.$n.'.');
                }
                $lineTotal=$qty*$gasRate;
            }
            $custom=$standardRate===null
                ? ($gasRateInput!==null && $gasRate>0)
                : ($gasRateInput!==null && abs($gasRate-$standardRate)>0.00001);
            $customRate=$customRate||$custom;

            $prepared[]=[
                'line_no'=>$n,'type_id'=>$typeId,'source_id'=>$sourceId,'quantity'=>$qty,'gas_weight_kg'=>$qty,
                'gas_rate'=>$gasRate,'standard_rate'=>$standardRate,'custom_rate_flag'=>$custom?1:0,
                'rate_source'=>$custom?'manual':$standard['source'],
                'target_id'=>$targetId,'line_total'=>$lineTotal
            ];
            $subtotal+=$lineTotal;$totalKg+=$qty;
        }
        $rateChangeReason=$this->rateChangeReasonFor($customRate);
        $customRate=$customRate||$this->backdatedPriceDiffers;

        $discount=max(0,(float)($payload['discount_amount']??0));
        if($discount>$subtotal) throw new RuntimeException('Discount cannot exceed subtotal.');
        $total=$subtotal-$discount;

        $this->db->transBegin();
        try{
            if($customerId){
                $locked=$this->db->query("SELECT * FROM customers WHERE id=? FOR UPDATE",[$customerId])->getRowArray();
                if(!$locked||!(int)$locked['is_active']) throw new RuntimeException('Customer is unavailable.');
            }

            $paymentPlan=$this->prepareSalePaymentPlan($payments,$customerId,$total);

            $lockKeys=array_map(static fn($row)=>['type'=>'filled_cylinder','cylinder_type_id'=>(int)$row['type_id']],$prepared);
            $this->acquireInventoryLocks($locationId,$lockKeys);

            $typeSeen=[];
            foreach($prepared as $row){
                if(isset($typeSeen[$row['type_id']])) throw new RuntimeException('Cylinder type cannot be used on multiple gas sale lines. Combine the quantity into one line.');
                $typeSeen[$row['type_id']]=true;
            }

            $sources=[];
            $sourceIds=$allowSourceSelection?array_values(array_unique(array_map(static fn($row)=>(int)$row['source_id'],$prepared))):[];
            if($allowSourceSelection){
                if(!$sourceIds) throw new RuntimeException('Source filled cylinder is required.');
                $placeholders=implode(',',array_fill(0,count($sourceIds),'?'));
                $sourceRows=$this->db->query(
                    "SELECT cu.*,ct.code cylinder_code,ct.name cylinder_name,ct.capacity_kg
                     FROM cylinder_units cu JOIN cylinder_types ct ON ct.id=cu.cylinder_type_id
                     WHERE cu.location_id=? AND cu.status='filled' AND cu.id IN ($placeholders) FOR UPDATE",
                    array_merge([$locationId],$sourceIds)
                )->getResultArray();
                foreach($sourceRows as $row)$sources[(int)$row['id']]=$row;
                $requestedBySource=[];
                foreach($prepared as $row){
                    $source=$sources[$row['source_id']]??null;
                    if(!$source) throw new RuntimeException('Selected source cylinder on gas line '.$row['line_no'].' is no longer available.');
                    if((int)$source['cylinder_type_id']!==$row['type_id']) throw new RuntimeException('Gas line '.$row['line_no'].' cylinder type does not match the selected source cylinder.');
                    if(isset($requestedBySource[$row['source_id']])) throw new RuntimeException('The same source filled cylinder cannot be selected on multiple gas sale lines.');
                    $requestedBySource[$row['source_id']]=(float)$row['quantity'];
                    if($requestedBySource[$row['source_id']]>(float)$source['gas_weight_kg']+0.00001) throw new RuntimeException('Gas line '.$row['line_no'].' exceeds selected cylinder '.$source['unit_code'].' stock. Available: '.number_format((float)$source['gas_weight_kg'],2).' KG.');
                }
            }

            $saleNo=$this->nextSaleNo($transactionAt,$locationId);
            $this->db->table('sales')->insert([
                'sale_no'=>$saleNo,'location_id'=>$locationId,'customer_id'=>$customerId,'transaction_type'=>'gas_sale',
                'status'=>'posted','transaction_at'=>$transactionAt,'total_kg'=>$totalKg,'subtotal'=>$subtotal,
                'discount_amount'=>$discount,'total_amount'=>$total,'security_deposit_amount'=>0,
                'security_deposit_refund_amount'=>0,'credit_amount'=>$paymentPlan['credit_amount'],
                'previous_os_balance'=>$paymentPlan['previous_os'],'receipt_amount'=>round($paymentPlan['payment_total'],2),
                'net_receivable_amount'=>$paymentPlan['net_receivable'],'os_balance'=>$paymentPlan['remaining_os'],
                'custom_rate_flag'=>$customRate?1:0,'price_list'=>$this->priceList,'rate_change_reason'=>$rateChangeReason,
                'notes'=>$notes,'created_by'=>$userId
            ]);
            $saleId=(int)$this->db->insertID();

            foreach($prepared as $row){
                if($row['target_id']){
                    if(!$customerId) throw new RuntimeException('Select a named customer before choosing a customer custody cylinder.');
                    $target=$this->db->query(
                        "SELECT cu.*,ct.capacity_kg
                         FROM cylinder_units cu
                         JOIN cylinder_types ct ON ct.id=cu.cylinder_type_id
                         JOIN cylinder_custody cc ON cc.cylinder_unit_id=cu.id AND cc.status='issued' AND cc.customer_id=?
                         WHERE cu.id=? AND cu.location_id=? AND cu.status='custody'
                         FOR UPDATE",
                        [$customerId,$row['target_id'],$locationId]
                    )->getRowArray();
                    if(!$target) throw new RuntimeException('Selected customer custody cylinder is not available.');
                    if((int)$target['cylinder_type_id']!==$row['type_id']) throw new RuntimeException('Gas refill cylinder type must match the selected customer custody cylinder type.');
                    if((float)$target['gas_weight_kg']+(float)$row['quantity']>(float)$target['capacity_kg']+0.00001) throw new RuntimeException('Gas quantity exceeds the remaining capacity of the selected customer cylinder.');
                }

                $sourceLabel=$allowSourceSelection?'Source cylinder selected by user':'Source cylinders auto-allocated by sequence';
                $this->db->table('sale_items')->insert([
                    'sale_id'=>$saleId,'line_no'=>$row['line_no'],'line_type'=>'refill_kg','cylinder_type_id'=>$row['type_id'],
                    'customer_cylinder_unit_id'=>$row['target_id'],'quantity'=>$row['quantity'],'gas_weight_kg'=>$row['gas_weight_kg'],
                    'applied_rate'=>$row['gas_rate'],'standard_rate'=>$row['standard_rate'],'custom_rate_flag'=>$row['custom_rate_flag'],
                    'rate_source'=>$row['rate_source'],'empty_cylinder_received'=>0,'line_discount'=>0,'line_total'=>$row['line_total'],'notes'=>($row['target_id']?'Refilled customer custody cylinder':'Gas sale / refill').' | '.$sourceLabel.($row['note_extra']??'')
                ]);
                $saleItemId=(int)$this->db->insertID();

                $remaining=(float)$row['quantity'];
                $allocationUnits=[];
                if($allowSourceSelection){
                    $source=$sources[$row['source_id']]??null;
                    if(!$source) throw new RuntimeException('Selected source cylinder on gas line '.$row['line_no'].' is no longer available.');
                    if((float)$source['gas_weight_kg']+0.00001<$remaining) throw new RuntimeException('Selected source cylinder '.$source['unit_code'].' has only '.number_format((float)$source['gas_weight_kg'],2).' KG available.');
                    $allocationUnits=[['unit'=>$source,'used'=>$remaining]];
                }else{
                    $autoUnits=$this->db->query(
                        "SELECT cu.*,ct.code cylinder_code,ct.name cylinder_name,ct.capacity_kg
                         FROM cylinder_units cu JOIN cylinder_types ct ON ct.id=cu.cylinder_type_id
                         WHERE cu.location_id=? AND cu.cylinder_type_id=? AND cu.status='filled' AND cu.gas_weight_kg>0
                         ORDER BY cu.id FOR UPDATE",
                        [$locationId,$row['type_id']]
                    )->getResultArray();
                    $availableAuto=array_sum(array_map(static fn($u)=>(float)$u['gas_weight_kg'],$autoUnits));
                    if($availableAuto+0.00001<$remaining) throw new RuntimeException('Insufficient filled gas stock for '.$row['line_no'].'. Available: '.number_format($availableAuto,2).' KG; required: '.number_format($remaining,2).' KG.');
                    foreach($autoUnits as $unit){
                        if($remaining<=0.00001) break;
                        $used=min($remaining,(float)$unit['gas_weight_kg']);
                        if($used>0) $allocationUnits[]=['unit'=>$unit,'used'=>$used];
                        $remaining-=$used;
                    }
                }

                foreach($allocationUnits as $allocation){
                    $source=$allocation['unit'];$used=(float)$allocation['used'];
                    $before=(float)$source['gas_weight_kg'];
                    if($used>$before+0.00001) throw new RuntimeException('Source cylinder '.$source['unit_code'].' stock changed while posting.');
                    $after=$before-$used;$newStatus=$after<=0.00001?'empty':'filled';
                    $this->db->table('cylinder_units')->where('id',(int)$source['id'])->update(['gas_weight_kg'=>max(0,$after),'status'=>$newStatus]);

                    $movementRows=[['type'=>'gas_kg','cylinder_type_id'=>null,'quantity'=>$used,'direction'=>'out','unit_id'=>(int)$source['id'],'notes'=>($allowSourceSelection?'Gas sold from selected filled cylinder':'Gas auto-allocated from filled cylinder in sequence')]];
                    if($newStatus==='empty'){
                        $movementRows[]=['type'=>'filled_cylinder','cylinder_type_id'=>$row['type_id'],'quantity'=>1,'direction'=>'out','unit_id'=>(int)$source['id'],'notes'=>'Cylinder became empty after gas sale'];
                        $movementRows[]=['type'=>'empty_cylinder','cylinder_type_id'=>$row['type_id'],'quantity'=>1,'direction'=>'in','unit_id'=>(int)$source['id'],'notes'=>'Cylinder became empty after gas sale'];
                    }
                    foreach($movementRows as $m){
                        $this->db->table('inventory_movements')->insert([
                            'location_id'=>$locationId,'inventory_type'=>$m['type'],'cylinder_type_id'=>$m['cylinder_type_id'],
                            'quantity'=>$m['quantity'],'direction'=>$m['direction'],'movement_at'=>$transactionAt,
                            'source_type'=>'sale','source_id'=>$saleId,'source_line_id'=>$saleItemId,
                            'cylinder_unit_id'=>$m['unit_id'],'created_by'=>$userId,'notes'=>$m['notes']
                        ]);
                    }
                    $sources[(int)$source['id']]=$source;$sources[(int)$source['id']]['gas_weight_kg']=max(0,$after);$sources[(int)$source['id']]['status']=$newStatus;
                }

                if($row['target_id']) $this->cylinders->addGasToCustody($row['target_id'],$customerId,$row['quantity']);
            }
            if(!$this->db->transStatus()) throw new RuntimeException('Gas sale posting failed.');
            $this->insertSalePayments($saleId,$paymentPlan['sale_payments'],$transactionAt,$userId);
            $this->postCustomerSettlement($paymentPlan['settlements'],$locationId,$saleId,$customerId,$transactionAt,$userId);
            $cash=$this->paymentCash($paymentPlan['sale_payments']);
            if($cash>0){
                $session=$this->cash->openSessionForLocation($locationId);
                if(!$session) throw new RuntimeException('Open the counter cash session before posting a cash sale.');
                $this->cash->postSaleCash((int)$session['id'],$saleId,$cash,$userId,$transactionAt);
            }

            if(!$this->db->transStatus()) throw new RuntimeException('Gas sale posting failed.');
            $this->db->transCommit();$this->releaseInventoryLocks();
            return ['id'=>$saleId,'sale_no'=>$saleNo,'total'=>$total,'customer_id'=>$customerId,'credit_amount'=>$paymentPlan['credit_amount']];
        }catch(\Throwable $e){
            $this->releaseInventoryLocks();$this->db->transRollback();throw $e;
        }
    }

    protected function postCylinderSaleHeader(array $payload,int $userId,int $locationId,?int $customerId,string $transactionAt,?string $notes,array $lines,array $payments): array
    {
        if(!$lines) throw new RuntimeException('At least one cylinder sale line is required.');
        if(!$payments) throw new RuntimeException('At least one payment is required.');

        $prepared=[];$subtotal=0;$customRate=false;$selectedAcrossLines=[];
        foreach($lines as $i=>$line){
            $n=$i+1;
            $typeId=isset($line['cylinder_type_id'])&&$line['cylinder_type_id']!==''?(int)$line['cylinder_type_id']:0;
            $status=(string)($line['cylinder_status']??'empty');
            $qty=(float)($line['quantity']??0);
            $gasRateInput=trim((string)($line['gas_rate']??''))===''?null:(float)$line['gas_rate'];
            $cylRateInput=trim((string)($line['cylinder_rate']??''))===''?null:(float)$line['cylinder_rate'];
            $selectedIds=array_values(array_unique(array_map('intval',is_array($line['selected_cylinder_unit_ids']??null)?$line['selected_cylinder_unit_ids']:[])));

            if(!$typeId||!in_array($status,['filled','empty'],true)) throw new RuntimeException('Cylinder type and sale type are required on line '.$n.'.');
            if($qty<=0||floor($qty)!==$qty) throw new RuntimeException('Cylinder quantity on line '.$n.' must be a whole number.');
            $type=$this->types->find($typeId);
            if(!$type||!(int)$type['is_active']) throw new RuntimeException('Invalid or inactive cylinder type on line '.$n.'.');

            $gasRate=0;$stdGas=null;
            // Empty-cylinder price: the configured rate for this cylinder type, otherwise the Cylinder Type master price.
            $standardCylinder=$this->standardPrice('emptyCylinder',$type,$transactionAt);
            $stdCylinderRate=(float)($standardCylinder['rate']??0);$rateSource=$standardCylinder['source'];
            // A manually entered cylinder price is valid even when it is 0.00.
            // Only require a configured/default price when the user leaves the price blank.
            if($cylRateInput===null && $stdCylinderRate<=0) throw new RuntimeException('No empty-cylinder price is defined for '.$type['name'].'. Enter a cylinder price on the POS line or configure a default rate.');
            $cylRate=$cylRateInput??$stdCylinderRate;
            if($cylRate<0) throw new RuntimeException('Cylinder price cannot be negative on line '.$n.'.');

            if($status==='filled'){
                if(count($selectedIds)!==(int)$qty) throw new RuntimeException('Filled cylinder line '.$n.' must select exactly '.(int)$qty.' physical cylinder(s).');
                foreach($selectedIds as $selectedId){
                    if(isset($selectedAcrossLines[$selectedId])) throw new RuntimeException('Physical cylinder '.$selectedId.' is selected more than once in this sale.');
                    $selectedAcrossLines[$selectedId]=$n;
                }
                // Gas inside the cylinder: the full-cylinder price expressed per KG (else the per-KG rate),
                // so a partly filled cylinder is pro-rated and a full one costs exactly the cylinder price.
                $standardGas=$this->standardPrice('cylinderGasPerKg',$type,$transactionAt);
                $stdGas=$standardGas['rate'];$rateSource=$standardGas['source'];
                $gasRate=$gasRateInput??$stdGas;
                if($gasRate===null || $gasRate<=0){
                    throw new RuntimeException($gasRateInput===null
                        ? 'No effective gas/kg rate exists. Enter a positive Gas Rate for this line or configure an effective rate in Gas Rate Settings.'
                        : 'Gas rate must be greater than zero on line '.$n.'.');
                }
            }else{
                if($selectedIds) throw new RuntimeException('Physical cylinder selection is only allowed for filled cylinder sale lines.');
            }

            $custom=(($status==='filled'&&$gasRateInput!==null&&($stdGas===null||abs($gasRate-$stdGas)>0.00001))||($cylRateInput!==null&&abs($cylRate-$stdCylinderRate)>0.00001));
            $customRate=$customRate||$custom;
            $prepared[]=[
                'line_no'=>$n,'type_id'=>$typeId,'status'=>$status,'quantity'=>(int)$qty,
                'selected_ids'=>$selectedIds,'gas_rate'=>$gasRate,'cyl_rate'=>$cylRate,
                'std_gas'=>$stdGas,'std_cyl_rate'=>$stdCylinderRate,'custom_rate'=>$custom?1:0,
                'rate_source'=>$custom?'manual':$rateSource
            ];
        }
        $rateChangeReason=$this->rateChangeReasonFor($customRate);
        $customRate=$customRate||$this->backdatedPriceDiffers;

        $this->db->transBegin();
        try{
            $customer=null;
            if($customerId){
                $customer=$this->db->query("SELECT * FROM customers WHERE id=? FOR UPDATE",[$customerId])->getRowArray();
                if(!$customer||!(int)$customer['is_active']) throw new RuntimeException('Customer is unavailable.');
            }

            $lockRows=[];
            foreach($prepared as $row){
                $lockRows[]=['type'=>$row['status']==='filled'?'filled_cylinder':'empty_cylinder','cylinder_type_id'=>$row['type_id']];
                if($row['status']==='filled') $lockRows[]=['type'=>'gas_kg','cylinder_type_id'=>null];
            }
            $this->acquireInventoryLocks($locationId,$lockRows);

            $inventory=[];$lineAmounts=[];$totalKg=0;$reservedEmptyIds=[];
            foreach($prepared as $idx=>&$row){
                $selected=[];
                if($row['status']==='filled'){
                    $ids=$row['selected_ids'];
                    $placeholders=implode(',',array_fill(0,count($ids),'?'));
                    $rows=$this->db->query(
                        "SELECT cu.*,ct.code cylinder_code,ct.name cylinder_name,ct.capacity_kg
                         FROM cylinder_units cu JOIN cylinder_types ct ON ct.id=cu.cylinder_type_id
                         WHERE cu.location_id=? AND cu.status='filled' AND cu.id IN ($placeholders)
                         ORDER BY cu.id FOR UPDATE",
                        array_merge([$locationId],$ids)
                    )->getResultArray();
                    if(count($rows)!==count($ids)) throw new RuntimeException('One or more selected filled cylinders are no longer available. Refresh the list and retry.');
                    foreach($rows as $unit){
                        if((int)$unit['cylinder_type_id']!==$row['type_id']) throw new RuntimeException('Selected cylinder '.$unit['unit_code'].' does not belong to the selected cylinder type.');
                        if((float)$unit['gas_weight_kg']<=0.00001) throw new RuntimeException('Selected cylinder '.$unit['unit_code'].' has no gas available.');
                        $selected[]=$unit;
                    }
                    $row['gas_kg']=array_sum(array_map(static fn($u)=>(float)$u['gas_weight_kg'],$selected));
                    $row['quantity']=count($selected);
                    $row['selected_codes']=array_map(static fn($u)=>$u['unit_code'],$selected);
                    foreach($selected as $unit){
                        $inventory[]=['line_no'=>$row['line_no'],'type'=>'gas_kg','cylinder_type_id'=>null,'quantity'=>(float)$unit['gas_weight_kg'],'direction'=>'out','unit_id'=>(int)$unit['id'],'notes'=>'Gas sold from selected physical cylinder'];
                        $inventory[]=['line_no'=>$row['line_no'],'type'=>'filled_cylinder','cylinder_type_id'=>$row['type_id'],'quantity'=>1,'direction'=>'out','unit_id'=>(int)$unit['id'],'notes'=>'Filled cylinder sold'];
                    }
                }else{
                    $limit=(int)$row['quantity'];
                    $rows=$this->db->query(
                        "SELECT * FROM cylinder_units
                         WHERE location_id=? AND cylinder_type_id=? AND status='empty'
                         ORDER BY id LIMIT $limit FOR UPDATE",
                        [$locationId,$row['type_id']]
                    )->getResultArray();
                    if(count($rows)<$limit) throw new RuntimeException('Insufficient empty cylinders of '.$this->types->find($row['type_id'])['name'].'.');
                    $row['gas_kg']=0;$row['selected_codes']=[];
                    foreach($rows as $unit){
                        if(isset($reservedEmptyIds[(int)$unit['id']])) throw new RuntimeException('Duplicate empty cylinder allocation detected.');
                        $reservedEmptyIds[(int)$unit['id']]=true;
                        $row['selected_codes'][]=$unit['unit_code'];
                        $inventory[]=['line_no'=>$row['line_no'],'type'=>'empty_cylinder','cylinder_type_id'=>$row['type_id'],'quantity'=>1,'direction'=>'out','unit_id'=>(int)$unit['id'],'notes'=>'Empty cylinder sold'];
                    }
                }

                $lineTotal=$row['gas_kg']*$row['gas_rate']+$row['quantity']*$row['cyl_rate'];
                $row['line_total']=$lineTotal;
                $lineAmounts[]=$lineTotal;$subtotal+=$lineTotal;$totalKg+=(float)$row['gas_kg'];
            }
            unset($row);

            $discount=max(0,(float)($payload['discount_amount']??0));
            if($discount>$subtotal) throw new RuntimeException('Discount cannot exceed subtotal.');
            $total=$subtotal-$discount;

            $paymentPlan=$this->prepareSalePaymentPlan($payments,$customerId,$total);
            $saleNo=$this->nextSaleNo($transactionAt,$locationId);
            $this->db->table('sales')->insert([
                'sale_no'=>$saleNo,'location_id'=>$locationId,'customer_id'=>$customerId,'transaction_type'=>'cylinder_sale',
                'status'=>'posted','transaction_at'=>$transactionAt,'total_kg'=>$totalKg,'subtotal'=>$subtotal,
                'discount_amount'=>$discount,'total_amount'=>$total,'security_deposit_amount'=>0,'security_deposit_refund_amount'=>0,
                'credit_amount'=>$paymentPlan['credit_amount'],'previous_os_balance'=>$paymentPlan['previous_os'],
                'receipt_amount'=>$paymentPlan['payment_total'],'net_receivable_amount'=>$paymentPlan['net_receivable'],
                'os_balance'=>$paymentPlan['remaining_os'],'custom_rate_flag'=>$customRate?1:0,'price_list'=>$this->priceList,
                'rate_change_reason'=>$rateChangeReason,'notes'=>$notes,'created_by'=>$userId
            ]);
            $saleId=(int)$this->db->insertID();

            foreach($prepared as $row){
                $appliedRate=$row['status']==='filled'
                    ? (($row['gas_kg']/max($row['quantity'],1))*$row['gas_rate'])+$row['cyl_rate']
                    : $row['cyl_rate'];
                $standardRate=$row['status']==='filled'
                    ? (($row['gas_kg']/max($row['quantity'],1))*$row['std_gas'])+$row['std_cyl_rate']
                    : $row['std_cyl_rate'];
                $this->db->table('sale_items')->insert([
                    'sale_id'=>$saleId,'line_no'=>$row['line_no'],'line_type'=>$row['status']==='filled'?'filled_cylinder':'empty_cylinder',
                    'cylinder_type_id'=>$row['type_id'],'customer_cylinder_unit_id'=>null,'quantity'=>$row['quantity'],
                    'gas_weight_kg'=>$row['gas_kg'],'applied_rate'=>$appliedRate,'gas_rate'=>$row['gas_rate'],'cylinder_price'=>$row['cyl_rate'],'standard_rate'=>$standardRate,
                    'custom_rate_flag'=>$row['custom_rate'],'rate_source'=>$row['rate_source'],'empty_cylinder_received'=>0,'line_discount'=>0,
                    'line_total'=>$row['line_total'],'notes'=>'Cylinder sale | '.$row['status'].' | '.implode(', ',$row['selected_codes'])
                ]);
            }

            $lineIds=[];
            foreach($this->db->table('sale_items')->select('id,line_no')->where('sale_id',$saleId)->get()->getResultArray() as $row) $lineIds[(int)$row['line_no']]=(int)$row['id'];

            $soldUnitIds=[];
            foreach($inventory as $m){
                $this->db->table('inventory_movements')->insert([
                    'location_id'=>$locationId,'inventory_type'=>$m['type'],'cylinder_type_id'=>$m['cylinder_type_id'],
                    'quantity'=>$m['quantity'],'direction'=>$m['direction'],'movement_at'=>$transactionAt,'source_type'=>'sale',
                    'source_id'=>$saleId,'source_line_id'=>$lineIds[(int)$m['line_no']]??null,'cylinder_unit_id'=>$m['unit_id'],
                    'created_by'=>$userId,'notes'=>$m['notes']
                ]);
                $unitId=(int)($m['unit_id']??0);
                if($unitId>0) $soldUnitIds[$unitId]=true;
            }
            foreach(array_keys($soldUnitIds) as $unitId) $this->cylinders->markSold((int)$unitId);

            $this->insertSalePayments($saleId,$paymentPlan['sale_payments'],$transactionAt,$userId);
            $this->postCustomerSettlement($paymentPlan['settlements'],$locationId,$saleId,$customerId,$transactionAt,$userId);
            $cash=$this->paymentCash($paymentPlan['sale_payments']);
            if($cash>0){
                $session=$this->cash->openSessionForLocation($locationId);
                if(!$session) throw new RuntimeException('Open the counter cash session before posting a cash sale.');
                $this->cash->postSaleCash((int)$session['id'],$saleId,$cash,$userId,$transactionAt);
            }
            if(!$this->db->transStatus()) throw new RuntimeException('Cylinder sale posting failed.');
            $this->db->transCommit();$this->releaseInventoryLocks();
            return ['id'=>$saleId,'sale_no'=>$saleNo,'total'=>$total,'customer_id'=>$customerId,'credit_amount'=>$paymentPlan['credit_amount']];
        }catch(\Throwable $e){
            $this->releaseInventoryLocks();$this->db->transRollback();throw $e;
        }
    }

    protected function postSecurityDepositHeader(array $payload,int $userId,int $locationId,int $customerId,string $transactionAt,?string $notes,array $payments,array $unitIds,float $deposit): array
    {
        $lines=is_array($payload['lines']??null)?$payload['lines']:[];
        if(!$unitIds && !$lines) throw new RuntimeException('Select at least one cylinder to place on customer custody.');
        if($deposit<0) throw new RuntimeException('Security Deposit Amount cannot be negative.');

        $shopSettings=(new ShopSettingsModel())->forLocation($locationId);
        $includeDepositOs=(int)($shopSettings['include_security_deposit_in_os']??0)===1;
        $selectedIds=[];
        foreach($lines as $line) foreach((array)($line['selected_cylinder_unit_ids']??[]) as $id) $selectedIds[]=(int)$id;
        $selectedIds=array_values(array_unique(array_filter($selectedIds,static fn($id)=>$id>0)));
        if(!$selectedIds)$selectedIds=$unitIds;
        if(!$selectedIds)throw new RuntimeException('Select at least one cylinder to place on customer custody.');

        $rows=$this->db->table('cylinder_units cu')
            ->select('cu.*,ct.name cylinder_name,ct.code cylinder_code,ct.capacity_kg,ct.empty_cylinder_price')
            ->join('cylinder_types ct','ct.id=cu.cylinder_type_id')
            ->where('cu.location_id',$locationId)->whereIn('cu.id',$selectedIds)
            ->whereIn('cu.status',['filled','empty'])->orderBy('cu.id')->get()->getResultArray();
        if(count($rows)!==count($selectedIds))throw new RuntimeException('One or more selected cylinders are no longer available for custody.');

        $lineByUnit=[];$seenCombos=[];
        foreach($lines as $i=>$line){
            $status=(string)($line['cylinder_status']??'');$typeId=(int)($line['cylinder_type_id']??0);
            $ids=array_values(array_unique(array_map('intval',(array)($line['selected_cylinder_unit_ids']??[]))));
            if(!$typeId||!in_array($status,['filled','empty'],true)||!$ids)throw new RuntimeException('Issue line '.($i+1).' is incomplete.');
            $combo=$typeId.'|'.$status;
            if(isset($seenCombos[$combo]))throw new RuntimeException('The same Cylinder Type + Filled/Empty combination cannot be used twice in one issue transaction.');
            $seenCombos[$combo]=true;
            foreach($ids as $id){
                if(isset($lineByUnit[$id]))throw new RuntimeException('Physical cylinder '.$id.' is selected more than once.');
                $lineByUnit[$id]=['type_id'=>$typeId,'status'=>$status,'gas_rate'=>trim((string)($line['gas_rate']??''))===''?null:(float)$line['gas_rate']];
            }
        }
        foreach($rows as $row){
            $id=(int)$row['id'];
            if(!isset($lineByUnit[$id]))throw new RuntimeException('Selected cylinder '.$row['unit_code'].' is missing from an issue line.');
            if((int)$row['cylinder_type_id']!==$lineByUnit[$id]['type_id'])throw new RuntimeException('Selected cylinder '.$row['unit_code'].' does not match its issue line.');
            if($lineByUnit[$id]['status']==='filled' && $row['status']!=='filled')throw new RuntimeException('Selected cylinder '.$row['unit_code'].' is not filled/partially filled.');
            if($lineByUnit[$id]['status']==='empty' && $row['status']!=='empty')throw new RuntimeException('Selected cylinder '.$row['unit_code'].' is not empty.');
        }

        $prepared=[];$subtotal=0;$totalKg=0;$customRate=false;
        foreach($rows as $row){
            $cfg=$lineByUnit[(int)$row['id']];
            // The cylinder stays company property on custody, so no cylinder price is charged:
            // only the gas inside a filled / part-filled cylinder is payable. The security deposit
            // itself is a refundable hold, not a charge. Gas is priced like gas inside a cylinder
            // (full-cylinder price per KG, else per-KG rate); a blank rate uses the price list.
            $gas=(float)$row['gas_weight_kg'];
            $gasRate=0.0;$stdGas=null;$lineCustom=false;$rateSource=null;
            if($cfg['status']==='filled'){
                $type=['id'=>(int)$row['cylinder_type_id'],'capacity_kg'=>$row['capacity_kg']];
                $standardGas=$this->standardPrice('cylinderGasPerKg',$type,$transactionAt);
                $stdGas=$standardGas['rate'];
                if($cfg['gas_rate']!==null && $cfg['gas_rate']<=0) throw new RuntimeException('Gas rate must be greater than zero for '.$row['unit_code'].'.');
                if($cfg['gas_rate']===null && $stdGas===null) throw new RuntimeException('No effective gas rate exists for '.$row['cylinder_name'].'. Set it in LPG Rates or enter a gas rate on the issue line.');
                $gasRate=$cfg['gas_rate']??$stdGas;
                $lineCustom=$cfg['gas_rate']!==null && ($stdGas===null || abs($gasRate-$stdGas)>0.00001);
                $rateSource=$lineCustom?'manual':$standardGas['source'];
            }
            $lineTotal=$gas*$gasRate;
            $customRate=$customRate||$lineCustom;
            $prepared[]=['row'=>$row,'status'=>$cfg['status'],'gas'=>$gas,'gas_rate'=>$gasRate,'line_total'=>$lineTotal,'std_gas'=>$stdGas,
                'custom'=>$lineCustom?1:0,'rate_source'=>$rateSource];
            $subtotal+=$lineTotal;$totalKg+=$gas;
        }
        $rateChangeReason=$this->rateChangeReasonFor($customRate);
        $customRate=$customRate||$this->backdatedPriceDiffers;
        $chargeTotal=round($subtotal,2);

        // Combined collection is recorded as separate Sale and Security Deposit payment rows.
        // The cashier may enter one total payment; the shop setting controls how that amount is allocated.
        $shopSettings=(new ShopSettingsModel())->forLocation($locationId);
        $depositRequired=$deposit;
        $allocationRule=(string)($shopSettings['deposit_payment_allocation_rule']??'deposit_first');
        if(!in_array($allocationRule,['gas_first','deposit_first','manual'],true))$allocationRule='deposit_first';
        $depositPayments=[];$salePayments=[];
        $combinedPayments=[];
        foreach($payments as $p){
            $type=(string)($p['payment_type']??'');
            $mode=(string)($p['payment_mode']??'');$amount=(float)($p['amount']??0);
            if($type==='combined'){
                if(!in_array($mode,['cash','cheque','online','credit'],true)||$amount<0)throw new RuntimeException('Invalid combined payment.');
                // A blank/zero payment row means no money was received. It must not block
                // a Security Deposit / Issue Cylinder transaction when the gas/cylinder
                // amount is posted to customer credit within the normal credit rules.
                if($amount<=0)continue;
                $combinedPayments[]=['payment_mode'=>$mode,'amount'=>$amount,'reference_no'=>$p['reference_no']??null];
                continue;
            }
            if(!in_array($type,['security_deposit','sale'],true))throw new RuntimeException('Every Security Deposit payment must be classified as Security Deposit or Gas / Cylinder.');
            if(!in_array($mode,['cash','cheque','online','credit'],true)||$amount<0)throw new RuntimeException('Invalid payment.');
            if($type==='security_deposit'){
                if($mode==='credit' && $amount>0)throw new RuntimeException('Security Deposit cannot be received on credit.');
                if($amount>0)$depositPayments[]=['payment_mode'=>$mode,'amount'=>$amount,'reference_no'=>$p['reference_no']??null];
            }else{
                $salePayments[]=['payment_mode'=>$mode,'amount'=>$amount,'reference_no'=>$p['reference_no']??null];
            }
        }
        $gasPreviousOs=$this->customerGasBalance($customerId);
        $gasDue=max(0,round($chargeTotal+$gasPreviousOs,2));
        $combinedReceived=array_sum(array_map(static fn($p)=>(float)$p['amount'],$combinedPayments));
        if($combinedPayments && $combinedReceived>$gasDue+$depositRequired+0.01){
            throw new RuntimeException('Payment exceeds the combined Gas / Cylinder balance and Security Deposit amount.');
        }
        if($combinedPayments){
            if($allocationRule==='manual')throw new RuntimeException('Manual payment allocation is enabled. Enter the Gas / Cylinder and Security Deposit allocations separately.');
            foreach($combinedPayments as $p){
                $remaining=(float)$p['amount'];
                if($allocationRule==='gas_first'){
                    $toGas=min($remaining,$gasDue); if($toGas>0){$salePayments[]=['payment_mode'=>$p['payment_mode'],'amount'=>$toGas,'reference_no'=>$p['reference_no']??null];$gasDue-=$toGas;$remaining-=$toGas;}
                    // Credit can cover gas only: a deposit is refundable money actually received.
                    $toDeposit=min($remaining,$deposit); if($toDeposit>0){if($p['payment_mode']==='credit')throw new RuntimeException('Security Deposit cannot be received on credit.');$depositPayments[]=['payment_mode'=>$p['payment_mode'],'amount'=>$toDeposit,'reference_no'=>$p['reference_no']??null];$deposit-=$toDeposit;$remaining-=$toDeposit;}
                }else{
                    $toDeposit=min($remaining,$deposit); if($toDeposit>0){if($p['payment_mode']==='credit')throw new RuntimeException('Security Deposit cannot be received on credit.');$depositPayments[]=['payment_mode'=>$p['payment_mode'],'amount'=>$toDeposit,'reference_no'=>$p['reference_no']??null];$deposit-=$toDeposit;$remaining-=$toDeposit;}
                    $toGas=min($remaining,$gasDue); if($toGas>0){$salePayments[]=['payment_mode'=>$p['payment_mode'],'amount'=>$toGas,'reference_no'=>$p['reference_no']??null];$gasDue-=$toGas;$remaining-=$toGas;}
                }
                if($remaining>0.01)throw new RuntimeException('Payment exceeds the combined amount due.');
            }
        }
        // Security deposit receipt is independent of the amount entered for the issue transaction.
        // Accept any non-negative receipt amount; only actual receipts increase the refundable balance.
        $depositPaid=array_sum(array_map(static fn($p)=>(float)$p['amount'],$depositPayments));
        if($chargeTotal>0.00001){
            if(!$salePayments)$salePayments=[['payment_mode'=>'credit','amount'=>0,'reference_no'=>null]];
            $paymentPlan=$this->prepareSalePaymentPlan($salePayments,$customerId,$chargeTotal);
        }else{
            if($salePayments)throw new RuntimeException('No gas/cylinder amount is payable; sale payment rows are not allowed.');
            $paymentPlan=['sale_payments'=>[],'settlements'=>[],'credit_amount'=>0,'previous_os'=>$gasPreviousOs,'payment_total'=>0,'net_receivable'=>$gasPreviousOs,'remaining_os'=>$gasPreviousOs];
        }
        if($allocationRule==='manual'){
            // Manual mode is represented by explicit payment_type rows from the POS.
            if($combinedPayments)throw new RuntimeException('Manual allocation requires separate payment rows.');
        }
        $this->db->transBegin();
        try{
            $this->acquireInventoryLocks($locationId,array_map(static fn($x)=>['type'=>$x['status']==='filled'?'filled_cylinder':'empty_cylinder','cylinder_type_id'=>(int)$x['row']['cylinder_type_id']],$prepared));
            foreach($selectedIds as $id){
                $locked=$this->db->query("SELECT cu.*,ct.capacity_kg FROM cylinder_units cu JOIN cylinder_types ct ON ct.id=cu.cylinder_type_id WHERE cu.id=? AND cu.location_id=? FOR UPDATE",[$id,$locationId])->getRowArray();
                if(!$locked||!in_array($locked['status'],['filled','empty'],true))throw new RuntimeException('One or more selected cylinders changed availability. Refresh and retry.');
            }

            $gasOsAfter=$paymentPlan['remaining_os'];
            $depositBalanceBefore=$this->securityDepositBalance($locationId,$customerId);
            $depositBalanceAfter=$depositBalanceBefore+$depositPaid;
            $overallOsAfter=$includeDepositOs?($gasOsAfter+$depositBalanceAfter):$gasOsAfter;

            $saleNo=$this->nextSaleNo($transactionAt,$locationId);
            $this->db->table('sales')->insert([
                'sale_no'=>$saleNo,'location_id'=>$locationId,'customer_id'=>$customerId,'transaction_type'=>'security_deposit','status'=>'posted',
                'transaction_at'=>$transactionAt,'total_kg'=>$totalKg,'subtotal'=>$chargeTotal,'discount_amount'=>0,'total_amount'=>$chargeTotal,
                'security_deposit_amount'=>$depositPaid,'security_deposit_refund_amount'=>0,'credit_amount'=>$paymentPlan['credit_amount'],
                'previous_os_balance'=>$paymentPlan['previous_os'],'receipt_amount'=>$paymentPlan['payment_total'],
                'net_receivable_amount'=>$paymentPlan['net_receivable'],'os_balance'=>$overallOsAfter,'return_gas_ledger_amount'=>0,
                'custom_rate_flag'=>$customRate?1:0,'price_list'=>$this->priceList,'rate_change_reason'=>$rateChangeReason,
                'notes'=>$notes,'created_by'=>$userId
            ]);
            $saleId=(int)$this->db->insertID();

            // Custody keeps the deposit actually received, never the amount keyed in on the POS.
            $depositPerUnit=$depositPaid>0?round($depositPaid/count($prepared),2):0;$assigned=0;
            foreach($prepared as $idx=>$item){
                $row=$item['row'];$unitId=(int)$row['id'];$share=($idx===count($prepared)-1)?round($depositPaid-$assigned,2):$depositPerUnit;$assigned+=$share;
                $this->cylinders->moveToCustody($unitId,$customerId,$saleId,$userId);
                $custody=$this->db->table('cylinder_custody')->where(['cylinder_unit_id'=>$unitId,'status'=>'issued'])->orderBy('id','DESC')->get()->getRowArray();
                if(!$custody)throw new RuntimeException('Custody record could not be created for '.$row['unit_code'].'.');
                $this->db->table('cylinder_custody')->where('id',$custody['id'])->update([
                    'deposit_amount'=>$share,'issued_gas_weight_kg'=>$item['gas'],'returned_gas_weight_kg'=>0,'consumed_gas_weight_kg'=>0,
                    'issued_gas_rate'=>$item['gas_rate'],'return_gas_rate'=>0,'issued_condition'=>$item['status']
                ]);
                // Deposit ledger is posted from actual received payments, not custody/deposit amount.

                $this->db->table('sale_items')->insert([
                    'sale_id'=>$saleId,'line_no'=>$idx+1,'line_type'=>$item['status']==='filled'?'filled_cylinder':'empty_cylinder',
                    'cylinder_type_id'=>$row['cylinder_type_id'],'customer_cylinder_unit_id'=>$unitId,'quantity'=>1,'gas_weight_kg'=>$item['gas'],
                    'applied_rate'=>$item['gas_rate'],'gas_rate'=>$item['gas_rate'],'cylinder_price'=>0,'standard_rate'=>$item['std_gas'],
                    'custom_rate_flag'=>$item['custom'],'rate_source'=>$item['rate_source'],'empty_cylinder_received'=>0,'line_discount'=>0,'line_total'=>$item['line_total'],'notes'=>'Custody issued: '.$row['unit_code']
                ]);
                $lineId=(int)$this->db->insertID();
                $this->db->table('inventory_movements')->insert(['location_id'=>$locationId,'inventory_type'=>$item['status']==='filled'?'filled_cylinder':'empty_cylinder','cylinder_type_id'=>$row['cylinder_type_id'],'quantity'=>1,'direction'=>'out','movement_at'=>$transactionAt,'source_type'=>'security_deposit','source_id'=>$saleId,'source_line_id'=>$lineId,'cylinder_unit_id'=>$unitId,'created_by'=>$userId,'notes'=>'Cylinder placed on customer custody']);
                if($item['status']==='filled'&&$item['gas']>0)$this->db->table('inventory_movements')->insert(['location_id'=>$locationId,'inventory_type'=>'gas_kg','cylinder_type_id'=>null,'quantity'=>$item['gas'],'direction'=>'out','movement_at'=>$transactionAt,'source_type'=>'security_deposit','source_id'=>$saleId,'source_line_id'=>$lineId,'cylinder_unit_id'=>$unitId,'created_by'=>$userId,'notes'=>'Gas issued with custody cylinder']);
            }

            // Deposit receipt and gas/cylinder payments have distinct payment classifications.
            if($depositPaid>0)$this->db->table('customer_security_deposits')->insert(['location_id'=>$locationId,'customer_id'=>$customerId,'entry_type'=>'hold','amount'=>$depositPaid,'sale_id'=>$saleId,'custody_id'=>null,'transaction_at'=>$transactionAt,'created_by'=>$userId,'notes'=>'Security deposit actually received']);
            $this->insertSalePayments($saleId,$depositPayments,$transactionAt,$userId,'security_deposit');
            $this->insertSalePayments($saleId,$paymentPlan['sale_payments'],$transactionAt,$userId,'sale');

            $depositCash=$this->paymentCash($depositPayments);
            if($depositCash>0){
                $s=$this->cash->openSessionForLocation($locationId);if(!$s)throw new RuntimeException('Open the counter cash session before receiving a security deposit.');
                $this->cash->postGeneric((int)$s['id'],'security_deposit','in',$depositCash,'sale',$saleId,$userId,'Security deposit received',$transactionAt);
            }
            $saleCash=$this->paymentCash($paymentPlan['sale_payments']);
            if($saleCash>0){
                $s=$this->cash->openSessionForLocation($locationId);if(!$s)throw new RuntimeException('Open the counter cash session before receiving the gas/cylinder amount.');
                $this->cash->postGeneric((int)$s['id'],'sale_cash','in',$saleCash,'sale',$saleId,$userId,'Gas/cylinder amount received with custody issue',$transactionAt);
            }
            $this->postCustomerSettlement($paymentPlan['settlements'],$locationId,$saleId,$customerId,$transactionAt,$userId);

            if(!$this->db->transStatus())throw new RuntimeException('Security deposit / cylinder issue failed.');
            $this->db->transCommit();$this->releaseInventoryLocks();
            return ['id'=>$saleId,'sale_no'=>$saleNo,'total'=>$chargeTotal,'customer_id'=>$customerId,'credit_amount'=>$paymentPlan['credit_amount']];
        }catch(\Throwable $e){$this->releaseInventoryLocks();$this->db->transRollback();throw $e;}
    }

    protected function postCylinderReturnHeader(array $payload,int $userId,int $locationId,int $customerId,string $transactionAt,?string $notes,array $returnUnitIds): array
    {
        $lines=is_array($payload['lines']??null)?$payload['lines']:[];
        if(!$returnUnitIds&&!$lines)throw new RuntimeException('Select at least one customer custody cylinder to return.');
        $payments=is_array($payload['payments']??null)?$payload['payments']:[];
        foreach($payments as $p){
            if((string)($p['payment_type']??'')!=='security_deposit_refund')throw new RuntimeException('Cylinder Return payments must be classified as Security Deposit Refund.');
        }
        $shopSettings=(new ShopSettingsModel())->forLocation($locationId);
        $allowGas=(int)($shopSettings['allow_return_gas_qty']??0)===1;
        $affectsOs=(int)($shopSettings['return_gas_affects_os']??0)===1;
        $allowEmptyGas=(int)($shopSettings['allow_empty_issued_return_gas']??0)===1;
        $allowOver=(int)($shopSettings['allow_return_gas_over_issued']??0)===1;
        $includeDepositOs=(int)($shopSettings['include_security_deposit_in_os']??0)===1;
        $refund=max(0,(float)($payload['security_deposit_refund_amount']??0));

        $selected=[];
        foreach($lines as $line){$id=(int)($line['unit_id']??0);if($id>0)$selected[]=$id;}
        if(!$selected)$selected=$returnUnitIds;
        $selected=array_values(array_unique($selected));
        if(!$selected)throw new RuntimeException('Select at least one customer custody cylinder to return.');

        $rows=$this->db->table('cylinder_custody cc')->select('cc.*,cu.unit_code,cu.gas_weight_kg,cu.cylinder_type_id,cu.status cylinder_status,ct.name cylinder_name,ct.capacity_kg')
            ->join('cylinder_units cu','cu.id=cc.cylinder_unit_id')->join('cylinder_types ct','ct.id=cu.cylinder_type_id')
            ->where(['cc.location_id'=>$locationId,'cc.customer_id'=>$customerId,'cc.status'=>'issued'])->whereIn('cc.cylinder_unit_id',$selected)->orderBy('cc.id')->get()->getResultArray();
        if(count($rows)!==count($selected))throw new RuntimeException('One or more selected custody cylinders are no longer active for this customer.');

        $returnById=[];
        foreach($lines as $line){
            $id=(int)($line['unit_id']??0);if($id<=0)continue;
            if(isset($returnById[$id]))throw new RuntimeException('Cylinder '.$id.' appears more than once in the return.');
            $qty=max(0,(float)($line['return_gas_kg']??0));$rate=max(0,(float)($line['return_gas_rate']??0));
            if(!$allowGas&&$qty>0.00001)throw new RuntimeException('Return gas quantity is disabled in Shop Settings.');
            $returnById[$id]=['gas'=>$qty,'rate'=>$rate];
        }
        $returnRateChanged=false;
        foreach($rows as $row){
            $id=(int)$row['cylinder_unit_id'];$cfg=$returnById[$id]??['gas'=>0,'rate'=>0];$issued=(float)$row['issued_gas_weight_kg'];
            if($cfg['gas']<0)throw new RuntimeException('Return gas cannot be negative for '.$row['unit_code'].'.');
            if($cfg['gas']>0&&!$allowGas)throw new RuntimeException('Return gas quantity is disabled in Shop Settings.');
            if($cfg['gas']>0&&$issued<=0&&!$allowEmptyGas)throw new RuntimeException('Cylinder '.$row['unit_code'].' was issued empty and cannot be returned with gas under current Shop Settings.');
            if($cfg['gas']>$issued+0.00001&&!$allowOver)throw new RuntimeException('Return gas for '.$row['unit_code'].' cannot exceed the issued gas quantity of '.number_format($issued, 2).' KG.');
            $rate=$cfg['rate']>0?$cfg['rate']:$row['issued_gas_rate'];
            if($cfg['gas']>0&&$rate<0)throw new RuntimeException('Return gas rate cannot be negative.');
            // Returned gas is valued at the rate it was issued at; a different rate is a POS rate change.
            if($cfg['gas']>0&&abs((float)$rate-(float)$row['issued_gas_rate'])>0.00001)$returnRateChanged=true;
            $returnById[$id]=['gas'=>$cfg['gas'],'rate'=>$rate,'consumed'=>max(0,$issued-$cfg['gas'])];
        }
        $rateChangeReason=$this->rateChangeReasonFor($returnRateChanged);

        $depositBalance=$this->securityDepositBalance($locationId,$customerId);
        if($refund>$depositBalance+0.01)throw new RuntimeException('Refund amount cannot exceed the customer refundable security deposit balance of Rs. '.number_format($depositBalance,2).'.');
        if($refund>0)$this->validateHeaderPayments($payments,$customerId,$refund,false);

        $returnLedger=0;
        foreach($rows as $row){$id=(int)$row['cylinder_unit_id'];$cfg=$returnById[$id];$returnLedger+=($cfg['gas']*$cfg['rate']);}
        $returnLedger=$affectsOs?round($returnLedger,2):0;

        $this->db->transBegin();
        try{
            foreach($selected as $id){
                $locked=$this->db->query("SELECT cu.*,ct.capacity_kg FROM cylinder_units cu JOIN cylinder_types ct ON ct.id=cu.cylinder_type_id JOIN cylinder_custody cc ON cc.cylinder_unit_id=cu.id AND cc.status='issued' WHERE cu.id=? AND cc.customer_id=? FOR UPDATE",[$id,$customerId])->getRowArray();
                if(!$locked)throw new RuntimeException('Cylinder selection changed while posting. Refresh and retry.');
            }
            $customer=$this->db->query("SELECT * FROM customers WHERE id=? FOR UPDATE",[$customerId])->getRowArray();
            if(!$customer||!(int)$customer['is_active'])throw new RuntimeException('Customer is unavailable.');

            $previousGasOs=$this->customerGasBalance($customerId);
            $newGasOs=$previousGasOs-$returnLedger;
            $depositBalanceAfter=$depositBalance-$refund;
            $overallOsAfter=$includeDepositOs?($newGasOs+$depositBalanceAfter):$newGasOs;

            $saleNo=$this->nextSaleNo($transactionAt,$locationId);
            $this->db->table('sales')->insert([
                'sale_no'=>$saleNo,'location_id'=>$locationId,'customer_id'=>$customerId,'transaction_type'=>'cylinder_return','status'=>'posted','transaction_at'=>$transactionAt,
                'total_kg'=>array_sum(array_map(static fn($x)=>(float)$x['gas'],array_values($returnById))),'subtotal'=>0,'discount_amount'=>0,'total_amount'=>0,
                'security_deposit_amount'=>0,'security_deposit_refund_amount'=>$refund,'credit_amount'=>0,'previous_os_balance'=>$previousGasOs,
                'receipt_amount'=>0,'net_receivable_amount'=>0,'os_balance'=>$overallOsAfter,'return_gas_ledger_amount'=>-$returnLedger,'custom_rate_flag'=>$returnRateChanged?1:0,'rate_change_reason'=>$rateChangeReason,'notes'=>$notes,'created_by'=>$userId
            ]);
            $saleId=(int)$this->db->insertID();

            foreach($rows as $idx=>$row){
                $id=(int)$row['cylinder_unit_id'];$cfg=$returnById[$id];$gas=$cfg['gas'];$rate=$cfg['rate'];$consumed=$cfg['consumed'];
                if($gas>0)$this->db->table('cylinder_units')->where('id',$id)->update(['status'=>'filled','custody_customer_id'=>null,'gas_weight_kg'=>$gas]);
                else $this->db->table('cylinder_units')->where('id',$id)->update(['status'=>'empty','custody_customer_id'=>null,'gas_weight_kg'=>0]);
                $this->db->table('cylinder_custody')->where('id',$row['id'])->update(['status'=>'returned','return_sale_id'=>$saleId,'refund_amount'=>($refund>0&&$idx===0?$refund:0),'returned_at'=>$transactionAt,'updated_by'=>$userId,'returned_gas_weight_kg'=>$gas,'consumed_gas_weight_kg'=>$consumed,'return_gas_rate'=>$rate]);
                if($refund>0&&$idx===0)$this->db->table('customer_security_deposits')->insert(['location_id'=>$locationId,'customer_id'=>$customerId,'entry_type'=>'refund','amount'=>$refund,'sale_id'=>$saleId,'custody_id'=>$row['id'],'transaction_at'=>$transactionAt,'created_by'=>$userId,'notes'=>'Security deposit refund']);
                $this->db->table('sale_items')->insert([
                    'sale_id'=>$saleId,'line_no'=>$idx+1,'line_type'=>$gas>0?'filled_cylinder':'empty_cylinder','cylinder_type_id'=>$row['cylinder_type_id'],
                    'customer_cylinder_unit_id'=>$id,'quantity'=>1,'gas_weight_kg'=>$gas,'applied_rate'=>$rate,'gas_rate'=>$rate,'cylinder_price'=>0,'standard_rate'=>$row['issued_gas_rate'],
                    'custom_rate_flag'=>$gas>0&&abs($rate-(float)$row['issued_gas_rate'])>0.00001?1:0,'rate_source'=>$gas>0?(abs($rate-(float)$row['issued_gas_rate'])>0.00001?'manual':'issued_rate'):null,'empty_cylinder_received'=>$gas<=0?1:0,'line_discount'=>0,'line_total'=>round($gas*$rate,2),
                    'notes'=>'Cylinder returned: '.$row['unit_code'].' | Issued '.$row['issued_gas_weight_kg'].' KG | Returned '.$gas.' KG | Consumed '.$consumed.' KG'
                ]);
                $lineId=(int)$this->db->insertID();
                $this->db->table('inventory_movements')->insert(['location_id'=>$locationId,'inventory_type'=>$gas>0?'filled_cylinder':'empty_cylinder','cylinder_type_id'=>$row['cylinder_type_id'],'quantity'=>1,'direction'=>'in','movement_at'=>$transactionAt,'source_type'=>'cylinder_return','source_id'=>$saleId,'source_line_id'=>$lineId,'cylinder_unit_id'=>$id,'created_by'=>$userId,'notes'=>$gas>0?'Partially filled cylinder returned':'Empty cylinder returned']);
                if($gas>0)$this->db->table('inventory_movements')->insert(['location_id'=>$locationId,'inventory_type'=>'gas_kg','cylinder_type_id'=>null,'quantity'=>$gas,'direction'=>'in','movement_at'=>$transactionAt,'source_type'=>'cylinder_return','source_id'=>$saleId,'source_line_id'=>$lineId,'cylinder_unit_id'=>$id,'created_by'=>$userId,'notes'=>'Returned gas added to shop stock']);
            }

            if($refund>0){
                // Refund payment is explicitly classified as a deposit refund.
                $this->insertSalePayments($saleId,$payments,$transactionAt,$userId,'security_deposit_refund');
                $cash=$this->paymentCash($payments);
                if($cash>0){
                    $s=$this->cash->openSessionForLocation($locationId);if(!$s)throw new RuntimeException('Open the counter cash session before refunding a security deposit.');
                    $this->cash->postGeneric((int)$s['id'],'security_deposit_refund','out',$cash,'sale',$saleId,$userId,'Security deposit refund',$transactionAt);
                }
            }
            if(!$this->db->transStatus())throw new RuntimeException('Cylinder return failed.');
            $this->db->transCommit();
            return ['id'=>$saleId,'sale_no'=>$saleNo,'total'=>0,'customer_id'=>$customerId,'credit_amount'=>0];
        }catch(\Throwable $e){$this->db->transRollback();throw $e;}
    }

    protected function prepareSalePaymentPlan(array $payments,?int $customerId,float $saleTotal): array
    {
        foreach($payments as $p){
            $type=(string)($p['payment_type']??'sale');
            if($type!=='sale')throw new RuntimeException('Gas/cylinder sale payments must be classified as Sale.');
        }
        if(!$payments)throw new RuntimeException('At least one payment is required.');
        $paymentTotal=0;
        foreach($payments as $p){
            $mode=(string)($p['payment_mode']??'');$amount=(float)($p['amount']??0);
            if(!in_array($mode,['cash','cheque','online','credit'],true)||$amount<0)throw new RuntimeException('Invalid payment.');
            // Empty/zero payment rows are allowed in the POS. They carry no collection;
            // a zero Credit row may also explicitly represent an unpaid customer sale.
            if(!$customerId&&$mode==='credit')throw new RuntimeException('Credit sale is not allowed for Walk-in / Cash customer.');
            $paymentTotal+=$amount;
        }
        // Walk-in / Cash is strictly cash-only and must be fully settled at posting.
        // Do not let an underpayment silently become credit via the unpaid remainder.
        if($customerId===null && abs($paymentTotal-$saleTotal)>0.01){
            throw new RuntimeException($paymentTotal<$saleTotal-0.01
                ? 'Credit sale is not allowed for Walk-in / Cash customer. A walk-in sale must be fully paid at posting time.'
                : 'Payment cannot exceed the walk-in sale amount.');
        }
        $previousOs=$customerId?$this->customerGasBalance($customerId):0;
        $maxReceivable=max(0,round($saleTotal+$previousOs,2));
        if($paymentTotal>$maxReceivable+0.01)throw new RuntimeException('Payment cannot exceed the customer net receivable of Rs. '.number_format($maxReceivable,2).'.');

        // A customer payment may be less than the current sale amount. The unpaid
        // remainder of the current sale is customer credit, subject to the same
        // credit-eligibility and credit-limit rules used by Gas Sale.
        $salePayments=[];$settlements=[];$remainingSale=$saleTotal;$remainingOs=$previousOs;
        foreach($payments as $p){
            $amount=(float)$p['amount'];$mode=(string)$p['payment_mode'];
            if($remainingOs>0.00001 && $amount>0){
                if($mode==='credit')throw new RuntimeException('Credit cannot be used to settle a previous customer OS balance.');
                $toOs=min($amount,$remainingOs);
                $settlements[]=['payment_mode'=>$mode,'amount'=>$toOs,'reference_no'=>$p['reference_no']??null];
                $remainingOs-=$toOs;$amount-=$toOs;
            }
            if($amount>0){
                $toSale=min($amount,$remainingSale);
                $salePayments[]=['payment_mode'=>$mode,'amount'=>$toSale,'reference_no'=>$p['reference_no']??null];
                $remainingSale-=$toSale;$amount-=$toSale;
            }
            if($amount>0.00001)throw new RuntimeException('Payment exceeds the customer net receivable.');
        }
        // The unpaid portion of the current sale becomes credit. If the cashier
        // explicitly uses a Credit tender, retain that credit tender as part of the
        // current-sale credit as well.
        $explicitCreditAmount=array_sum(array_map(static fn($p)=>(string)$p['payment_mode']==='credit'?(float)$p['amount']:0,$salePayments));
        $creditAmount=max(0,$remainingSale)+$explicitCreditAmount;
        $newOs=round($remainingOs+$creditAmount,2);
        if($customerId!==null && $newOs>0.01){
            $customerForCredit=$this->customers->find($customerId);
            if(!$customerForCredit || !(int)$customerForCredit['is_active']) throw new RuntimeException('Customer is unavailable.');
            if(!(int)($customerForCredit['allow_credit_sale']??0)){
                throw new RuntimeException('Credit sale is not allowed for this customer. Enable Allow Credit Sale on the customer record.');
            }

            $shopSettings=(new ShopSettingsModel())->forLocation($this->currentLocationId ?? 0);
            $creditMode=(string)($shopSettings['credit_limit_validation_mode']??'none');
            $settlementTotal=array_sum(array_map(static fn($p)=>(float)$p['amount'],$settlements));

            if($creditMode==='customer' || ($creditMode==='none' && (float)($customerForCredit['credit_limit']??0)>0)){
                // A configured customer credit limit is authoritative for that customer.
                // This also prevents a configured limit from being displayed as Unlimited
                // while the server silently allows unlimited credit.
                $creditLimit=(float)($customerForCredit['credit_limit']??0);
                if($creditLimit>0 && $newOs>$creditLimit+0.01){
                    $available=max(0,$creditLimit-max(0,$previousOs));
                    throw new RuntimeException('Customer credit limit exceeded. Existing OS Rs. '.number_format($previousOs,2).'; available additional credit Rs. '.number_format($available,2).'.');
                }
            }elseif($creditMode==='shop'){
                $this->acquireCreditLimitLock($this->currentLocationId ?? 0);
                $shopOs=$this->shopOutstanding($this->currentLocationId ?? 0);
                $advanceApplied=min($creditAmount,max(0,-$previousOs));
                $projectedShopOs=max(0,$shopOs-$settlementTotal+$creditAmount-$advanceApplied);
                $shopLimit=(float)($shopSettings['shop_credit_limit']??0);
                if($projectedShopOs>$shopLimit+0.01){
                    $available=max(0,$shopLimit-($shopOs-$settlementTotal));
                    throw new RuntimeException('Shop credit limit exceeded. Current shop OS Rs. '.number_format($shopOs,2).'; available additional credit Rs. '.number_format($available,2).'.');
                }
            }
        }
        return ['sale_payments'=>$salePayments,'settlements'=>$settlements,'credit_amount'=>$creditAmount,'previous_os'=>$previousOs,'payment_total'=>$paymentTotal,'net_receivable'=>$maxReceivable,'remaining_os'=>$newOs];
    }

    protected function shopOutstanding(int $locationId): float
    {
        $sql="SELECT COALESCE(SUM(CASE WHEN (c.opening_balance+COALESCE(s.credit,0)+COALESCE(s.return_ledger,0)-COALESCE(r.paid,0))>0 THEN (c.opening_balance+COALESCE(s.credit,0)+COALESCE(s.return_ledger,0)-COALESCE(r.paid,0)) ELSE 0 END),0) AS shop_os
              FROM customers c
              LEFT JOIN (SELECT customer_id,SUM(credit_amount) credit,SUM(return_gas_ledger_amount) return_ledger FROM sales WHERE location_id=? AND status='posted' AND customer_id IS NOT NULL GROUP BY customer_id) s ON s.customer_id=c.id
              LEFT JOIN (SELECT customer_id,SUM(amount) paid FROM customer_receipts WHERE location_id=? AND status='posted' GROUP BY customer_id) r ON r.customer_id=c.id";
        $row=$this->db->query($sql,[$locationId,$locationId])->getRowArray();
        return (float)($row['shop_os']??0);
    }

    protected function acquireCreditLimitLock(int $locationId): void
    {
        if($locationId<=0) throw new RuntimeException('Invalid shop location for credit-limit validation.');
        $lockKey='lpg_credit_limit_'.$locationId;
        $result=$this->db->query("SELECT GET_LOCK(?,5) AS locked",[$lockKey])->getRowArray();
        if((int)($result['locked']??0)!==1) throw new RuntimeException('Credit limit is busy; please retry the transaction.');
        $this->creditLocks[]=$lockKey;
    }

    protected function postCustomerSettlement(array $settlements,int $locationId,int $saleId,?int $customerId,string $at,int $userId): void
    {
        if(!$settlements || $customerId===null)return;
        foreach($settlements as $p){
            $amount=(float)$p['amount'];$mode=(string)$p['payment_mode'];
            $no='R'.date('YmdHis').'-'.random_int(100,999);
            $this->db->table('customer_receipts')->insert([
                'receipt_no'=>$no,'location_id'=>$locationId,'customer_id'=>$customerId,'status'=>'posted',
                'amount'=>$amount,'payment_mode'=>$mode,'receipt_at'=>$at,
                'reference_no'=>trim((string)($p['reference_no']??''))?:null,
                'notes'=>'OS settlement collected with sale '.$saleId,'created_by'=>$userId
            ]);
            if($mode==='cash'){
                $s=$this->cash->openSessionForLocation($locationId);
                if(!$s)throw new RuntimeException('Open the counter cash session before receiving an OS settlement.');
                $this->cash->postGeneric((int)$s['id'],'customer_receipt','in',$amount,'customer_receipt',(int)$this->db->insertID(),$userId,'Customer OS settlement',$at);
            }
        }
    }

    protected function validateHeaderPayments(array $payments,?int $customerId,float $expected,bool $allowCredit=true): void
    {
        if(!$payments)throw new RuntimeException('At least one payment is required.');
        $total=0;
        foreach($payments as $p){$mode=(string)($p['payment_mode']??'');$amount=(float)($p['amount']??0);if(!in_array($mode,['cash','cheque','online','credit'],true)||$amount<=0)throw new RuntimeException('Invalid payment.');if(!$customerId&&$mode==='credit')throw new RuntimeException('Credit sale is not allowed for Walk-in / Cash customer.');if(!$allowCredit&&$mode==='credit')throw new RuntimeException('Credit payment is not allowed for this transaction.');$total+=$amount;}
        if(abs($total-$expected)>0.01)throw new RuntimeException('Payment total must equal the net amount payable.');
    }

    protected function insertSalePayments(int $saleId,array $payments,string $at,int $userId,string $paymentType='sale'): void
    {
        foreach($payments as $p){
            $this->db->table('sale_payments')->insert([
                'sale_id'=>$saleId,'payment_type'=>$paymentType,'payment_mode'=>$p['payment_mode'],'amount'=>(float)$p['amount'],
                'reference_no'=>trim((string)($p['reference_no']??''))?:null,'payment_at'=>$at,'received_by'=>$userId
            ]);
        }
    }

    protected function securityDepositBalance(int $locationId,int $customerId): float
    {
        $row=$this->db->query(
            "SELECT COALESCE(SUM(CASE WHEN entry_type='hold' THEN amount ELSE -amount END),0) AS balance
             FROM customer_security_deposits WHERE location_id=? AND customer_id=?",
            [$locationId,$customerId]
        )->getRowArray();
        return max(0,(float)($row['balance']??0));
    }

    protected function customerGasBalance(int $customerId): float
    {
        $s=$this->db->table('sales')->selectSum('credit_amount','credit')->selectSum('return_gas_ledger_amount','return_ledger')
            ->where('customer_id',$customerId)->where('status','posted')->where('location_id',$this->currentLocationId)->get()->getRowArray();
        $r=$this->db->table('customer_receipts')->selectSum('amount','paid')
            ->where('customer_id',$customerId)->where('status','posted')->where('location_id',$this->currentLocationId)->get()->getRowArray();
        $c=$this->customers->find($customerId);
        return (float)($c['opening_balance']??0)+(float)($s['credit']??0)+(float)($s['return_ledger']??0)-(float)($r['paid']??0);
    }

    protected function paymentCash(array $payments): float {return array_sum(array_map(static fn($p)=>(string)($p['payment_mode'])==='cash'?(float)$p['amount']:0,$payments));}
    protected function paymentCredit(array $payments): float {return array_sum(array_map(static fn($p)=>(string)($p['payment_mode'])==='credit'?(float)$p['amount']:0,$payments));}
    protected function paymentCustom(array $rows): int {return array_reduce($rows,static fn($c,$r)=>$c||(int)($r['custom_rate_flag']??0)?1:0,0);}

    public function void(int $saleId,int $userId,int $locationId,string $reason): string
    {
        $reason=trim($reason);
        if($reason==='') throw new RuntimeException('Void reason is required.');
        $this->db->transBegin();
        try{
            $sale=$this->db->query("SELECT * FROM sales WHERE id=? AND location_id=? FOR UPDATE",[$saleId,$locationId])->getRowArray();
            if(!$sale) throw new RuntimeException('Sale not found.');
            if($sale['status']!=='posted') throw new RuntimeException('Only posted sales can be voided.');
            if(in_array($sale['transaction_type'],['security_deposit','cylinder_return'],true)) throw new RuntimeException('Security Deposit and Cylinder Return transactions cannot be voided. Use the custody workflow so the liability and physical cylinder remain consistent.');
            if($sale['transaction_type']==='gas_sale'){
                $custodyLine=(int)$this->db->table('sale_items')->where('sale_id',$saleId)->where('customer_cylinder_unit_id IS NOT NULL',null,false)->countAllResults();
                if($custodyLine>0) throw new RuntimeException('Gas sales that refill a customer custody cylinder cannot be voided after posting.');
            }

            $movements=$this->db->table('inventory_movements')->where('source_type','sale')->where('source_id',$saleId)->get()->getResultArray();
            $this->acquireInventoryLocks($locationId,$movements);

            // A single physical filled-cylinder sale/refill can have two OUT movements:
            // gas_kg and filled_cylinder. Restore the physical cylinder only once.
            // When a gas sale empties a filled cylinder, it also has an empty_cylinder IN
            // movement. In both cases restoreFilled() must handle the gas exactly once.
            $soldFilledUnitIds=[];
            foreach($movements as $m){
                $unitId=(int)($m['cylinder_unit_id']??0);
                if($unitId && $m['inventory_type']==='filled_cylinder' && $m['direction']==='out'){
                    $soldFilledUnitIds[$unitId]=true;
                }
            }

            foreach($movements as $m){
                $reverse=$m['direction']==='in'?'out':'in';
                $this->db->table('inventory_movements')->insert([
                    'location_id'=>$m['location_id'],'inventory_type'=>$m['inventory_type'],'cylinder_type_id'=>$m['cylinder_type_id'],
                    'quantity'=>$m['quantity'],'direction'=>$reverse,'movement_at'=>date('Y-m-d H:i:s'),
                    'source_type'=>'sale_void','source_id'=>$saleId,'source_line_id'=>$m['source_line_id']??null,
                    'cylinder_unit_id'=>$m['cylinder_unit_id']??null,'created_by'=>$userId,
                    'notes'=>'Reversal of sale '.$sale['sale_no']
                ]);

                $unitId=(int)($m['cylinder_unit_id']??0);
                if(!$unitId) continue;

                if($m['inventory_type']==='gas_kg' && $m['direction']==='out'){
                    // For a physical filled-cylinder OUT in this same sale, defer gas
                    // restoration to the filled_cylinder branch below to avoid double restore.
                    if(!isset($soldFilledUnitIds[$unitId])) $this->cylinders->restoreGas($unitId,(float)$m['quantity']);
                }elseif($m['inventory_type']==='filled_cylinder' && $m['direction']==='out'){
                    $gasRow=$this->db->table('inventory_movements')
                        ->where('source_type','sale')->where('source_id',$saleId)
                        ->where('inventory_type','gas_kg')->where('direction','out')
                        ->where('cylinder_unit_id',$unitId)->orderBy('id')->get()->getRowArray();
                    $this->cylinders->restoreFilled($unitId,(float)($gasRow['quantity']??0));
                }elseif($m['inventory_type']==='empty_cylinder' && $m['direction']==='out'){
                    $this->cylinders->restoreEmpty($unitId);
                }elseif($m['inventory_type']==='empty_cylinder' && $m['direction']==='in'){
                    // This represents a cylinder that became empty during a gas sale.
                    // restoreFilled() above already restores it to its original filled state.
                    $converted=isset($soldFilledUnitIds[$unitId]);
                    if(!$converted) $this->cylinders->markSold($unitId);
                }
            }
            $cashRows=$this->db->table('cash_transactions')->where('reference_type','sale')->where('reference_id',$saleId)->where('transaction_type','sale_cash')->where('direction','in')->get()->getResultArray();
            $existingReversal=(int)$this->db->table('cash_transactions')->where('reference_type','sale_void')->where('reference_id',$saleId)->where('transaction_type','sale_cash')->where('direction','out')->countAllResults();
            if($existingReversal>0) throw new RuntimeException('Cash reversal for this sale already exists.');
            foreach($cashRows as $cash){
                $this->db->table('cash_transactions')->insert([
                    'cash_session_id'=>$cash['cash_session_id'],'transaction_type'=>'sale_cash','direction'=>'out',
                    'amount'=>$cash['amount'],'transaction_at'=>date('Y-m-d H:i:s'),'reference_type'=>'sale_void',
                    'reference_id'=>$saleId,'created_by'=>$userId,'notes'=>'Cash reversal of sale '.$sale['sale_no']
                ]);
            }

            $settlementReceipts=$this->db->table('customer_receipts')
                ->where(['location_id'=>$locationId,'status'=>'posted'])
                ->where('customer_id', $sale['customer_id'])
                ->where('notes','OS settlement collected with sale '.$saleId)
                ->get()->getResultArray();
            foreach($settlementReceipts as $receipt){
                $receiptId=(int)$receipt['id'];
                $this->db->table('customer_receipts')->where('id',$receiptId)->update(['status'=>'voided']);

                $receiptCashRows=$this->db->table('cash_transactions')
                    ->where([
                        'reference_type'=>'customer_receipt',
                        'reference_id'=>$receiptId,
                        'transaction_type'=>'customer_receipt',
                        'direction'=>'in'
                    ])->get()->getResultArray();

                foreach($receiptCashRows as $receiptCash){
                    $alreadyReversed=(int)$this->db->table('cash_transactions')
                        ->where([
                            'reference_type'=>'sale_void',
                            'reference_id'=>$saleId,
                            'transaction_type'=>'customer_receipt',
                            'direction'=>'out',
                            'cash_session_id'=>$receiptCash['cash_session_id']
                        ])->where('notes','Reversal of customer receipt '.$receipt['receipt_no'])->countAllResults();
                    if($alreadyReversed===0){
                        $this->db->table('cash_transactions')->insert([
                            'cash_session_id'=>$receiptCash['cash_session_id'],
                            'transaction_type'=>'customer_receipt',
                            'direction'=>'out',
                            'amount'=>$receiptCash['amount'],
                            'transaction_at'=>date('Y-m-d H:i:s'),
                            'reference_type'=>'sale_void',
                            'reference_id'=>$saleId,
                            'created_by'=>$userId,
                            'notes'=>'Reversal of customer receipt '.$receipt['receipt_no'].' from voided sale '.$sale['sale_no']
                        ]);
                    }
                }
            }

            $this->db->table('sales')->where('id',$saleId)->update(['status'=>'voided','voided_by'=>$userId,'voided_at'=>date('Y-m-d H:i:s'),'void_reason'=>$reason]);
            if(!$this->db->transStatus()) throw new RuntimeException('Sale void failed.');
            $this->db->transCommit();
            $this->releaseInventoryLocks();
            return $sale['sale_no'];
        }catch(\Throwable $e){
            $this->releaseInventoryLocks();
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Bulk equivalent of customerBalance() for many customers: three grouped queries instead
     * of several queries per customer. Returns [customer_id => balance] using identical rules.
     *
     * @param array<int,array<string,mixed>> $customers rows containing at least id and opening_balance
     * @return array<int,float>
     */
    public function customerBalances(array $customers): array
    {
        if(!$customers)return [];
        $locationId=(int)$this->currentLocationId;
        $sales=[];
        foreach($this->db->table('sales')->select('customer_id,SUM(credit_amount) AS credit,SUM(return_gas_ledger_amount) AS return_ledger')
            ->where('location_id',$locationId)->where('status','posted')->where('customer_id IS NOT NULL',null,false)->groupBy('customer_id')->get()->getResultArray() as $r){
            $sales[(int)$r['customer_id']]=(float)$r['credit']+(float)$r['return_ledger'];
        }
        $paid=[];
        foreach($this->db->table('customer_receipts')->select('customer_id,SUM(amount) AS paid')
            ->where('location_id',$locationId)->where('status','posted')->groupBy('customer_id')->get()->getResultArray() as $r){
            $paid[(int)$r['customer_id']]=(float)$r['paid'];
        }
        $deposits=[];
        $settings=(new ShopSettingsModel())->forLocation($locationId);
        if((int)($settings['include_security_deposit_in_os']??0)===1){
            foreach($this->db->table('customer_security_deposits')->select("customer_id,SUM(CASE WHEN entry_type='hold' THEN amount ELSE -amount END) AS balance")
                ->where('location_id',$locationId)->groupBy('customer_id')->get()->getResultArray() as $r){
                $deposits[(int)$r['customer_id']]=max(0,(float)$r['balance']);
            }
        }
        $balances=[];
        foreach($customers as $c){
            $id=(int)$c['id'];
            $balances[$id]=(float)($c['opening_balance']??0)+($sales[$id]??0.0)-($paid[$id]??0.0)+($deposits[$id]??0.0);
        }
        return $balances;
    }

    public function customerBalance(int $customerId): float
    {
        $gasBalance=$this->customerGasBalance($customerId);
        $settings=(new ShopSettingsModel())->forLocation($this->currentLocationId ?? 0);
        if((int)($settings['include_security_deposit_in_os']??0)!==1)return $gasBalance;
        return $gasBalance+$this->securityDepositBalance((int)$this->currentLocationId,$customerId);
    }

    protected function acquireInventoryLocks(int $locationId,array $movements): void
    {
        $keys=[];
        foreach($movements as $m){
            $type=(string)($m['type']??$m['inventory_type']??'');
            $typeId=$m['cylinder_type_id']??null;
            if($type==='') throw new RuntimeException('Invalid inventory lock key.');
            $keys[$type.'|'.($typeId===null?'null':(string)$typeId)]=true;
        }
        $keys=array_keys($keys);
        sort($keys,SORT_STRING);
        foreach($keys as $key){
            $lockKey='lpg_inv_'.$locationId.'_'.substr(hash('sha256',$key),0,48);
            $result=$this->db->query("SELECT GET_LOCK(?,5) AS locked",[$lockKey])->getRowArray();
            if((int)($result['locked']??0)!==1) throw new RuntimeException('Inventory is busy; please retry the transaction.');
            $this->inventoryLocks[]=$lockKey;
        }
    }

    protected function releaseInventoryLocks(): void
    {
        foreach(array_reverse($this->creditLocks) as $lockKey){
            $this->db->query("SELECT RELEASE_LOCK(?) AS released",[$lockKey]);
        }
        $this->creditLocks=[];
        foreach(array_reverse($this->inventoryLocks) as $lockKey){
            $this->db->query("SELECT RELEASE_LOCK(?) AS released",[$lockKey]);
        }
        $this->inventoryLocks=[];
    }

    protected function assertStock(int $locationId,string $type,?int $typeId,float $qty,string $direction,string $at): void
 {
  if($direction!=='out') return;

  if($type==='gas_kg'){
   $row=$this->db->table('cylinder_units')
    ->selectSum('gas_weight_kg','qty')
    ->where(['location_id'=>$locationId,'status'=>'filled'])
    ->get()->getRowArray();

   $stock=(float)($row['qty']??0);
   if($stock+0.00001<$qty){
    throw new RuntimeException(
     'Insufficient gas stock. Available gas is derived from filled physical cylinders: ' .
     number_format($stock, 2) . ' KG.'
    );
   }
   return;
  }

  $status=$type==='filled_cylinder'?'filled':($type==='empty_cylinder'?'empty':null);
  if($status===null) throw new RuntimeException('Invalid inventory type.');

  $q=$this->db->table('cylinder_units')->where([
   'location_id'=>$locationId,
   'cylinder_type_id'=>$typeId,
   'status'=>$status
  ])->countAllResults();

  if((float)$q+0.00001<$qty){
   throw new RuntimeException('Insufficient '.$type.' stock.');
  }
 }
}
