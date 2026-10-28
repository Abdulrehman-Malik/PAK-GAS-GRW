// Scenario: two customers with different special rates for the same cylinder type
// (C11, 11.8 KG). Each gets one filled cylinder on custody (Security Deposit / Issue
// Cylinder), stock is checked, both cylinders are returned with the deposit refunded,
// and stock is checked again. Uses its own customers (SR Customer A / B) and their
// special rates only, so the shop's price-list rates are not changed.
//
// Run (visible): LPG_SLOWMO=400 npx playwright test tests/e2e/custody-special-rates.spec.mjs --headed --reporter=line
// The cases share state, so run the whole file, in order.
import { test, expect } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const TYPE = 'C11';
const CAPACITY = 11.8;
const DEPOSIT = 2000;
const CUSTOMERS = [
  { name: 'SR Customer A', fullCylinder: 11000 },
  { name: 'SR Customer B', fullCylinder: 10500 },
];

let page;
let typeId;
const ids = {};          // customer name -> id
const issued = {};       // customer name -> { unitId, unitCode, gas, total }
let before;              // stock snapshot before issuing

const round2 = (n) => Math.round(n * 100) / 100;
const log = (...a) => console.log('   ', ...a);

async function login() {
  await page.goto('login');
  const gate = page.getByRole('button', { name: /Execute Pending Migrations/i });
  if (await gate.count()) { await gate.click(); await page.waitForURL(/\/login/); }
  await page.fill('input[name="login"]', 'admin');
  await page.fill('input[name="password"]', 'admin123');
  await Promise.all([page.waitForURL(/\/dashboard/), page.click('button[type="submit"]')]);
}

async function optionValue(select, startsWith) {
  const option = select.locator('option').filter({ hasText: new RegExp('^\\s*' + startsWith + '\\b') }).first();
  return option.getAttribute('value');
}

/** Stock as the POS sees it, for the C11 type and the two customers. */
async function stockSnapshot() {
  await page.goto('sales');
  const s = await page.evaluate(({ typeId, ids }) => {
    const units = (filledUnits[typeId] || []);
    const custody = allCustomerCustody.filter((u) => Object.values(ids).includes(String(u.customer_id)));
    return {
      filled: Number(filledStock[typeId] || 0),
      filledKg: Math.round(units.reduce((t, u) => t + Number(u.gas_weight_kg || 0), 0) * 1000) / 1000,
      empty: Number(emptyStock[typeId] || 0),
      custody: custody.map((u) => ({ unit: Number(u.unit_id), customer: String(u.customer_id) })),
      deposits: Object.fromEntries(Object.entries(ids).map(([n, id]) => [n, Number((window.depositBalances || {})[id] || 0)])),
    };
  }, { typeId, ids });
  return s;
}

/** Clicks Post and returns the server's JSON reply. */
async function post() {
  let reply = null;
  await page.route('**/sales/save', async (route) => {
    const response = await route.fetch();
    reply = await response.json().catch(() => ({ success: false, error: 'HTTP ' + response.status() }));
    await route.fulfill({ response });
  });
  await page.click('#saveBtn');
  await expect.poll(() => reply, { timeout: 20_000 }).not.toBeNull();
  await page.unroute('**/sales/save');
  if (reply.success) await page.waitForLoadState('load');
  return reply;
}
const details = async (id) => (await page.request.get('sales/details/' + id)).json();

test.beforeAll(async ({ browser }) => {
  page = await browser.newPage({ baseURL: test.info().project.use.baseURL });
  page.on('dialog', (d) => d.accept());
  await login();
  await page.goto('sales');
  typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), TYPE);
  expect(typeId, `Cylinder type ${TYPE} must exist`).toBeTruthy();
});
test.afterAll(async () => { await page.close(); });

test('CS-01 Two new customers are defined', async () => {
  await page.goto('customers');
  for (const c of CUSTOMERS) {
    if (!(await page.locator('table.datatable tbody tr', { hasText: c.name }).count())) {
      await page.locator('button[data-bs-target="#form"]').click();
      await page.fill('#form input[name="name"]', c.name);
      await page.selectOption('#form select[name="customer_type"]', 'retail');
      await Promise.all([page.waitForURL(/\/customers/), page.getByRole('button', { name: 'Save Customer' }).click()]);
    }
    await expect(page.locator('table.datatable tbody tr', { hasText: c.name })).toHaveCount(1);
  }
  await page.goto('sales');
  for (const c of CUSTOMERS) ids[c.name] = await page.locator('#customer_id option').filter({ hasText: new RegExp('^' + c.name + '$') }).first().getAttribute('value');
  log('Customers:', JSON.stringify(ids));
});

test('CS-02 Each customer gets a different special rate for the same cylinder type', async () => {
  for (const c of CUSTOMERS) {
    await page.goto('rates?customer=' + ids[c.name] + '#rates-customer');
    await expect(page.locator('#rates-customer')).toBeVisible();
    const input = page.locator('#rates-customer tbody tr').filter({ hasText: new RegExp('^\\s*' + TYPE + ' ') }).locator('input[name$="[gas_per_cylinder]"]');
    if ((await input.inputValue()) !== c.fullCylinder.toFixed(2)) {
      await input.fill(String(c.fullCylinder));
      await page.fill('#rates-customer input[name="reason"]', 'Custody special-rate scenario');
      await Promise.all([page.waitForURL(/\/rates/), page.locator('#rates-customer .rate-save').click()]);
    }
    await expect(input).toHaveValue(c.fullCylinder.toFixed(2));
    const book = await (await page.request.get('sales/rates?customer_id=' + ids[c.name])).json();
    const gas = book.books.retail[typeId].cylinder_gas_per_kg;
    expect(gas.source).toBe('customer_special');
    expect(gas.rate).toBeCloseTo(c.fullCylinder / CAPACITY, 4);
    log(`${c.name}: ${TYPE} full cylinder ${c.fullCylinder} -> ${(c.fullCylinder / CAPACITY).toFixed(4)} per KG (${gas.source})`);
  }
});

