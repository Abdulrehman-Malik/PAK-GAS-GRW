<?php

namespace App\Controllers;

use App\Models\CustomerModel;
use App\Models\CustomerRateModel;
use App\Models\CylinderTypeModel;
use App\Models\GasRateModel;
use App\Models\RateCardModel;
use App\Models\RateChangeLogModel;
use App\Models\ShopSettingsModel;
use App\Services\AuditService;
use App\Services\PermissionService;
use App\Services\RateResolver;
use CodeIgniter\Controller;
use Config\Database;

/**
 * LPG Rates: a price grid per price list (Retail / Wholesale), customer special rates and the rate history.
 * Rates are never edited or deleted once they have started: a new price is a new dated row,
 * a current price can be stopped, and only a scheduled (not yet started) price can be cancelled.
 * Price-list rates live in rate_cards, customer special rates in customer_rates. Both follow the same rules.
 */
class Rates extends Controller
{
    public const ITEMS = ['gas_per_kg'=>'Gas per KG','gas_per_cylinder'=>'Gas per full cylinder','empty_cylinder'=>'Empty cylinder price'];
    protected const PAST_GRACE_SECONDS = 3600;

    protected GasRateModel $rates;
    protected CustomerRateModel $customerRates;
    protected CylinderTypeModel $types;
    public function __construct(){ $this->rates=new GasRateModel(); $this->customerRates=new CustomerRateModel(); $this->types=new CylinderTypeModel(); }

    private function guard(): ?\CodeIgniter\HTTP\ResponseInterface
    {
        if(!PermissionService::allows('RATE_MANAGE')) return $this->response->setStatusCode(403)->setBody('Forbidden');
        return null;
    }

    private function locationId(): int { return (int)(session()->get('location_id') ?? 0); }
    private function userId(): int { return (int)session()->get('user_id'); }
    private function activeTypes(): array { return $this->types->where('is_active',1)->orderBy('sort_order')->findAll(); }

    public function index()
    {
        if($r=$this->guard()) return $r;
        $locationId=$this->locationId();
        $now=date('Y-m-d H:i:s');
        $settings=(new ShopSettingsModel())->forLocation($locationId);
        $types=$this->activeTypes();
        $resolver=new RateResolver($locationId,$settings);

        $grid=[];
        foreach(RateResolver::PRICE_LISTS as $list){
            $grid[$list]=$this->gridFor($this->rates,$locationId,$list,$types,$now)+['book'=>$resolver->priceBook($list,$types,$now)];
        }

        // Customer special rates: the selected customer's grid, with what they would pay without special rates.
        $customers=(new CustomerModel())->where('is_active',1)->orderBy('name')->findAll();
        $customerId=(int)$this->request->getGet('customer');
        $customer=null;$customerGrid=null;$customerHistory=[];$customerList='retail';
        foreach($customers as $c) if((int)$c['id']===$customerId) $customer=$c;
        if($customer){
            $customerList=RateResolver::defaultPriceList($settings,$customer);
            $customerGrid=$this->gridFor($this->customerRates,$locationId,(int)$customer['id'],$types,$now)+['book'=>$resolver->priceBook($customerList,$types,$now)];
            $customerHistory=$this->withStatus($this->customerRates->history($locationId,(int)$customer['id']),$now);
        }

        return view('rates/index',[
            'title'=>'LPG Rates','types'=>$types,'grid'=>$grid,'history'=>$this->withStatus((new RateCardModel())->history($locationId),$now),
            'wholesaleEnabled'=>(int)($settings['wholesale_price_list_enabled']??1)===1,'items'=>self::ITEMS,
            'specialEnabled'=>(int)($settings['customer_special_rates_enabled']??1)===1,
            'customers'=>$customers,'specialCounts'=>$this->customerRates->activeCounts($locationId,$now),
            'customer'=>$customer,'customerGrid'=>$customerGrid,'customerHistory'=>$customerHistory,'customerList'=>$customerList,
        ]);
    }

