<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$fmt = static fn($v) => number_format((float)$v, 2);
$when = static fn($d) => $d ? date('d-M-Y H:i', strtotime((string)$d)) : '';
$listLabels = ['retail' => 'Retail', 'wholesale' => 'Wholesale'];
// Hint under a blank price-list cell: what POS uses instead.
$sourceText = static function (array $resolved, string $sameListText) use ($fmt): string {
    if ($resolved['rate'] === null) return 'No price set';
    if ($resolved['source'] === 'retail_fallback') return 'Not set, uses Retail ' . $fmt($resolved['rate']);
    if ($resolved['source'] === 'cylinder_type') return 'Not set, uses Cylinder Type price ' . $fmt($resolved['rate']);
    return 'Not set, uses ' . $sameListText . ' ' . $fmt($resolved['rate']);
};
// Hint under a blank customer cell: the price the customer's price list gives.
$customerSourceText = static fn(array $resolved): string => $sourceText($resolved, $resolved['source'] === 'wholesale' ? 'Wholesale' : 'Retail');
// One grid input: current value, a hint when nothing is set, the next scheduled price and a Stop action.
$cellInput = static function (string $name, array $cell, ?float $fallbackRate, string $hint, string $stopUrl) use ($fmt, $when): string {
    $current = $cell['current'];
    $value = $current ? esc(number_format((float)$current['rate_value'], 2, '.', ''), 'attr') : '';
    $html = '<input name="' . esc($name, 'attr') . '" type="number" step="0.01" min="0.01" inputmode="decimal" class="form-control form-control-sm rate-input text-end"'
        . ' value="' . $value . '" data-current="' . $value . '"'
        . ' placeholder="' . ($fallbackRate !== null ? esc(number_format($fallbackRate, 2, '.', ''), 'attr') : '') . '">';
    $html .= '<div class="rate-hint text-muted mt-1">';
    if ($current) {
        $html .= 'Since ' . esc($when($current['effective_from']))
            . ' <button type="submit" class="btn btn-link btn-sm p-0 ms-1 text-danger align-baseline rate-stop" formaction="' . esc($stopUrl . (int)$current['id'], 'attr') . '" formnovalidate'
            . ' onclick="return confirm(\'Stop this price now? It stays in the history. POS then uses the price shown when the field is blank.\');">Stop</button>';
    } else {
        $html .= esc($hint);
    }
    if ($cell['next']) {
        $html .= '<br><span class="text-primary">Next: ' . $fmt($cell['next']['rate_value']) . ' from ' . esc($when($cell['next']['effective_from'])) . '</span>';
    }
    return $html . '</div>';
};
// The save bar under every grid: Effective From, reason and the Save button.
$saveBar = static function (string $buttonText): string {
    return '<div class="rate-save-bar row g-2 align-items-end border-top pt-3 mt-3">'
        . '<div class="col-sm-6 col-lg-3"><label class="form-label mb-1">Effective From</label><input name="effective_from" type="datetime-local" class="form-control" value="' . date('Y-m-d\TH:i') . '" min="' . date('Y-m-d\TH:i') . '" required></div>'
        . '<div class="col-sm-6 col-lg-6"><label class="form-label mb-1">Reason</label><input name="reason" class="form-control" maxlength="255" placeholder="For example: plant price increase"></div>'
        . '<div class="col-lg-3"><button class="btn btn-primary w-100 rate-save" type="submit">' . esc($buttonText) . '</button></div>'
        . '</div>';
};
$badges = ['current' => ['success', 'Current'], 'scheduled' => ['info', 'Scheduled'], 'stopped' => ['secondary', 'Stopped'], 'old' => ['light border', 'Old']];
$customerName = static fn(array $c) => trim(($c['code'] ?? '') !== '' ? $c['name'] . ' (' . $c['code'] . ')' : $c['name']);
?>
<style>
.rates-page .rate-grid-scroll{max-height:min(62vh,600px);overflow:auto;border:1px solid var(--bs-border-color);border-radius:.375rem}
.rates-page .rate-grid-scroll table{min-width:700px;margin-bottom:0}
.rates-page .rate-grid-scroll thead th,.rates-page .rate-history-scroll thead th{position:sticky;top:0;z-index:2;background:var(--bs-card-bg,var(--bs-body-bg));box-shadow:inset 0 -1px 0 var(--bs-border-color);vertical-align:bottom}
.rates-page .rate-input{max-width:11rem}
.rates-page .rate-kg .rate-input{max-width:12rem}
.rates-page .rate-input::placeholder{color:var(--bs-secondary-color);opacity:.55;font-style:italic}
.rates-page .rate-cyl{white-space:nowrap}
.rates-page .rate-hint{font-size:.78rem;line-height:1.3}
.rates-page .rate-col-head{min-width:13rem}
.rates-page .rate-history-scroll{max-height:min(65vh,640px);overflow:auto}
.rates-page .rate-history-scroll td,.rates-page .rate-history-scroll th{white-space:nowrap}
.rates-page .rate-save-bar{position:sticky;bottom:0;z-index:3;background:var(--bs-card-bg,var(--bs-body-bg));padding-bottom:.75rem}
.rates-page .rate-customer-chips{max-height:7.5rem;overflow:auto}
</style>
<div class="rates-page">
<div class="d-flex justify-content-between align-items-center mb-2"><h4 class="mb-0">LPG Rates</h4></div>
<p class="text-muted small mb-3">Change a price and press Save. The new price starts at <strong>Effective From</strong> and the old price stays in History. Posted sales keep the price they were sold at. A blank field uses the price shown in grey.</p>