test('CS-03 Stock before issuing', async () => {
  before = await stockSnapshot();
  log('Before:', JSON.stringify(before));
  expect(before.filled, 'need at least 2 filled C11 cylinders').toBeGreaterThanOrEqual(2);
  expect(before.custody, 'the two customers start with no cylinders').toEqual([]);
});

for (const c of CUSTOMERS) {
  test(`CS-04 Issue one filled ${TYPE} to ${c.name} at the special rate`, async () => {
    await page.goto('sales');
    await page.selectOption('#transactionType', 'security_deposit');
    await page.selectOption('#customer_id', ids[c.name]);
    await expect(page.locator('#specialRateBadge')).toBeVisible();
    await page.click('#openIssuePicker');
    await page.selectOption('#issuePickerType', typeId);
    await page.selectOption('#issuePickerStatus', 'filled');
    const card = page.locator('#issuePickerCards label').first();
    const unitId = Number(await card.locator('input').getAttribute('value'));
    const unitCode = (await card.locator('.cylinder-code').innerText()).trim();
    const gas = Number(((await card.innerText()).match(/([\d.]+) KG/) || [])[1]);
    await card.click();
    await page.click('#confirmIssuePicker');
    const perKg = c.fullCylinder / CAPACITY;
    await expect(page.locator('#issueLinesBody .issueGasRateInput').first()).toHaveValue(perKg.toFixed(2));
    const charge = round2(gas * perKg);
    await page.fill('#securityDeposit', String(DEPOSIT));
    await page.locator('.payment .amount').first().fill(String(round2(charge + DEPOSIT)));
    const reply = await post();
    expect(reply.error ?? '').toBe('');
    const d = await details(reply.id);
    expect(Number(d.sale.total_amount)).toBeCloseTo(charge, 2);
    expect(Number(d.sale.security_deposit_amount)).toBeCloseTo(DEPOSIT, 2);
    expect(d.items[0].rate_source).toBe('customer_special');
    expect(Number(d.items[0].cylinder_price)).toBe(0);
    issued[c.name] = { unitId, unitCode, gas, total: charge };
    log(`${reply.sale_no}: ${c.name} got ${unitCode} (${gas} KG) at ${perKg.toFixed(4)}/KG = ${charge}, deposit ${DEPOSIT}, source ${d.items[0].rate_source}`);
  });
}

test('CS-05 Stock after issuing', async () => {
  const after = await stockSnapshot();
  log('After issue:', JSON.stringify(after));
  const gasOut = CUSTOMERS.reduce((t, c) => t + issued[c.name].gas, 0);
  expect(after.filled).toBe(before.filled - 2);
  expect(after.filledKg).toBeCloseTo(before.filledKg - gasOut, 3);
  expect(after.empty).toBe(before.empty);
  for (const c of CUSTOMERS) {
    expect(after.custody).toContainEqual({ unit: issued[c.name].unitId, customer: ids[c.name] });
    expect(after.deposits[c.name]).toBeCloseTo(before.deposits[c.name] + DEPOSIT, 2);
  }
});

for (const c of CUSTOMERS) {
  test(`CS-06 ${c.name} returns the cylinder and the deposit is refunded`, async () => {
    await page.goto('sales');
    await page.selectOption('#transactionType', 'cylinder_return');
    await page.selectOption('#customer_id', ids[c.name]);
    await page.click('#openReturnPicker');
    await page.locator(`#returnPickerCards input.returnPickCheck[value="${issued[c.name].unitId}"]`).locator('xpath=..').click();
    await page.click('#confirmReturnPicker');
    await expect(page.locator('#returnLinesBody')).toContainText(issued[c.name].unitCode);
    await page.fill('#refundAmount', String(DEPOSIT));
    await page.locator('#refundAmount').dispatchEvent('input');
    await page.locator('.payment .amount').first().fill(String(DEPOSIT));
    const reply = await post();
    expect(reply.error ?? '').toBe('');
    const d = await details(reply.id);
    expect(d.sale.transaction_type).toBe('cylinder_return');
    expect(Number(d.sale.security_deposit_refund_amount)).toBeCloseTo(DEPOSIT, 2);
    log(`${reply.sale_no}: ${c.name} returned ${issued[c.name].unitCode}, refund ${d.sale.security_deposit_refund_amount}`);
  });
}

test('CS-07 Stock after the returns', async () => {
  const after = await stockSnapshot();
  log('After return:', JSON.stringify(after));
  // The customers used the gas: both cylinders come back empty.
  expect(after.filled).toBe(before.filled - 2);
  expect(after.empty).toBe(before.empty + 2);
  expect(after.custody).toEqual([]);
  for (const c of CUSTOMERS) expect(after.deposits[c.name]).toBeCloseTo(before.deposits[c.name], 2);
});
