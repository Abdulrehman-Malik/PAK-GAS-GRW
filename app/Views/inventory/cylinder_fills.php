<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$typeData = [];
foreach ($types as $t) {
    $typeData[(string)$t['id']] = ['empty' => (int)($stock[(int)$t['id']]['empty'] ?? 0), 'filled' => (int)($stock[(int)$t['id']]['filled'] ?? 0)];
}
$oldDirection = old('direction') === 'full_to_empty' ? 'full_to_empty' : 'empty_to_full';
?>
<div class="mb-3">
    <h4 class="mb-1">Fill / Empty Cylinders</h4>
    <p class="text-muted small mb-0">Convert shop cylinders between empty and full. This changes stock only: no purchase, sale, cost or cash is recorded.</p>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="post" action="<?= site_url('inventory/fill-empty/save') ?>" id="fillForm">
            <?= csrf_field() ?>
            <div class="mb-3">
                <div class="btn-group" role="group" aria-label="Direction">
                    <input type="radio" class="btn-check" name="direction" id="dirToFull" value="empty_to_full" <?= $oldDirection === 'empty_to_full' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="dirToFull">Empty to Full</label>
                    <input type="radio" class="btn-check" name="direction" id="dirToEmpty" value="full_to_empty" <?= $oldDirection === 'full_to_empty' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="dirToEmpty">Full to Empty</label>
                </div>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label mb-1" for="fillType">Cylinder Type</label>
                    <select class="form-select" id="fillType" name="cylinder_type_id" required>
                        <option value="">Select cylinder</option>
                        <?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (string)old('cylinder_type_id') === (string)$t['id'] ? 'selected' : '' ?>><?= esc($t['code'] . ' — ' . $t['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <div class="small text-muted mb-1">Empty Cylinders</div>
                    <div class="fs-4 fw-semibold" id="fillEmpty">-</div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="small text-muted mb-1">Full Cylinders</div>
                    <div class="fs-4 fw-semibold" id="fillFull">-</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1" for="fillQty">Number of Cylinders</label>
                    <input type="number" class="form-control" id="fillQty" name="quantity" min="1" step="1" value="<?= esc(old('quantity') ?? '', 'attr') ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100" id="fillSubmit" disabled>Convert</button>
                </div>
                <div class="col-12">
                    <label class="form-label mb-1" for="fillNotes" id="fillNotesLabel">Note (optional)</label>
                    <input type="text" class="form-control" id="fillNotes" name="notes" maxlength="255" value="<?= esc(old('notes') ?? '', 'attr') ?>">
                </div>
            </div>
            <div class="alert alert-info py-2 small mt-3 mb-0" id="fillHelpFull">Each cylinder is filled to its capacity.</div>
            <div class="alert alert-warning py-2 small mt-3 mb-0 d-none" id="fillHelpEmpty">The gas in these cylinders is removed from stock. Cylinders holding the most gas are emptied first, so part-used cylinders are emptied last.</div>
            <div class="alert alert-warning py-2 small mt-3 mb-0 d-none" id="fillNoEmpty">No empty cylinders are available for this cylinder type.</div>
            <div class="alert alert-warning py-2 small mt-3 mb-0 d-none" id="fillNoFull">No full cylinders are available for this cylinder type.</div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold">History</div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0" id="fillHistory">
            <thead><tr><th>Date / Time</th><th>Direction</th><th>Cylinder</th><th class="text-end">Cylinders</th><th class="text-end">Gas Change (KG)</th><th class="text-end">Empty Before</th><th class="text-end">Empty After</th><th>Note</th><th>User</th></tr></thead>
            <tbody>
            <?php if (!$history): ?><tr><td colspan="9" class="text-muted text-center py-3">No conversions yet.</td></tr><?php endif; ?>
            <?php foreach ($history as $h): $toFull = $h['direction'] === 'empty_to_full'; ?>
                <tr>
                    <td><?= esc(date('d-M-Y h:i A', strtotime($h['created_at']))) ?></td>
                    <td><?php if ($toFull): ?><span class="badge text-bg-success">Empty to Full</span><?php else: ?><span class="badge text-bg-secondary">Full to Empty</span><?php endif; ?></td>
                    <td><?= esc($h['cylinder_code'] . ' — ' . $h['cylinder_name']) ?></td>
                    <td class="text-end"><?= (int)$h['quantity'] ?></td>
                    <td class="text-end"><?= ($toFull ? '+' : '-') . number_format((float)$h['total_gas_kg'], 2) ?></td>
                    <td class="text-end"><?= (int)$h['empty_before'] ?></td>
                    <td class="text-end"><?= (int)$h['empty_after'] ?></td>
                    <td><?= esc($h['notes'] ?? '') ?></td>
                    <td><?= esc($h['created_by_name'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const data = <?= json_encode($typeData, JSON_HEX_TAG) ?>;
    const el = id => document.getElementById(id);
    const type = el('fillType'), qty = el('fillQty'), notes = el('fillNotes'), submit = el('fillSubmit');
    const labels = {optional: el('fillNotesLabel').textContent, required: <?= json_encode('Reason') ?>};

    function refresh() {
        const toEmpty = el('dirToEmpty').checked, row = data[type.value];
        el('fillEmpty').textContent = row ? row.empty : '-';
        el('fillFull').textContent = row ? row.filled : '-';
        const available = row ? (toEmpty ? row.filled : row.empty) : 0;
        qty.max = available;
        qty.disabled = !!row && available === 0;
        el('fillNoEmpty').classList.toggle('d-none', toEmpty || !row || available > 0);
        el('fillNoFull').classList.toggle('d-none', !toEmpty || !row || available > 0);
        el('fillHelpFull').classList.toggle('d-none', toEmpty);
        el('fillHelpEmpty').classList.toggle('d-none', !toEmpty);
        el('fillNotesLabel').textContent = toEmpty ? labels.required : labels.optional;
        notes.required = toEmpty;
        const count = Number(qty.value || 0);
        submit.disabled = !row || available === 0 || !Number.isInteger(count) || count < 1 || count > available || (toEmpty && notes.value.trim() === '');
    }

    [type, el('dirToFull'), el('dirToEmpty')].forEach(field => field.addEventListener('change', refresh));
    [qty, notes].forEach(field => field.addEventListener('input', refresh));
    el('fillForm').addEventListener('submit', event => {
        if (el('dirToEmpty').checked && !confirm('Empty these cylinders? Their gas will be removed from stock.')) event.preventDefault();
    });
    refresh();
})();
</script>
<?= $this->endSection() ?>