    /**
     * Current and next rate for every grid cell of one price list or one customer.
     * $scope is the price list (rate_cards) or the customer id (customer_rates).
     */
    private function gridFor($model, int $locationId, $scope, array $types, string $now): array
    {
        $cell=fn(string $item,?int $typeId)=>['current'=>$model->effectiveRow($locationId,$scope,$item,$typeId,$now),'next'=>$model->nextRow($locationId,$scope,$item,$typeId,$now)];
        $grid=['gas_per_kg'=>$cell('gas_per_kg',null),'types'=>[]];
        foreach($types as $t){
            foreach(['gas_per_cylinder','empty_cylinder'] as $item) $grid['types'][(string)$t['id']][$item]=$cell($item,(int)$t['id']);
        }
        return $grid;
    }

    /** Adds a status per history row: scheduled, current, stopped or old. */
    private function withStatus(array $rows, string $now): array
    {
        $started=[];
        foreach($rows as $i=>$row){
            if($row['effective_from']>$now){ $rows[$i]['status']='scheduled'; continue; }
            $started[($row['price_list']??'').'|'.($row['customer_id']??'').'|'.$row['rate_type'].'|'.(int)$row['cylinder_type_id']][]=$i;
        }
        foreach($started as $indexes){
            usort($indexes,static fn($a,$b)=>[$rows[$b]['effective_from'],(int)$rows[$b]['id']]<=>[$rows[$a]['effective_from'],(int)$rows[$a]['id']]);
            foreach($indexes as $n=>$i){
                $stopped=$rows[$i]['effective_to']!==null && $rows[$i]['effective_to']<=$now;
                $rows[$i]['status']=$stopped?'stopped':($n===0?'current':'old');
            }
        }
        return $rows;
    }

    /** Saves the changed prices of one price list as new dated rates. */
    public function save()
    {
        if($r=$this->guard()) return $r;
        $list=(string)$this->request->getPost('price_list');
        if(!in_array($list,RateResolver::PRICE_LISTS,true)) return redirect()->to('/rates')->with('error','Invalid price list.');
        return $this->saveGrid($this->rates,$list,['price_list'=>$list],redirect()->to('/rates#rates-'.$list),'rate_card');
    }

    /** Saves the changed special prices of one customer as new dated rates. */
    public function saveCustomer()
    {
        if($r=$this->guard()) return $r;
        $customer=(new CustomerModel())->where('is_active',1)->find((int)$this->request->getPost('customer_id'));
        if(!$customer) return redirect()->to('/rates#rates-customer')->with('error','Customer is invalid or inactive.');
        $id=(int)$customer['id'];
        return $this->saveGrid($this->customerRates,$id,['customer_id'=>$id],redirect()->to('/rates?customer='.$id.'#rates-customer'),'customer_rate');
    }

