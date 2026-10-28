<?php

namespace App\Controllers;

use App\Services\PermissionService;
use App\Services\SalesService;
use CodeIgniter\Controller;
use Config\Database;

class Dashboard extends Controller
{
    private function guard()
    {
        return PermissionService::allows('DASHBOARD_VIEW')
            ? null
            : $this->response->setStatusCode(403)->setBody('Forbidden');
    }

    private function dateRange(): array
    {
        $today = date('Y-m-d');
        $from = trim((string) $this->request->getGet('from'));
        $to = trim((string) $this->request->getGet('to'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $from;

        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        if ($fromTs === false) { $from = $today; $fromTs = strtotime($from); }
        if ($toTs === false || $toTs < $fromTs) $to = $from;

        return [
            'from' => $from,
            'to' => $to,
            'toExclusive' => date('Y-m-d', strtotime($to . ' +1 day')),
            'label' => $from === $to
                ? date('d M Y', strtotime($from))
                : date('d M Y', strtotime($from)) . ' — ' . date('d M Y', strtotime($to)),
            'isToday' => $from === $today && $to === $today,
        ];
    }

    private function periodQuery($builder, string $column, array $range): void
    {
        $builder->where($column . ' >=', $range['from'] . ' 00:00:00')
            ->where($column . ' <', $range['toExclusive'] . ' 00:00:00');
    }

    public function index()
    {
        if ($r = $this->guard()) return $r;

        // Dashboard totals are transaction-sensitive; prevent browsers/proxies from
        // reusing a stale rendered summary after a POS transaction is posted.
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Expires', '0');

        $db = Database::connect();
        $locationId = (int) (session()->get('location_id') ?? 0);
        $range = $this->dateRange();

        // 1) Available gas: live physical filled cylinders, grouped by cylinder type.
        $gasRows = $db->table('cylinder_units cu')
            ->select('cu.id,cu.unit_code,cu.gas_weight_kg,cu.updated_at,ct.id cylinder_type_id,ct.code type_code,ct.name type_name,ct.capacity_kg')
            ->join('cylinder_types ct', 'ct.id=cu.cylinder_type_id')
            ->where(['cu.location_id'=>$locationId,'cu.status'=>'filled'])
            ->orderBy('ct.sort_order')->orderBy('cu.unit_code')->get()->getResultArray();

        $gasTypes = [];
        foreach ($gasRows as $row) {
            $id=(int)$row['cylinder_type_id'];
            if (!isset($gasTypes[$id])) $gasTypes[$id]=[
                'id'=>$id,'code'=>$row['type_code'],'name'=>$row['type_name'],'capacity_kg'=>(float)$row['capacity_kg'],
                'cylinder_count'=>0,'gas_kg'=>0.0,'rows'=>[]
            ];
            $gasTypes[$id]['cylinder_count']++;
            $gasTypes[$id]['gas_kg']+=(float)$row['gas_weight_kg'];
            $gasTypes[$id]['rows'][]=$row;
        }
        $gasTypes=array_values($gasTypes);
        $availableGasKg=array_sum(array_map(static fn($x)=>(float)$x['gas_kg'],$gasTypes));

        // 2) Empty physical cylinders in shop, grouped by type.
        $emptyRows = $db->table('cylinder_units cu')
            ->select('cu.id,cu.unit_code,cu.updated_at,ct.id cylinder_type_id,ct.code type_code,ct.name type_name,ct.capacity_kg')
            ->join('cylinder_types ct', 'ct.id=cu.cylinder_type_id')
            ->where(['cu.location_id'=>$locationId,'cu.status'=>'empty'])
            ->orderBy('ct.sort_order')->orderBy('cu.unit_code')->get()->getResultArray();

        $emptyTypes=[];
        foreach($emptyRows as $row){
            $id=(int)$row['cylinder_type_id'];
            if(!isset($emptyTypes[$id]))$emptyTypes[$id]=[
                'id'=>$id,'code'=>$row['type_code'],'name'=>$row['type_name'],'capacity_kg'=>(float)$row['capacity_kg'],
                'count'=>0,'rows'=>[]
            ];
            $emptyTypes[$id]['count']++; $emptyTypes[$id]['rows'][]=$row;
        }
        $emptyTypes=array_values($emptyTypes);

        // 3) Customer custody, grouped first by customer, then by cylinder.
        $issuedRows=$db->table('cylinder_units cu')
            ->select('cu.id,cu.unit_code,cu.gas_weight_kg,cu.updated_at,ct.code type_code,ct.name type_name,ct.capacity_kg,c.id customer_id,c.code customer_code,c.name customer_name,c.phone customer_phone,cc.deposit_amount,cc.issued_at,cc.issued_condition,cc.issued_gas_weight_kg')
            ->join('cylinder_types ct','ct.id=cu.cylinder_type_id')
            ->join('cylinder_custody cc',"cc.cylinder_unit_id=cu.id AND cc.status='issued'","inner")
            ->join('customers c','c.id=cc.customer_id','inner')
            ->where(['cu.location_id'=>$locationId,'cu.status'=>'custody'])
            ->orderBy('c.name')->orderBy('ct.sort_order')->orderBy('cu.unit_code')->get()->getResultArray();

        $issuedCustomers=[];
        foreach($issuedRows as $row){
            $id=(int)$row['customer_id'];
            if(!isset($issuedCustomers[$id]))$issuedCustomers[$id]=[
                'id'=>$id,'code'=>$row['customer_code'],'name'=>$row['customer_name'],'phone'=>$row['customer_phone'],
                'count'=>0,'deposit'=>0.0,'rows'=>[]
            ];
            $issuedCustomers[$id]['count']++; $issuedCustomers[$id]['deposit']+=(float)$row['deposit_amount']; $issuedCustomers[$id]['rows'][]=$row;
        }
        $issuedCustomers=array_values($issuedCustomers);

        // 4) Sales for selected range with payment-mode breakdown.
        $salesQ=$db->table('sales s')
            ->select("s.id,s.sale_no,s.transaction_at,s.customer_id,s.total_amount,s.credit_amount,s.status,s.transaction_type,c.name customer_name,
                COALESCE(SUM(CASE WHEN sp.payment_mode='cash' AND sp.payment_type='sale' THEN sp.amount ELSE 0 END),0) cash_amount,
                COALESCE(SUM(CASE WHEN sp.payment_mode='online' AND sp.payment_type='sale' THEN sp.amount ELSE 0 END),0) online_amount,
                COALESCE(SUM(CASE WHEN sp.payment_mode='cheque' AND sp.payment_type='sale' THEN sp.amount ELSE 0 END),0) cheque_amount")
            ->join('customers c','c.id=s.customer_id','left')
            ->join('sale_payments sp',"sp.sale_id=s.id AND sp.payment_type='sale'",'left')
            ->where(['s.location_id'=>$locationId,'s.status'=>'posted'])
            ->where(SalesService::revenueSaleCondition('s'),null,false)
            ->groupBy('s.id')->orderBy('s.transaction_at','DESC');
        $this->periodQuery($salesQ,'s.transaction_at',$range);
        $salesRows=$salesQ->get()->getResultArray();

        $saleSummary=['total'=>0.0,'count'=>count($salesRows),'cash'=>0.0,'online'=>0.0,'cheque'=>0.0,'credit'=>0.0];
        foreach($salesRows as $row){
            $saleSummary['total']+=(float)$row['total_amount'];
            $saleSummary['cash']+=(float)$row['cash_amount'];
            $saleSummary['online']+=(float)$row['online_amount'];
            $saleSummary['cheque']+=(float)$row['cheque_amount'];
            $saleSummary['credit']+=(float)$row['credit_amount'];
        }

        // 5) Counter cash: actual register/session movements in selected range.
        $cashRowsQ=$db->table('cash_transactions ct')
            ->select('ct.id,ct.transaction_at,ct.transaction_type,ct.direction,ct.amount,ct.reference_type,ct.reference_id,ct.notes,cr.code register_code,cr.name register_name,cs.id session_id')
            ->join('cash_sessions cs','cs.id=ct.cash_session_id')
            ->join('cash_registers cr','cr.id=cs.register_id')
            ->where('cr.location_id',$locationId)->orderBy('cr.code')->orderBy('ct.transaction_at','DESC')->orderBy('ct.id','DESC');
        $this->periodQuery($cashRowsQ,'ct.transaction_at',$range);
        $cashRows=$cashRowsQ->get()->getResultArray();
        $cashRegisters=[];
        foreach($cashRows as $row){
            $key=(string)$row['register_code'];
            if(!isset($cashRegisters[$key]))$cashRegisters[$key]=[
                'code'=>$row['register_code'],'name'=>$row['register_name'],'in'=>0.0,'out'=>0.0,'rows'=>[]
            ];
            if($row['direction']==='in')$cashRegisters[$key]['in']+=(float)$row['amount'];else$cashRegisters[$key]['out']+=(float)$row['amount'];
            $cashRegisters[$key]['rows'][]=$row;
        }
        $cashRegisters=array_values($cashRegisters);
        $cashIn=array_sum(array_map(static fn($x)=>(float)$x['in'],$cashRegisters));
        $cashOut=array_sum(array_map(static fn($x)=>(float)$x['out'],$cashRegisters));

        // 6) Current customer receivables, independent of the selected sales date range.
        $customers=$db->table('customers')->where('is_active',1)->orderBy('name')->get()->getResultArray();
        $salesCreditRows=$db->table('sales')->select('customer_id,SUM(credit_amount) credit')
            ->where(['location_id'=>$locationId,'status'=>'posted'])->where('customer_id IS NOT NULL',null,false)->groupBy('customer_id')->get()->getResultArray();
        $receiptRows=$db->table('customer_receipts')->select('customer_id,SUM(amount) paid')
            ->where(['location_id'=>$locationId,'status'=>'posted'])->groupBy('customer_id')->get()->getResultArray();
        $creditBy=[];$paidBy=[];
        foreach($salesCreditRows as $row)$creditBy[(int)$row['customer_id']]=(float)$row['credit'];
        foreach($receiptRows as $row)$paidBy[(int)$row['customer_id']]=(float)$row['paid'];
        $salesService=new SalesService();
        $bulkBalances=$salesService->customerBalances($customers);
        $receivables=[];
        foreach($customers as $c){
            $id=(int)$c['id'];
            $balance=max(0,(float)($bulkBalances[$id]??0));
            if($balance<=0.005)continue;
            $receivables[]=['id'=>$id,'code'=>$c['code'],'name'=>$c['name'],'phone'=>$c['phone'],'opening_balance'=>(float)$c['opening_balance'],'credit_sales'=>(float)($creditBy[$id]??0),'receipts'=>(float)($paidBy[$id]??0),'balance'=>$balance];
        }
        usort($receivables,static fn($a,$b)=>$b['balance']<=>$a['balance']);
        $receivableTotal=array_sum(array_map(static fn($x)=>(float)$x['balance'],$receivables));

        // The drill-down only renders customers that have an outstanding balance, so fetch just those.
        $receivableIds=array_map(static fn($x)=>(int)$x['id'],$receivables);
        // Customer ledger rows for the receivable drill-down.
        $ledgerRowsQ=$db->table('sales s')->select("s.customer_id,s.sale_no,s.transaction_at,s.transaction_type,s.credit_amount,0 AS receipt_amount,c.name customer_name")
            ->join('customers c','c.id=s.customer_id','inner')->where(['s.location_id'=>$locationId,'s.status'=>'posted'])->whereIn('s.customer_id',$receivableIds ?: [0])->where('s.credit_amount >',0)->orderBy('s.transaction_at','DESC');
        $ledgerSales=$ledgerRowsQ->get()->getResultArray();
        $ledgerReceipts=$db->table('customer_receipts r')->select("r.customer_id,r.receipt_no,r.receipt_at transaction_at,r.amount receipt_amount,0 AS credit_amount,c.name customer_name")
            ->join('customers c','c.id=r.customer_id','inner')->where(['r.location_id'=>$locationId,'r.status'=>'posted'])->whereIn('r.customer_id',$receivableIds ?: [0])->orderBy('r.receipt_at','DESC')->get()->getResultArray();
        $receivableLedger=[];
        foreach(array_merge($ledgerSales,$ledgerReceipts) as $row){
            $id=(int)$row['customer_id'];
            if(!isset($receivableLedger[$id]))$receivableLedger[$id]=[];
            $receivableLedger[$id][]=$row;
        }

        // 7) Expenses for selected range, with category and payment method.
        $expenseRowsQ=$db->table('expenses e')->select('e.expense_no,e.expense_at,e.amount,e.payment_mode,e.reference_no,e.description,ec.name category_name')
            ->join('expense_categories ec','ec.id=e.category_id','left')->where('e.location_id',$locationId)->orderBy('e.expense_at','DESC');
        $this->periodQuery($expenseRowsQ,'e.expense_at',$range);
        $expenseRows=$expenseRowsQ->get()->getResultArray();
        $expenseTotal=array_sum(array_map(static fn($x)=>(float)$x['amount'],$expenseRows));
        $expenseCategories=[];
        foreach($expenseRows as $row){
            $key=(string)($row['category_name']??'Other');
            if(!isset($expenseCategories[$key]))$expenseCategories[$key]=['name'=>$key,'amount'=>0.0,'count'=>0];
            $expenseCategories[$key]['amount']+=(float)$row['amount'];$expenseCategories[$key]['count']++;
        }
        $expenseCategories=array_values($expenseCategories);
        usort($expenseCategories,static fn($a,$b)=>$b['amount']<=>$a['amount']);

        // 8) Purchases for selected range: Company (supplier) -> Plant -> Purchase.
        $purchaseQ=$db->table('purchases p')
            ->select('p.id,p.purchase_no,p.transaction_at,p.total_amount,p.credit_amount,p.supplier_id,p.plant_id,s.name supplier_name,sp.name plant_name')
            ->join('suppliers s','s.id=p.supplier_id')
            ->join('supplier_plants sp','sp.id=p.plant_id','left')
            ->where(['p.location_id'=>$locationId,'p.status'=>'posted'])
            ->orderBy('s.name')->orderBy('p.transaction_at','DESC');
        $this->periodQuery($purchaseQ,'p.transaction_at',$range);
        $purchaseRows=$purchaseQ->get()->getResultArray();

        $purchaseItems=[];
        if($purchaseRows){
            $itemRows=$db->table('purchase_items pi')
                ->select('pi.purchase_id,pi.line_type,pi.quantity,ct.code type_code,ct.name type_name')
                ->join('cylinder_types ct','ct.id=pi.cylinder_type_id','left')
                ->whereIn('pi.purchase_id',array_map(static fn($r)=>(int)$r['id'],$purchaseRows))
                ->orderBy('pi.line_no')->get()->getResultArray();
            foreach($itemRows as $it) $purchaseItems[(int)$it['purchase_id']][]=$it;
        }

        $purchaseCompanies=[];
        $purchaseSummary=['total'=>0.0,'paid'=>0.0,'pending'=>0.0,'count'=>count($purchaseRows),'cylinders'=>0];
        foreach($purchaseRows as $row){
            $sid=(int)$row['supplier_id'];
            $pkey=$row['plant_id']?(int)$row['plant_id']:0;
            $total=(float)$row['total_amount'];$pending=(float)$row['credit_amount'];$paid=max(0,$total-$pending);
            $cyl=0;$row['items']=$purchaseItems[(int)$row['id']]??[];
            foreach($row['items'] as $it) if($it['line_type']!=='gas_kg') $cyl+=(int)$it['quantity'];
            $row['paid']=$paid;$row['cylinders']=$cyl;
            if(!isset($purchaseCompanies[$sid])) $purchaseCompanies[$sid]=['name'=>$row['supplier_name'],'total'=>0.0,'paid'=>0.0,'pending'=>0.0,'cylinders'=>0,'plants'=>[]];
            if(!isset($purchaseCompanies[$sid]['plants'][$pkey])) $purchaseCompanies[$sid]['plants'][$pkey]=['name'=>$row['plant_name']?:'Plant not specified','total'=>0.0,'paid'=>0.0,'pending'=>0.0,'cylinders'=>0,'purchases'=>[]];
            foreach(['company','plant'] as $level){
                $node=&$purchaseCompanies[$sid];
                if($level==='plant') $node=&$purchaseCompanies[$sid]['plants'][$pkey];
                $node['total']+=$total;$node['paid']+=$paid;$node['pending']+=$pending;$node['cylinders']+=$cyl;
                unset($node);
            }
            $purchaseCompanies[$sid]['plants'][$pkey]['purchases'][]=$row;
            $purchaseSummary['total']+=$total;$purchaseSummary['paid']+=$paid;$purchaseSummary['pending']+=$pending;$purchaseSummary['cylinders']+=$cyl;
        }

        // 9) Filled cylinders: Company -> Plant -> Cylinder type -> physical cylinders.
        // Origin = purchase that filled the cylinder (filled_purchase_id), else the purchase that created it.
        $filledOriginRows=$db->table('cylinder_units cu')
            ->select("cu.id,cu.unit_code,ct.code type_code,ct.name type_name,s.id supplier_id,s.name supplier_name,sp.id plant_id,sp.name plant_name")
            ->join('cylinder_types ct','ct.id=cu.cylinder_type_id')
            ->join('purchases p',"p.id=COALESCE(cu.filled_purchase_id,CASE WHEN cu.source_type='purchase' THEN cu.source_id END)",'left')
            ->join('suppliers s','s.id=p.supplier_id','left')
            ->join('supplier_plants sp','sp.id=p.plant_id','left')
            ->where(['cu.location_id'=>$locationId,'cu.status'=>'filled'])
            ->orderBy('s.name')->orderBy('sp.name')->orderBy('ct.sort_order')->orderBy('cu.unit_code')->get()->getResultArray();
        $filledCompanies=[];
        foreach($filledOriginRows as $row){
            $sid=$row['supplier_id']?(int)$row['supplier_id']:0;
            $pkey=$row['plant_id']?(int)$row['plant_id']:0;
            if(!isset($filledCompanies[$sid])) $filledCompanies[$sid]=['name'=>$row['supplier_name']?:'Opening / Adjustment stock','count'=>0,'plants'=>[]];
            if(!isset($filledCompanies[$sid]['plants'][$pkey])) $filledCompanies[$sid]['plants'][$pkey]=['name'=>$sid?($row['plant_name']?:'Plant not specified'):'—','count'=>0,'types'=>[]];
            $tkey=$row['type_code'];
            if(!isset($filledCompanies[$sid]['plants'][$pkey]['types'][$tkey])) $filledCompanies[$sid]['plants'][$pkey]['types'][$tkey]=['name'=>$row['type_code'].' — '.$row['type_name'],'units'=>[]];
            $filledCompanies[$sid]['plants'][$pkey]['types'][$tkey]['units'][]=$row['unit_code'];
            $filledCompanies[$sid]['count']++;$filledCompanies[$sid]['plants'][$pkey]['count']++;
        }

        // Existing Stock tab data — intentionally retained.
        $filled=$db->table('cylinder_units cu')
            ->select('cu.id,cu.unit_code,cu.gas_weight_kg,cu.updated_at,ct.code type_code,ct.name type_name,ct.capacity_kg')
            ->join('cylinder_types ct','ct.id=cu.cylinder_type_id')
            ->where(['cu.location_id'=>$locationId,'cu.status'=>'filled'])->orderBy('ct.sort_order')->orderBy('cu.unit_code')->get()->getResultArray();
        $empty=$db->table('cylinder_units cu')
            ->select('cu.id,cu.unit_code,cu.gas_weight_kg,cu.updated_at,ct.code type_code,ct.name type_name,ct.capacity_kg')
            ->join('cylinder_types ct','ct.id=cu.cylinder_type_id')
            ->where(['cu.location_id'=>$locationId,'cu.status'=>'empty'])->orderBy('ct.sort_order')->orderBy('cu.unit_code')->get()->getResultArray();
        $issued=$db->table('cylinder_units cu')
            ->select('cu.id,cu.unit_code,cu.gas_weight_kg,cu.updated_at,ct.code type_code,ct.name type_name,ct.capacity_kg,c.id customer_id,c.code customer_code,c.name customer_name,c.phone customer_phone,cc.deposit_amount,cc.issued_at')
            ->join('cylinder_types ct','ct.id=cu.cylinder_type_id')
            ->join('cylinder_custody cc',"cc.cylinder_unit_id=cu.id AND cc.status='issued'",'inner')
            ->join('customers c','c.id=cc.customer_id','inner')
            ->where(['cu.location_id'=>$locationId,'cu.status'=>'custody'])->orderBy('ct.sort_order')->orderBy('cu.unit_code')->get()->getResultArray();

        $stock=[
            'filled'=>['title'=>'Filled Cylinders','count'=>count($filled),'gas_kg'=>array_sum(array_map(static fn($row)=>(float)$row['gas_weight_kg'],$filled)),'rows'=>$filled],
            'empty'=>['title'=>'Empty Cylinders','count'=>count($empty),'gas_kg'=>0.0,'rows'=>$empty],
            'issued'=>['title'=>'Issued Temporarily Against Deposit','count'=>count($issued),'deposit'=>array_sum(array_map(static fn($row)=>(float)$row['deposit_amount'],$issued)),'rows'=>$issued],
        ];

        return view('dashboard/index',[
            'title'=>'Dashboard','range'=>$range,
            'summary'=>['sales'=>$saleSummary['total'],'sale_count'=>$saleSummary['count'],'cash_sale'=>$saleSummary['cash'],'credit_sale'=>$saleSummary['credit']],
            'gasRows'=>$gasRows,'gasTypes'=>$gasTypes,'availableGasKg'=>$availableGasKg,
            'emptyTypes'=>$emptyTypes,'emptyCylinderCount'=>count($emptyRows),
            'issuedCustomers'=>$issuedCustomers,'issuedCylinderCount'=>count($issuedRows),'issuedDepositTotal'=>array_sum(array_map(static fn($x)=>(float)$x['deposit_amount'],$issuedRows)),
            'saleSummary'=>$saleSummary,'salesRows'=>$salesRows,
            'cashRegisters'=>$cashRegisters,'cashIn'=>$cashIn,'cashOut'=>$cashOut,
            'receivables'=>$receivables,'receivableTotal'=>$receivableTotal,'receivableLedger'=>$receivableLedger,
            'expenseRows'=>$expenseRows,'expenseCategories'=>$expenseCategories,'expenseTotal'=>$expenseTotal,
            'stock'=>$stock,
            'purchaseCompanies'=>$purchaseCompanies,'purchaseSummary'=>$purchaseSummary,
            'filledCompanies'=>$filledCompanies,'filledCylinderCount'=>count($filledOriginRows),
        ]);
    }
}
