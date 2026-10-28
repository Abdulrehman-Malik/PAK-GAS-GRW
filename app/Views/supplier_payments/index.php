<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<div class="mb-3">
    <h4 class="mb-1">Supplier Account Payment</h4>
    <div class="text-muted small">Pay an outstanding supplier balance without purchasing or receiving any stock.</div>
</div>

<form method="post" action="<?= site_url('supplier-payments/save') ?>" class="card shadow-sm" id="supplierPaymentForm">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5">
                <label class="form-label fw-semibold">Supplier</label>
                <select name="supplier_id" id="supplier_id" class="form-select" required>
                    <option value="">Select supplier</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= esc($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-3">
                <label class="form-label fw-semibold">Outstanding Payable</label>
                <div class="form-control bg-light fw-semibold" id="outstanding">Rs. 0.00</div>
            </div>

            <div class="col-lg-4">
                <label class="form-label fw-semibold">Payment Amount</label>
                <input name="amount" id="amount" type="number" min=".01" step=".01" class="form-control" placeholder="Enter amount" required>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Payment Method</label>
                <select name="payment_mode" id="payment_mode" class="form-select">
                    <option value="cash">Cash</option>
                    <option value="online">Online</option>
                    <option value="cheque">Cheque</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Reference / Cheque No.</label>
                <input name="reference_no" class="form-control" placeholder="Optional">
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Remaining Payable</label>
                <div class="form-control bg-light fw-bold" id="remaining">Rs. 0.00</div>
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Notes</label>
                <input name="notes" class="form-control" placeholder="Optional payment notes">
            </div>
        </div>

        <div class="alert alert-info mt-4 mb-3">
            <strong>Supplier Account Payment:</strong>
            This transaction reduces the supplier's outstanding payable only. It does <strong>not</strong> add or remove inventory and does <strong>not</strong> require a purchase.
        </div>

        <button class="btn btn-primary w-100" id="payButton" type="submit">
            Record Supplier Payment
        </button>
    </div>
</form>

<script>
const balances = <?= json_encode($balances ?? []) ?>;
const money = value => fmtMoney(value);

function refreshBalance() {
    const supplierId = document.getElementById('supplier_id').value;
    // A negative balance means the supplier is already overpaid (advance).
    const outstanding = Number(balances[supplierId] || 0);
    const amount = Math.max(0, Number(document.getElementById('amount').value || 0));
    const remaining = outstanding - amount;

    document.getElementById('outstanding').textContent = balanceLabel(outstanding);
    document.getElementById('remaining').textContent = balanceLabel(remaining);
    document.getElementById('amount').classList.remove('is-invalid');
    document.getElementById('payButton').disabled = !supplierId || amount <= 0;

    if (!supplierId) {
        document.getElementById('outstanding').textContent = 'Rs. 0.00';
        document.getElementById('remaining').textContent = 'Rs. 0.00';
    }
}

function balanceLabel(value) {
    return value < -0.005 ? 'Advance Rs. ' + money(Math.abs(value)) : 'Rs. ' + money(value);
}

document.getElementById('supplier_id').addEventListener('change', refreshBalance);
document.getElementById('amount').addEventListener('input', refreshBalance);

document.getElementById('supplierPaymentForm').addEventListener('submit', function (event) {
    const supplierId = document.getElementById('supplier_id').value;
    const amount = Number(document.getElementById('amount').value || 0);

    if (!supplierId || amount <= 0) {
        event.preventDefault();
        alert('Select a supplier and enter a payment amount greater than zero.');
    }
});

refreshBalance();
</script>

<?= $this->endSection() ?>