    /**
     * Validates the posted grid and inserts one dated row per changed price, with a change log and audit entry.
     * $scope is the price list or customer id the model is keyed by. $key holds the matching column.
     */
    private function saveGrid($model, $scope, array $key, $back, string $entity)
    {
        $locationId=$this->locationId();
        $effectiveRaw=str_replace('T',' ',trim((string)$this->request->getPost('effective_from')));
        $ts=$effectiveRaw===''?false:strtotime($effectiveRaw);
        if($ts===false) return $back->withInput()->with('error','Enter a valid Effective From date and time.');
        // A rate cannot start in the past, which would rewrite which price was in force.
        // A time up to an hour old is the form's "now" default from when the page was opened.
        if($ts<time()){
            if($ts<time()-self::PAST_GRACE_SECONDS) return $back->withInput()->with('error','Effective From cannot be in the past. Use the current time or a future time.');
            $ts=time();
        }
        $effective=date('Y-m-d H:i:s',$ts);
        $reason=trim((string)$this->request->getPost('reason'))?:null;

        $posted=(array)$this->request->getPost('rates');
        $entries=[['gas_per_kg',null,$posted['gas_per_kg']??'']];
        $activeIds=array_map('intval',array_column($this->activeTypes(),'id'));
        foreach((array)($posted['types']??[]) as $typeId=>$values){
            if(!in_array((int)$typeId,$activeIds,true)) return $back->withInput()->with('error','Invalid or inactive cylinder type.');
            foreach(['gas_per_cylinder','empty_cylinder'] as $item) $entries[]=[$item,(int)$typeId,((array)$values)[$item]??''];
        }

        $changes=[];
        foreach($entries as [$item,$typeId,$raw]){
            $raw=trim((string)$raw);
            if($raw==='') continue; // blank keeps the price in force
            if(!is_numeric($raw) || (float)$raw<=0) return $back->withInput()->with('error','Rates must be numbers greater than zero. To remove a price, use Stop.');
            $value=round((float)$raw,4);
            $inForce=$model->effective($locationId,$scope,$item,$typeId,$effective);
            if($inForce!==null && abs($inForce-$value)<0.00005) continue;
            $sameStart=$model->where(['location_id'=>$locationId,'rate_type'=>$item,'effective_from'=>$effective]+$key)->where('cylinder_type_id',$typeId)->first();
            if($sameStart) return $back->withInput()->with('error','The '.strtolower(self::ITEMS[$item]).' already has a rate starting at that exact time. Choose another Effective From time or cancel the scheduled rate.');
            $changes[]=['item'=>$item,'type_id'=>$typeId,'value'=>$value,'old'=>$inForce];
        }
        if(!$changes) return $back->with('success','No rate changes to save.');

        $db=Database::connect();
        $db->transBegin();
        try{
            $log=new RateChangeLogModel();
            foreach($changes as $c){
                $row=['location_id'=>$locationId,'rate_type'=>$c['item'],'cylinder_type_id'=>$c['type_id'],'rate_value'=>$c['value'],
                    'effective_from'=>$effective,'created_by'=>$this->userId(),'created_at'=>date('Y-m-d H:i:s')]+$key;
                $rateId=(int)$model->insert($row,true);
                if($rateId<=0) throw new \RuntimeException('Rate could not be saved.');
                $log->insert($this->logRow($entity,$rateId,$locationId,$row,$c['old'],$c['value'],$reason));
                AuditService::log('CREATE',$entity,$rateId,null,$row,$this->userId(),$locationId);
            }
            if(!$db->transStatus()) throw new \RuntimeException('Rates could not be saved.');
            $db->transCommit();
        }catch(\Throwable $e){
            $db->transRollback();
            return $back->withInput()->with('error',$e->getMessage());
        }
        return $back->with('success',count($changes).' rate change(s) saved. They apply from '.date('d-M-Y H:i',$ts).'.');
    }

    /** A rate_change_log row for a price-list rate (rate_card) or a customer special rate (customer_rate). */
    private function logRow(string $entity, ?int $rateId, int $locationId, array $row, $old, float $new, ?string $reason): array
    {
        $customer=$entity==='customer_rate';
        return ['rate_card_id'=>$customer?null:$rateId,'customer_rate_id'=>$customer?$rateId:null,'location_id'=>$locationId,
            'price_list'=>$customer?null:$row['price_list'],'customer_id'=>$customer?$row['customer_id']:null,
            'rate_type'=>$row['rate_type'],'cylinder_type_id'=>$row['cylinder_type_id'],'old_rate'=>$old,'new_rate'=>$new,'changed_by'=>$this->userId(),'reason'=>$reason];
    }

    /** Ends the current price now. The rate stays in history; POS then falls back (Wholesale uses Retail). */
    public function stop(int $id)
    {
        if($r=$this->guard()) return $r;
        $row=$this->rates->where(['id'=>$id,'location_id'=>$this->locationId()])->first();
        if(!$row) return redirect()->to('/rates')->with('error','Rate not found.');
        return $this->stopRow($this->rates,$row,$row['price_list'],redirect()->to('/rates#rates-'.$row['price_list']),'rate_card');
    }

