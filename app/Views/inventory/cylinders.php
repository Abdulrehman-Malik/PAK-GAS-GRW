<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div><h4 class="mb-1">Physical Cylinders</h4><div class="text-muted small"><?=esc($type['code'].' — '.$type['name'])?> · Capacity <?=number_format((float)$type['capacity_kg'], 2)?> KG</div></div>
  <a class="btn btn-outline-secondary" href="<?=site_url('inventory')?>"><i class="bi bi-arrow-left me-1"></i>Inventory</a>
</div>
<style>
.type-stock{display:flex;gap:.6rem;overflow-x:auto;padding-bottom:.35rem}
.type-stock-card{flex:0 0 auto;min-width:11.5rem;border:1px solid #dde9e2;border-radius:.8rem;background:#fff;padding:.65rem .8rem;color:inherit;text-decoration:none;display:block}
.type-stock-card:hover{border-color:#0B6E54;box-shadow:0 4px 14px #06382f1a}
.type-stock-card.active{border-color:#0B6E54;background:#f0f8f4;box-shadow:inset 0 0 0 1px #0B6E54}
.type-stock-name{font-weight:700;font-size:.92rem}.type-stock-total{font-size:1.35rem;font-weight:800;line-height:1.1}
.type-stock-total small{font-size:.7rem;font-weight:600;color:#64748b}
.type-stock-split{display:flex;gap:.3rem;margin-top:.35rem;flex-wrap:wrap}
.type-stock-split span{font-size:.7rem;border-radius:999px;padding:.12rem .45rem;border:1px solid #e2e8f0;background:#f8fafc;color:#475569}
.type-stock-split .full{background:#e7f6ee;border-color:#c5e6d3;color:#0b6e54}
.type-stock-split .partial{background:#fff6e0;border-color:#f3dfa8;color:#8a6200}
</style>
<div class="mb-1 small text-uppercase fw-semibold text-muted">Stock by Cylinder Type</div>
<div class="type-stock mb-3">
<?php foreach($typeStock as $ts): $tot=(int)$ts['full_count']+(int)$ts['partial_count']+(int)$ts['empty_count']; ?>
  <a class="type-stock-card <?=(int)$ts['id']===(int)$type['id']?'active':''?>" href="<?=site_url('inventory/cylinders/'.$ts['id'])?>">
    <div class="type-stock-name"><?=esc($ts['code'].' — '.$ts['name'])?></div>
    <div class="type-stock-total"><?=number_format($tot)?> <small>cylinders</small></div>
    <div class="type-stock-split"><span class="full">Full <?=number_format((int)$ts['full_count'])?></span><span class="partial">Partial <?=number_format((int)$ts['partial_count'])?></span><span>Empty <?=number_format((int)$ts['empty_count'])?></span></div>
  </a>
<?php endforeach;?>
</div>
<form method="get" class="row g-2 mb-3">
  <div class="col-md-5"><input name="q" class="form-control" value="<?=esc($search)?>" placeholder="Search cylinder code"></div>
  <div class="col-md-3">
    <select name="status" class="form-select">
      <option value="">All Statuses</option><option value="filled" <?=$status==='filled'?'selected':''?>>Filled</option><option value="partial" <?=$status==='partial'?'selected':''?>>Partially Filled</option><option value="empty" <?=$status==='empty'?'selected':''?>>Empty</option>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-primary w-100">Filter</button></div>
  <div class="col-md-2"><a class="btn btn-outline-secondary w-100" href="<?=site_url('inventory/cylinders/'.$type['id'])?>">Clear</a></div>
</form>
<div class="card shadow-sm">
<div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
<thead><tr><th>Cylinder Code</th><th>Capacity</th><th>Current Gas</th><th>Status</th><th>Location</th><th>Last Activity</th><th>Action</th></tr></thead>
<tbody>
<?php foreach($units as $u):
  $gas=(float)$u['gas_weight_kg'];$cap=(float)$u['capacity_kg'];
  $label=$gas<=0?'Empty':($gas<$cap?'Partially Filled':'Filled');
  $badge=$label==='Filled'?'text-bg-success':($label==='Partially Filled'?'text-bg-warning':'text-bg-secondary');
?>
<tr>
<td><strong><?=esc($u['unit_code'])?></strong></td><td><?=number_format($cap, 2)?> KG</td><td class="fw-semibold"><?=number_format($gas, 2)?> KG</td>
<td><span class="badge <?=$badge?>"><?=esc($label)?></span></td><td>Current Branch</td>
<td><?=esc($u['last_activity_at']??'—')?></td>
<td><a class="btn btn-sm btn-primary" href="<?=site_url('inventory/cylinder/'.$u['id'])?>">Details</a></td>
</tr>
<?php endforeach;?>
<?php if(!$units):?><tr><td colspan="7" class="text-center text-muted py-4">No physical cylinders match the selected filters.</td></tr><?php endif;?>
</tbody></table></div></div>
<?= $this->endSection() ?>