<ul class="nav nav-tabs flex-nowrap overflow-auto" role="tablist" id="rateTabs">
  <li class="nav-item"><button class="nav-link active text-nowrap" data-bs-toggle="tab" data-bs-target="#rates-retail" type="button">Retail</button></li>
  <li class="nav-item"><button class="nav-link text-nowrap" data-bs-toggle="tab" data-bs-target="#rates-wholesale" type="button">Wholesale</button></li>
  <li class="nav-item"><button class="nav-link text-nowrap" data-bs-toggle="tab" data-bs-target="#rates-customer" type="button">Customer Rates</button></li>
  <li class="nav-item"><button class="nav-link text-nowrap" data-bs-toggle="tab" data-bs-target="#rates-history" type="button">History</button></li>
</ul>
<div class="tab-content card border-top-0 rounded-0 rounded-bottom">
<?php foreach (['retail', 'wholesale'] as $list): $g = $grid[$list]; $label = $listLabels[$list]; ?>
  <div class="tab-pane fade <?= $list === 'retail' ? 'show active' : '' ?> card-body" id="rates-<?= $list ?>">
    <?php if ($list === 'wholesale' && !$wholesaleEnabled): ?>
      <div class="alert alert-warning py-2">The Wholesale price list is turned off in Shop Settings → Pricing. These prices are kept but not used at POS.</div>
    <?php elseif ($list === 'wholesale'): ?>
      <div class="alert alert-light border py-2 small mb-3">Wholesale customers pay these prices. Any price left blank uses the Retail price.</div>
    <?php endif; ?>
    <form method="post" action="<?= site_url('rates/save') ?>" class="rate-grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="price_list" value="<?= $list ?>">
      <?php
        $firstType = $types[0] ?? null;
        $kgFallback = $firstType ? $g['book'][(string)$firstType['id']]['gas_per_kg'] : ['rate' => null, 'source' => 'none'];
        $kgHint = $list === 'wholesale'
            ? 'Not set, uses each cylinder\'s full price ÷ capacity, else Retail'
            : 'Not set, uses each cylinder\'s full price ÷ capacity';
      ?>
      <div class="row g-3 align-items-start mb-3 rate-kg">
        <div class="col-md-5 col-lg-4">
          <label class="form-label fw-semibold mb-1"><?= $label ?> Gas per KG (Rs)</label>
          <?= $cellInput('rates[gas_per_kg]', $g['gas_per_kg'], $list === 'wholesale' && $kgFallback['source'] === 'retail_fallback' ? $kgFallback['rate'] : null, $kgHint, site_url('rates/stop/')) ?>
        </div>
        <div class="col-md-7 col-lg-8 small text-muted pt-md-4">Used for gas sold by KG or amount, and for gas in Cylinder Sale and Security Deposit lines when the cylinder has no full-cylinder price.</div>
      </div>
      <div class="small text-muted d-md-none mb-1">Scroll sideways to see all columns.</div>
      <div class="rate-grid-scroll">
        <table class="table table-sm align-top">
          <thead><tr><th>Cylinder</th><th class="text-end">Capacity</th><th class="rate-col-head">Gas per full cylinder (Rs)<div class="small fw-normal text-muted">Gas sold by cylinder count, and gas in Cylinder Sale / Security Deposit (pro-rated by actual weight)</div></th><th class="rate-col-head">Empty cylinder price (Rs)<div class="small fw-normal text-muted">Cylinder Sale only (empty, or the cylinder part of a filled one)</div></th></tr></thead>
          <tbody>
          <?php foreach ($types as $t): $id = (string)$t['id']; $cells = $g['types'][$id]; $book = $g['book'][$id]; ?>
            <tr>
              <td class="fw-semibold rate-cyl"><?= esc($t['code'] . ' — ' . $t['name']) ?></td>
              <td class="text-end rate-cyl"><?= number_format((float)$t['capacity_kg'], 2) ?> KG</td>
              <td><?= $cellInput('rates[types][' . $id . '][gas_per_cylinder]', $cells['gas_per_cylinder'], $book['gas_per_cylinder']['rate'], $sourceText($book['gas_per_cylinder'], 'Gas per KG × capacity'), site_url('rates/stop/')) ?></td>
              <td><?= $cellInput('rates[types][' . $id . '][empty_cylinder]', $cells['empty_cylinder'], $book['empty_cylinder']['rate'], $sourceText($book['empty_cylinder'], $label), site_url('rates/stop/')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= $saveBar($list === 'wholesale' ? 'Save Wholesale Prices' : 'Save Retail Prices') ?>
    </form>
  </div>
<?php endforeach; ?>

  <div class="tab-pane fade card-body" id="rates-customer">
    <?php if (!$specialEnabled): ?>
      <div class="alert alert-warning py-2">Customer special rates are turned off in Shop Settings → Pricing. These rates are kept but not used at POS.</div>
    <?php endif; ?>
    <p class="small text-muted mb-2">Give one customer their own price. At POS a special rate is used first, and any price left blank uses the customer's price list. Walk-in sales never use special rates.</p>
    <form method="get" action="<?= site_url('rates') ?>" class="row g-2 align-items-end mb-2" id="customerPicker">
      <div class="col-md-6 col-lg-5">
        <label class="form-label mb-1" for="rateCustomer">Customer</label>
        <select name="customer" id="rateCustomer" class="form-select" data-base="<?= esc(site_url('rates'), 'attr') ?>" onchange="window.location.href=this.dataset.base+(this.value?'?customer='+encodeURIComponent(this.value):'')+'#rates-customer'">
          <option value="">Select a customer</option>
          <?php foreach ($customers as $c): $n = (int)($specialCounts[(int)$c['id']] ?? 0); ?>
            <option value="<?= (int)$c['id'] ?>" <?= $customer && (int)$customer['id'] === (int)$c['id'] ? 'selected' : '' ?>><?= esc($customerName($c)) ?><?= $n ? ' ★' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><noscript><button class="btn btn-outline-secondary">Open</button></noscript></div>
    </form>
    <?php if ($specialCounts): ?>
      <div class="small mb-3">
        <span class="text-muted">Customers with special rates:</span>
        <div class="rate-customer-chips d-flex flex-wrap gap-1 mt-1">
        <?php foreach ($customers as $c): $n = (int)($specialCounts[(int)$c['id']] ?? 0); if (!$n) continue; ?>
          <a class="badge text-bg-light border text-decoration-none" href="<?= site_url('rates?customer=' . (int)$c['id']) ?>#rates-customer"><?= esc($c['name']) ?> · <?= $n ?></a>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($customer): $cg = $customerGrid; $cListLabel = $listLabels[$customerList]; ?>
      <div class="alert alert-light border py-2 small mb-3 d-flex flex-wrap gap-3">
        <span><strong><?= esc($customer['name']) ?></strong></span>
        <span>Price list: <strong><?= $cListLabel ?></strong></span>
        <span class="text-muted">Grey prices show what this customer pays without a special rate.</span>
      </div>
      <form method="post" action="<?= site_url('rates/customer/save') ?>" class="rate-grid-form">
        <?= csrf_field() ?>
        <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">
        <div class="row g-3 align-items-start mb-3 rate-kg">
          <div class="col-md-5 col-lg-4">
            <label class="form-label fw-semibold mb-1">Special Gas per KG (Rs)</label>
            <?= $cellInput('rates[gas_per_kg]', $cg['gas_per_kg'], null, $customerList === 'wholesale' ? 'Not set, uses the Wholesale price list' : 'Not set, uses the Retail price list', site_url('rates/customer/stop/')) ?>
          </div>
          <div class="col-md-7 col-lg-8 small text-muted pt-md-4">A special Gas per KG applies to every cylinder type. A special full-cylinder price is used for that cylinder type instead.</div>
        </div>
        <div class="small text-muted d-md-none mb-1">Scroll sideways to see all columns.</div>
        <div class="rate-grid-scroll">
          <table class="table table-sm align-top">
            <thead><tr><th>Cylinder</th><th class="text-end">Capacity</th><th class="rate-col-head">Special gas per full cylinder (Rs)</th><th class="rate-col-head">Special empty cylinder price (Rs)</th></tr></thead>
            <tbody>
            <?php foreach ($types as $t): $id = (string)$t['id']; $cells = $cg['types'][$id]; $book = $cg['book'][$id]; ?>
              <tr>
                <td class="fw-semibold rate-cyl"><?= esc($t['code'] . ' — ' . $t['name']) ?></td>
                <td class="text-end rate-cyl"><?= number_format((float)$t['capacity_kg'], 2) ?> KG</td>
                <td><?= $cellInput('rates[types][' . $id . '][gas_per_cylinder]', $cells['gas_per_cylinder'], $book['gas_per_cylinder']['rate'], $customerSourceText($book['gas_per_cylinder']), site_url('rates/customer/stop/')) ?></td>
                <td><?= $cellInput('rates[types][' . $id . '][empty_cylinder]', $cells['empty_cylinder'], $book['empty_cylinder']['rate'], $customerSourceText($book['empty_cylinder']), site_url('rates/customer/stop/')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= $saveBar('Save Special Rates') ?>
      </form>

      <h6 class="mt-4 mb-2">Special rate history</h6>
      <?php if (!$customerHistory): ?>
        <p class="small text-muted">No special rates yet.</p>
      <?php else: ?>
        <div class="rate-history-scroll border rounded">
          <table class="table table-sm mb-0" id="customerRateHistory">
            <thead><tr><th>Effective From</th><th>Ends</th><th>Item</th><th>Cylinder</th><th class="text-end">Rate</th><th>Status</th><th>Created By</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($customerHistory as $r): [$badgeClass, $badgeText] = $badges[$r['status']] ?? ['light', '']; ?>
              <tr>
                <td><?= esc($when($r['effective_from'])) ?></td>
                <td><?= esc($when($r['effective_to'])) ?></td>
                <td><?= esc($items[$r['rate_type']] ?? $r['rate_type']) ?></td>
                <td><?= $r['cylinder_type_id'] ? esc(trim(($r['cylinder_code'] ?? '') . ' ' . ($r['cylinder_name'] ?? ''))) : 'All cylinders' ?></td>
                <td class="text-end"><?= $fmt($r['rate_value']) ?></td>
                <td><span class="badge text-bg-<?= $badgeClass ?>"><?= $badgeText ?></span></td>
                <td><?= esc($r['created_by_name'] ?? '') ?></td>
                <td class="text-end">
                  <?php if ($r['status'] === 'scheduled'): ?>
                    <form method="post" action="<?= site_url('rates/customer/cancel/' . (int)$r['id']) ?>" class="d-inline" onsubmit="return confirm('Cancel this scheduled rate? It has not started, so no sale used it.');"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="tab-pane fade card-body" id="rates-history">
    <div class="rate-history-scroll">
    <table class="table table-sm datatable">
      <thead><tr><th>Effective From</th><th>Ends</th><th>Price List</th><th>Item</th><th>Cylinder</th><th class="text-end">Rate</th><th>Status</th><th>Created By</th><th data-orderable="false"></th></tr></thead>
      <tbody>
      <?php foreach ($history as $r): [$badgeClass, $badgeText] = $badges[$r['status']] ?? ['light', '']; ?>
        <tr>
          <td data-order="<?= esc($r['effective_from']) ?>"><?= esc($when($r['effective_from'])) ?></td>
          <td><?= esc($when($r['effective_to'])) ?></td>
          <td><?= $listLabels[$r['price_list']] ?? '' ?></td>
          <td><?= esc($items[$r['rate_type']] ?? $r['rate_type']) ?></td>
          <td><?= $r['cylinder_type_id'] ? esc(trim(($r['cylinder_code'] ?? '') . ' ' . ($r['cylinder_name'] ?? ''))) : 'All cylinders' ?></td>
          <td class="text-end"><?= $fmt($r['rate_value']) ?></td>
          <td><span class="badge text-bg-<?= $badgeClass ?>"><?= $badgeText ?></span></td>
          <td><?= esc($r['created_by_name'] ?? '') ?></td>
          <td class="text-end">
            <?php if ($r['status'] === 'scheduled'): ?>
              <form method="post" action="<?= site_url('rates/cancel/' . (int)$r['id']) ?>" class="d-inline" onsubmit="return confirm('Cancel this scheduled rate? It has not started, so no sale used it.');"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Open the tab named in the URL (#rates-wholesale, #rates-customer, #rates-history) after a save or stop.
    const target = document.querySelector('#rateTabs [data-bs-target="' + window.location.hash + '"]');
    if (target && window.bootstrap?.Tab) window.bootstrap.Tab.getOrCreateInstance(target).show();
    document.querySelectorAll('#rateTabs [data-bs-toggle="tab"]').forEach((tab) => tab.addEventListener('shown.bs.tab', () => history.replaceState(null, '', window.location.pathname + window.location.search + tab.dataset.bsTarget)));

    // Highlight changed prices and show how many will be saved.
    document.querySelectorAll('.rate-grid-form').forEach((form) => {
        const button = form.querySelector('.rate-save');
        const label = button.textContent;
        const refresh = () => {
            let changed = 0;
            form.querySelectorAll('.rate-input').forEach((input) => {
                const isChanged = input.value.trim() !== '' && Number(input.value) !== Number(input.dataset.current || NaN);
                input.classList.toggle('border-warning', isChanged);
                input.classList.toggle('bg-warning-subtle', isChanged);
                if (isChanged) changed++;
            });
            button.textContent = changed ? label + ' (' + changed + ')' : label;
        };
        form.addEventListener('input', refresh);
        refresh();
    });
});
</script>
<?= $this->endSection() ?>