    /** Ends a customer's current special price now; POS then uses the customer's price list. */
    public function stopCustomer(int $id)
    {
        if($r=$this->guard()) return $r;
        $row=$this->customerRates->where(['id'=>$id,'location_id'=>$this->locationId()])->first();
        if(!$row) return redirect()->to('/rates#rates-customer')->with('error','Rate not found.');
        return $this->stopRow($this->customerRates,$row,(int)$row['customer_id'],redirect()->to('/rates?customer='.(int)$row['customer_id'].'#rates-customer'),'customer_rate');
    }

    private function stopRow($model, array $row, $scope, $back, string $entity)
    {
        $locationId=$this->locationId();
        $now=date('Y-m-d H:i:s');
        $id=(int)$row['id'];
        $current=$model->effectiveRow($locationId,$scope,$row['rate_type'],$row['cylinder_type_id']!==null?(int)$row['cylinder_type_id']:null,$now);
        if(!$current || (int)$current['id']!==$id) return $back->with('error','Only the price currently in force can be stopped.');

        return $this->changeInTransaction($back,function() use ($model,$row,$id,$locationId,$now,$entity){
            $model->update($id,['effective_to'=>$now]);
            (new RateChangeLogModel())->insert($this->logRow($entity,$id,$locationId,$row,$row['rate_value'],0,'Price stopped'));
            AuditService::log('STOP',$entity,$id,['effective_to'=>null],['effective_to'=>$now],$this->userId(),$locationId);
        },'Price stopped. It stays in the rate history.');
    }

    /** Removes a scheduled price that has not started yet, so it was never used at POS. */
    public function cancel(int $id)
    {
        if($r=$this->guard()) return $r;
        $row=$this->rates->where(['id'=>$id,'location_id'=>$this->locationId()])->first();
        if(!$row) return redirect()->to('/rates')->with('error','Rate not found.');
        return $this->cancelRow($this->rates,$row,redirect()->to('/rates#rates-history'),'rate_card');
    }

    /** Removes a customer's scheduled special price that has not started yet. */
    public function cancelCustomer(int $id)
    {
        if($r=$this->guard()) return $r;
        $row=$this->customerRates->where(['id'=>$id,'location_id'=>$this->locationId()])->first();
        if(!$row) return redirect()->to('/rates#rates-customer')->with('error','Rate not found.');
        return $this->cancelRow($this->customerRates,$row,redirect()->to('/rates?customer='.(int)$row['customer_id'].'#rates-customer'),'customer_rate');
    }

    private function cancelRow($model, array $row, $back, string $entity)
    {
        $locationId=$this->locationId();
        $id=(int)$row['id'];
        if($row['effective_from']<=date('Y-m-d H:i:s')) return $back->with('error','Only a scheduled rate that has not started can be cancelled. To change a price in force, save a new price.');

        return $this->changeInTransaction($back,function() use ($model,$row,$id,$locationId,$entity){
            (new RateChangeLogModel())->insert($this->logRow($entity,null,$locationId,$row,$row['rate_value'],0,'Scheduled rate cancelled (was due '.$row['effective_from'].')'));
            AuditService::log('CANCEL',$entity,$id,$row,null,$this->userId(),$locationId);
            $model->delete($id);
        },'Scheduled rate cancelled.');
    }

    private function changeInTransaction($back, callable $change, string $success)
    {
        $db=Database::connect();
        $db->transBegin();
        try{
            $change();
            if(!$db->transStatus()) throw new \RuntimeException('Rate could not be updated.');
            $db->transCommit();
        }catch(\Throwable $e){
            $db->transRollback();
            return $back->with('error',$e->getMessage());
        }
        return $back->with('success',$success);
    }
}
