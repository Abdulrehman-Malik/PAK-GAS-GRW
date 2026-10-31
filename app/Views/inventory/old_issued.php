<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$oldRows = old('rows');
$oldRows = is_array($oldRows) && $oldRows ? array_values($oldRows) : [['issued_on' => date('Y-m-d')]];
?>
<div class="mb-3">
    <h4 class="mb-1">Old Issued Cylinders</h4>
    <p class="text-muted small mb-0">Record cylinders given to customers before using this system. They can be received back on the Cylinder Return screen.</p>
</div>

<div class="alert alert-warning py-2 small">
    Enter only cylinders that are with customers now. Do not include them in shop opening stock.
    Saving adds to the customer's issued cylinders and OS. Sales, cash and shop stock do not change.
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="post" action="<?= site_url('inventory/old-issued/save') ?>" id="oldIssuedForm">
            <?= csrf_field() ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="oldIssuedTable">
                    <thead>
                        <tr>
                            <th>Date</th><th>Customer</th><th>Cylinder</th><th style="width:90px">Qty</th><th style="width:130px">Rate / Cylinder</th>
                            <th class="text-end">Amount</th><th style="width:130px">Received</th><th class="text-end">Outstanding</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($oldRows as $i => $row): ?>
                        <tr class="oldIssuedRow">
                            <td><input type="date" class="form-control form-control-sm rowDate" name="rows[<?= (int)$i ?>][issued_on]" value="<?= esc($row['issued_on'] ?? date('Y-m-d'), 'attr') ?>" max="<?= date('Y-m-d') ?>" required></td>
                            <td>
                                <select class="form-select form-select-sm rowCustomer" name="rows[<?= (int)$i ?>][customer_id]" required>
                                    <option value="">Select customer</option>
                                    <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)($row['customer_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>><?= esc(trim(($c['code'] ?? '') . ' ' . $c['name'])) ?></option><?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select class="form-select form-select-sm rowType" name="rows[<?= (int)$i ?>][cylinder_type_id]" required>
                                    <option value="">Select cylinder</option>
                                    <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (string)($row['cylinder_type_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= esc($t['code'] . ' — ' . $t['name']) ?></option><?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" class="form-control form-control-sm rowQty" name="rows[<?= (int)$i ?>][quantity]" value="<?= esc($row['quantity'] ?? '', 'attr') ?>" min="1" max="500" step="1" required></td>
                            <td><input type="number" class="form-control form-control-sm rowRate" name="rows[<?= (int)$i ?>][rate_per_cylinder]" value="<?= esc($row['rate_per_cylinder'] ?? '', 'attr') ?>" min="0" step="0.01"></td>
                            <td class="text-end rowAmount">0.00</td>
                            <td><input type="number" class="form-control form-control-sm rowReceived" name="rows[<?= (int)$i ?>][amount_received]" value="<?= esc($row['amount_received'] ?? '', 'attr') ?>" min="0" step="0.01"></td>
                            <td class="text-end rowOutstanding">0.00</td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger rowRemove" aria-label="Remove row">&times;</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="addRow">+ Add Row</button>
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label mb-1" for="oldIssuedNote">Note (optional)</label>
                    <input type="text" class="form-control" id="oldIssuedNote" name="note" maxlength="255" value="<?= esc(old('note') ?? '', 'attr') ?>" placeholder="For example: old register page 12">
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="small mb-2" id="oldIssuedTotals"></div>
                    <button type="submit" class="btn btn-primary">Save All</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">Recently Entered</div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Date</th><th>Customer</th><th>Cylinder</th><th class="text-end">Qty</th><th class="text-end">Rate / Cylinder</th><th class="text-end">Amount</th><th class="text-end">Received</th><th class="text-end">Outstanding</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$entries): ?><tr><td colspan="10" class="text-muted text-center py-3">No entries yet.</td></tr><?php endif; ?>
            <?php foreach ($entries as $e): ?>
                <tr>
                    <td><?= esc(date('d-M-Y', strtotime($e['issued_on']))) ?></td>
                    <td><?= esc($e['customer_name']) ?></td>
                    <td><?= esc($e['cylinder_code'] . ' — ' . $e['cylinder_name']) ?></td>
                    <td class="text-end"><?= (int)$e['quantity'] ?></td>
                    <td class="text-end"><?= number_format((float)$e['rate_per_cylinder'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$e['amount'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$e['amount_received'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$e['outstanding'], 2) ?></td>
                    <td>
                        <?php if ($e['status'] === 'voided'): ?><span class="badge text-bg-secondary" title="<?= esc($e['void_reason'] ?? '', 'attr') ?>">Voided</span>
                        <?php else: ?><span class="badge text-bg-success">Posted</span><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($e['status'] === 'posted'): ?>
                        <form method="post" action="<?= site_url('inventory/old-issued/void/' . (int)$e['id']) ?>" class="d-flex gap-1 justify-content-end">
                            <?= csrf_field() ?>
                            <input type="text" class="form-control form-control-sm" style="width:150px" name="reason" maxlength="255" placeholder="Reason" required>
                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Void this entry? Its cylinders and outstanding amount will be removed from the customer.');">Void</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const table = document.querySelector('#oldIssuedTable tbody');
    let nextIndex = table.querySelectorAll('tr').length;
    const money = value => Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

    function recalc() {
        let cylinders = 0, outstanding = 0;
        table.querySelectorAll('tr').forEach(row => {
            const qty = Number(row.querySelector('.rowQty').value || 0);
            const amount = Math.round(qty * Number(row.querySelector('.rowRate').value || 0) * 100) / 100;
            const received = row.querySelector('.rowReceived');
            const left = Math.round((amount - Number(received.value || 0)) * 100) / 100;
            row.querySelector('.rowAmount').textContent = money(amount);
            row.querySelector('.rowOutstanding').textContent = money(left);
            row.querySelector('.rowOutstanding').classList.toggle('text-danger', left < 0);
            received.setCustomValidity(left < 0 ? 'Received amount cannot be more than the amount.' : '');
            cylinders += qty;
            outstanding += Math.max(0, left);
        });
        document.getElementById('oldIssuedTotals').textContent = cylinders + ' cylinders | Outstanding: Rs. ' + money(outstanding);
    }

    document.getElementById('addRow').addEventListener('click', () => {
        const rows = table.querySelectorAll('tr');
        const last = rows[rows.length - 1];
        const row = last.cloneNode(true);
        row.querySelectorAll('[name]').forEach(field => {
            field.name = field.name.replace(/rows\[\d+\]/, 'rows[' + nextIndex + ']');
            if (field.classList.contains('rowDate')) field.value = last.querySelector('.rowDate').value;
            else if (field.tagName === 'SELECT') field.value = field.classList.contains('rowCustomer') ? last.querySelector('.rowCustomer').value : '';
            else field.value = '';
        });
        nextIndex++;
        table.appendChild(row);
        recalc();
        row.querySelector('.rowType').focus();
    });

    table.addEventListener('click', event => {
        const button = event.target.closest('.rowRemove');
        if (!button) return;
        if (table.querySelectorAll('tr').length > 1) button.closest('tr').remove();
        recalc();
    });
    table.addEventListener('input', recalc);
    recalc();
})();
</script>
<?= $this->endSection() ?>
