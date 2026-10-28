<?php $uiLang = \App\Libraries\UiLanguage::fromRequest(); $uiDir = \App\Libraries\UiLanguage::meta($uiLang)['dir']; ?>
<!doctype html>
<html lang="<?= esc($uiLang, 'attr') ?>" dir="<?= esc($uiDir, 'attr') ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt <?=esc($sale['sale_no'])?></title>
<style>
*{box-sizing:border-box}html,body{margin:0;padding:0}body{width:80mm;max-width:80mm;margin:0 auto;padding:4mm 3mm;font-family:Arial,Helvetica,sans-serif;font-size:10px;line-height:1.25;color:#000;background:#fff}
.header{text-align:center}.shop-name{font-size:15px;font-weight:700;margin-bottom:2px}.receipt-title{font-size:11px;font-weight:700;margin-top:2px}.transaction-type{font-size:10px;font-weight:700;margin-top:3px}.small{font-size:9px}.rule{border-top:1px dashed #000;margin:5px 0}.row{display:flex;justify-content:space-between;gap:6px}.row span:first-child{flex:1}.row span:last-child{text-align:right}
table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{padding:2px 0;vertical-align:top;border-bottom:1px dotted #888;word-wrap:break-word}th{font-weight:700}.item{width:35%}.qty{width:11%;text-align:right}.gas{width:15%;text-align:right}.rate{width:17%;text-align:right}.amount{width:22%;text-align:right}
.detail{font-size:8.5px;color:#222}.codes{font-size:8.5px;line-height:1.35;color:#222}.codes span{white-space:nowrap}
.total{font-size:13px;font-weight:700;margin-top:5px}.deposit{font-weight:700}.refund{font-weight:700}.footer{text-align:center;margin-top:6px;white-space:pre-line}.actions{text-align:center;margin-top:10px}button{font-size:12px;padding:6px 12px;margin:0 3px}
@media print{@page{size:80mm auto;margin:0}html,body{width:80mm;max-width:80mm}body{padding:3mm 2.5mm}.actions{display:none}}
</style><?= view('partials/i18n_head', ['uiLang' => $uiLang]) ?></head><body>
<?php
$typeLabels=[
 'gas_sale'=>'Gas Sale / Refill',
 'cylinder_sale'=>'Cylinder Sale',
 'security_deposit'=>'Security Deposit / Cylinder Custody',
 'cylinder_return'=>'Cylinder Return / Deposit Refund',
 'filled_cylinder'=>'Filled Cylinder Sale',
 'refill_service'=>'Gas Refill',
 'cylinder_exchange'=>'Cylinder Exchange',
 'empty_sale'=>'Empty Cylinder Sale',
 'mixed'=>'Mixed Transaction'
];
$typeLabel=$typeLabels[$sale['transaction_type']]??ucwords(str_replace('_',' ',$sale['transaction_type']));
?>
<div class="header">
  <div class="shop-name"><?=esc($location['name']??'Perfect LPG')?></div>
  <?php if(!empty($shopSettings['show_address_on_receipt'])&&!empty($location['address'])):?><div class="small"><?=esc($location['address'])?><?=!empty($location['city'])?', '.esc($location['city']):''?></div><?php endif;?>
  <div class="receipt-title"><?=esc($shopSettings['receipt_title']??'SALE RECEIPT')?></div>
  <div class="transaction-type"><?=esc($typeLabel)?></div>
</div>
<div class="rule"></div>
<div class="row"><span>No.</span><span><?=esc($sale['sale_no'])?></span></div>
<div class="row"><span>Date</span><span><?=esc($sale['transaction_at'])?></span></div>
<div class="row"><span>Customer</span><span><?=esc($sale['customer_name']??'Walk-in / Cash')?></span></div>
<?php if(!empty($sale['cashier_name'])): ?><div class="row"><span>Cashier</span><span><?=esc($sale['cashier_name'])?></span></div><?php endif;?>
<div class="rule"></div>

<?php if($items): ?>
<table><thead><tr><th class="item">Item</th><th class="qty">Qty</th><th class="gas">Gas</th><th class="rate">Rate</th><th class="amount">Amount</th></tr></thead><tbody>
<?php foreach($items as $i): ?><tr>
<?php
$isCyl=!empty($i['is_cylinder']);
$hasCylPrice=$isCyl&&(float)($i['cylinder_price']??0)>0;
$trim=static fn(float $v,int $d=2):string=>rtrim(rtrim(number_format($v,$d,'.',','),'0'),'.');
?>
<td class="item"><?=esc(match($i['line_type']){'refill_kg'=>'Gas / Refill','filled_cylinder'=>'Filled Cylinder','empty_cylinder'=>'Empty Cylinder',default=>$i['line_type']})?>
<?php if($hasCylPrice): $gasAmt=(float)$i['unit_amount']-(float)$i['cylinder_price']; ?>
<br><span class="detail"><?php if($i['line_type']==='filled_cylinder'): ?>Gas Rs. <?=number_format($gasAmt,2)?> + Cylinder Rs. <?=number_format((float)$i['cylinder_price'],2)?> each<?php else: ?>Cylinder Rs. <?=number_format((float)$i['cylinder_price'],2)?> each<?php endif;?></span>
<?php endif;?>
<?php if(!empty($i['codes'])): ?><br><span class="codes"><?php foreach($i['codes'] as $n=>$code): ?><span><?=esc($code)?></span><?=$n<count($i['codes'])-1?', ':''?><?php endforeach;?></span><?php endif;?>
<?php if(!empty($i['display_note'])):?><br><span class="small"><?=esc($i['display_note'])?></span><?php endif;?></td>
<td class="qty"><?=$isCyl?number_format((float)$i['quantity'],0):$trim((float)$i['quantity'])?></td><td class="gas"><?=(float)$i['gas_weight_kg']>0?$trim((float)$i['gas_weight_kg']).' KG':'—'?></td><td class="rate"><?=$hasCylPrice?'—':number_format((float)$i['applied_rate'],2)?></td><td class="amount"><?=number_format((float)$i['line_total'],2)?></td>
</tr><?php endforeach;?></tbody></table><div class="rule"></div><?php endif;?>

<?php $showTotals=(float)$sale['total_amount']>0 || $sale['transaction_type']!=='security_deposit'; ?>
<?php if($showTotals): ?>
<div class="row"><span>Subtotal</span><span><?=number_format((float)$sale['subtotal'],2)?></span></div>
<div class="row"><span>Discount</span><span><?=number_format((float)$sale['discount_amount'],2)?></span></div>
<div class="row total"><span>Sale Total</span><span>Rs. <?=number_format((float)$sale['total_amount'],2)?></span></div>
<?php endif;?>

<?php if((float)$sale['security_deposit_amount']>0): ?>
<div class="row deposit"><span>Security Deposit Held</span><span>Rs. <?=number_format((float)$sale['security_deposit_amount'],2)?></span></div>
<div class="small">Refundable liability; not sales revenue.</div>
<?php endif;?>
<?php if((float)$sale['security_deposit_refund_amount']>0): ?>
<div class="row refund"><span>Security Deposit Refund</span><span>Rs. <?=number_format((float)$sale['security_deposit_refund_amount'],2)?></span></div>
<?php endif;?>

<div class="rule"></div>
<div class="row"><span>Previous OS Balance</span><span>Rs. <?=number_format((float)($sale['previous_os_balance']??0),2)?></span></div>
<?php if(!$showTotals): ?>
<div class="row"><span>Current Sale</span><span>Rs. <?=number_format((float)$sale['total_amount'],2)?></span></div>
<div class="row"><span>Discount</span><span>Rs. <?=number_format((float)$sale['discount_amount'],2)?></span></div>
<?php endif;?>
<div class="row"><span>Net Receivable Amount</span><span>Rs. <?=number_format((float)($sale['net_receivable_amount']??$sale['total_amount']),2)?></span></div>
<?php foreach($payments as $p): ?><div class="row"><span><?=esc(ucfirst($p['payment_mode']))?></span><span>Rs. <?=number_format((float)$p['amount'],2)?></span></div><?php endforeach;?>
<div class="row total"><span>Receipt Amount</span><span>Rs. <?=number_format((float)($sale['receipt_amount']??0),2)?></span></div>
<div class="row total"><span>OS Balance</span><span>Rs. <?=number_format((float)($sale['os_balance']??$sale['credit_amount']),2)?></span></div>
<?php if($sale['transaction_type']==='security_deposit' && (float)$sale['security_deposit_amount']>0): ?><div class="row total"><span>Net Amount Payable</span><span>Rs. <?=number_format((float)$sale['security_deposit_amount'],2)?></span></div><?php elseif($sale['transaction_type']==='cylinder_return'): ?><div class="row total"><span>Customer Receives</span><span>Rs. <?=number_format((float)$sale['security_deposit_refund_amount'],2)?></span></div><?php endif;?>
<div class="rule"></div><div class="footer"><?=nl2br(esc($shopSettings['receipt_footer']??'Thank you'))?></div>
<div class="actions"><button type="button" onclick="window.print()">Print</button><button type="button" onclick="window.close()">Close</button></div>
<script>/* Receipt opens as a preview; print only when the user clicks Print. */</script>
<?= view('partials/i18n_foot', ['uiLang' => $uiLang]) ?></body></html>