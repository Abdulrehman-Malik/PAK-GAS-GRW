// Browser test cases for Retail / Wholesale pricing — see testcase.md for the case list.
// Every case drives the real screens (Shop Settings, Customers, LPG Rates, POS) so the run
// can be watched. Server-only guards that the POS screen deliberately prevents
// (POS-P08, POS-P10, POS-P16) are checked with direct requests.
//
// Run:  npm run test:pricing:watch      (visible Chrome)
//       npm run test:pricing            (headless)
// The app must be running locally with the admin / admin123 login (LPG_BASE_URL to override).
// Data: creates TC customers, rates and opening stock when missing, posts test sales, and
// restores the Pricing settings to their defaults at the end.
import { test, expect } from '@playwright/test';

// Each case is self-contained (signs in and sets up its own screen), so cases can be run
// one at a time:  npx playwright test tests/e2e/pricing.spec.mjs -g "POS-P01 " --headed
test.describe.configure({ mode: 'serial' });

const DEFAULTS = { wholesale: true, defaultRate: 'retail', walkin: 'retail', special: true, rateMode: 'reason_required', switch: false };
const WHOLESALE_PER_KG = 3300 / 11.8; // 279.66

let page;
let pricingChanged = false;
const jsErrors = [];

// ---------- helpers ----------
async function login() {
  await page.goto('login');
  const gate = page.getByRole('button', { name: /Execute Pending Migrations/i });
  if (await gate.count()) {
    await gate.click();
    await page.waitForURL(/\/login/);
  }
  await page.fill('input[name="login"]', 'admin');
  await page.fill('input[name="password"]', 'admin123');
  await Promise.all([page.waitForURL(/\/dashboard/), page.click('button[type="submit"]')]);
}

async function optionValue(select, startsWith) {
  const option = select.locator('option').filter({ hasText: new RegExp('^\\s*' + startsWith.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b') }).first();
  return option.getAttribute('value');
}

async function setPricing(s) {
  pricingChanged = s !== DEFAULTS;
  await page.goto('shop-settings');
  await page.click('button[data-bs-target="#pricing"]');
  await expect(page.locator('#pricing')).toBeVisible();
  if ('wholesale' in s) await page.setChecked('#wholesalePriceListEnabled', s.wholesale);
  if (s.defaultRate && (await page.isEnabled('select[name="default_price_list"]'))) await page.selectOption('select[name="default_price_list"]', s.defaultRate);
  if (s.walkin && (await page.isEnabled('select[name="walkin_price_list"]'))) await page.selectOption('select[name="walkin_price_list"]', s.walkin);
  if ('special' in s) await page.setChecked('#customerSpecialRatesEnabled', s.special);
  if (s.rateMode) await page.selectOption('select[name="pos_rate_change_mode"]', s.rateMode);
  if ('switch' in s && (await page.isEnabled('#posPriceListSwitch'))) await page.setChecked('#posPriceListSwitch', s.switch);
  await Promise.all([page.waitForURL(/\/shop-settings/), page.getByRole('button', { name: 'Save Shop Settings' }).click()]);
  await expect(page.locator('body')).toContainText('Shop settings saved successfully.');
}

async function customerId(name) {
  await page.goto('sales');
  return page.locator('#customer_id option').filter({ hasText: new RegExp('^' + name + '$') }).first().getAttribute('value');
}

/** Opens POS on a transaction type with an optional customer; returns the first line row. */
async function openPos(type, customerName = '', priceList = '') {
  await page.goto('sales');
  await page.selectOption('#transactionType', type);
  if (customerName) {
    const id = await page.locator('#customer_id option').filter({ hasText: new RegExp('^' + customerName + '$') }).first().getAttribute('value');
    await page.selectOption('#customer_id', id);
  }
  if (priceList) await page.selectOption('#priceList', priceList);
  return page.locator('#lines tbody tr:not(.cylinderPickerRow)').first();
}

async function fillGasLine({ mode = 'quantity', type = 'C11_8', qty, rate, reason, pay }) {
  await page.selectOption('#gasEntryMode', mode);
  const row = page.locator('#lines tbody tr').first();
  await row.locator('.cyl').selectOption(await optionValue(row.locator('.cyl'), type));
  await row.locator('.entryValue').fill(String(qty));
  if (rate !== undefined) await row.locator('.gasRate').fill(String(rate));
  if (reason) await page.fill('#rateChangeReason', reason);
  if (pay !== undefined) await page.locator('.payment .amount').first().fill(String(pay));
  return row;
}

/** Clicks Post and returns the server's JSON reply (the POS reloads after a successful post). */
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

async function saleDetails(id) {
  return (await page.request.get('sales/details/' + id)).json();
}

async function expectPosted(reply, { total, priceList, source }) {
  expect(reply.error ?? '').toBe('');
  expect(reply.success).toBe(true);
  const d = await saleDetails(reply.id);
  expect(Number(d.sale.total_amount)).toBeCloseTo(total, 2);
  expect(d.sale.price_list).toBe(priceList);
  if (source) expect(d.items[0].rate_source).toBe(source);
  test.info().annotations.push({ type: 'sale', description: `${reply.sale_no}: total ${d.sale.total_amount}, price list ${d.sale.price_list}, line source ${d.items[0].rate_source}` });
  return d;
}

/** Posts directly to the server, bypassing POS screen checks, to prove the server enforces a rule. */
async function postRaw(fields) {
  const html = await (await page.request.get('sales')).text();
  const token = html.match(/name="csrf_test_name" value="([^"]+)"/)[1];
  const res = await page.request.post('sales/save', {
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    form: { csrf_test_name: token, discount_amount: '0', stock_override_confirmed: '0', ...fields },
  });
  return res.json();
}
const rawGasLine = (typeId, qty, rate = '') => JSON.stringify([{ cylinder_type_id: String(typeId), source_cylinder_unit_id: '', quantity: String(qty), cylinder_quantity: '0', gas_entry_mode: 'quantity', gas_rate: String(rate), entered_amount: '', customer_cylinder_unit_id: '' }]);
const rawCash = (amount) => JSON.stringify([{ payment_type: 'sale', payment_mode: 'cash', amount: String(amount) }]);
const nowLocal = () => { const d = new Date(), p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };

// ---------- lifecycle ----------
test.beforeAll(async ({ browser }) => {
  page = await browser.newPage({ baseURL: test.info().project.use.baseURL });
  page.on('pageerror', (e) => jsErrors.push(page.url() + ': ' + e.message));
  // The CI debug toolbar (development only) loads its panel from ?debugbar_time=…; under parallel
  // runs its history clean-up can fail with a 500. That is framework noise, not an app error.
  const toolbarRequest = (url) => /[?&]debugbar(_time)?=/.test(url || '');
  page.on('console', (m) => { if (m.type() === 'error' && !toolbarRequest(m.location()?.url)) jsErrors.push(page.url() + ': ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 500 && !toolbarRequest(r.url())) jsErrors.push(`HTTP ${r.status()} ${r.url()}`); });
  page.on('dialog', (d) => d.accept());
  await login();
});

test.afterAll(async () => {
  // Leave the shop on the default Pricing settings.
  if (pricingChanged) await setPricing(DEFAULTS).catch(() => {});
  await page.close();
});

// ---------- S. Setup ----------
test('S-01 Migrations applied and admin can sign in', async () => {
  // beforeAll ran the migration gate (if any) and signed in.
  await page.goto('dashboard');
  await expect(page).toHaveURL(/\/dashboard/);
});

test('S-02 Cash session is open', async () => {
  await page.goto('cash');
  const openForm = page.locator('form[action$="cash/open"]');
  if (await openForm.count()) {
    await openForm.locator('input[name="opening_cash"]').fill('0');
    await openForm.locator('input[name="notes"]').fill('Pricing browser test');
    await Promise.all([page.waitForURL(/\/cash/), openForm.locator('button').click()]);
  }
  await expect(page.locator('body')).toContainText('Cash session: OPEN');
});

test('S-03 Opening stock is enough for the run', async () => {
  // Tops up stock with Opening Inventory entries when an earlier run used it up.
  const stock = async () => {
    await page.goto('sales');
    await page.selectOption('#transactionType', 'gas_sale');
    const text = async (code) => (await page.locator('#lines tbody tr .cyl option').filter({ hasText: new RegExp('^' + code + ' ') }).first().textContent()) || '';
    const parse = (t) => ({ kg: Number((t.match(/Available Stock: ([\d.]+) KG/) || [])[1] || 0), units: Number((t.match(/\((\d+) cylinder/) || [])[1] || 0) });
    return { c11: parse(await text('C11_8')), c45: parse(await text('C45')) };
  };
  // One opening entry is allowed per cylinder type and day, so each top-up uses its own earlier day.
  let dayBack = 1 + (Math.floor(Date.now() / 60000) % 300);
  const addOpening = async (code, qty, gas) => {
    await page.goto('inventory/opening');
    await page.locator('button[onclick="prepareAdd()"]').click();
    const d = new Date(Date.now() - dayBack++ * 864e5), p = (n) => String(n).padStart(2, '0');
    await page.fill('#inventoryDate', `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`);
    await page.selectOption('#cylinderTypeId', await optionValue(page.locator('#cylinderTypeId'), code));
    await page.fill('#quantity', String(qty));
    await page.fill('#actualGasWeight', String(gas));
    await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Save Opening Inventory' }).click()]);
  };
  let s = await stock();
  if (s.c11.kg < 45 || s.c11.units < 4) await addOpening('C11_8', 4, 11.8);
  if (s.c45.kg < 2) await addOpening('C45', 1, 45);
  await addOpening('C11_8', 3, 0); // empty cylinders for POS-P13, POS-P19 and CR-04
  s = await stock();
  expect(s.c11.kg).toBeGreaterThanOrEqual(45);
  expect(s.c45.kg).toBeGreaterThanOrEqual(2);
});

// ---------- LPG Rates screen helpers ----------
async function openRates(tab) {
  await page.goto('rates');
  await page.click(`#rateTabs [data-bs-target="#rates-${tab}"]`);
  await expect(page.locator(`#rates-${tab}`)).toBeVisible();
}
/** A grid input: item gas_per_kg (no cylinder) or gas_per_cylinder / empty_cylinder for a cylinder code. */
function rateInput(list, item, code = '') {
  if (item === 'gas_per_kg') return page.locator(`#rates-${list} input[name="rates[gas_per_kg]"]`);
  return page.locator(`#rates-${list} tbody tr`).filter({ hasText: new RegExp('^\\s*' + code + ' ') }).locator(`input[name$="[${item}]"]`);
}
async function saveRates(list, effectiveFrom = '', reason = 'Pricing browser test') {
  if (effectiveFrom) await page.fill(`#rates-${list} input[name="effective_from"]`, effectiveFrom);
  await page.fill(`#rates-${list} input[name="reason"]`, reason);
  await Promise.all([page.waitForURL(/\/rates/), page.locator(`#rates-${list} .rate-save`).click()]);
}
const flash = () => page.locator('.alert-success, .alert-danger').first();
const futureDate = () => { const d = new Date(Date.now() + 60 * 864e5), p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T09:00`; };

test('S-04 LPG Rates are configured', async () => {
  // Retail gas per KG 300, Retail C11_8 empty cylinder 4500, Wholesale C11_8 full cylinder 3300.
  await openRates('retail');
  let changed = false;
  if ((await rateInput('retail', 'gas_per_kg').inputValue()) !== '300.00') { await rateInput('retail', 'gas_per_kg').fill('300'); changed = true; }
  if ((await rateInput('retail', 'empty_cylinder', 'C11_8').inputValue()) !== '4500.00') { await rateInput('retail', 'empty_cylinder', 'C11_8').fill('4500'); changed = true; }
  if (changed) await saveRates('retail');
  await openRates('wholesale');
  if ((await rateInput('wholesale', 'gas_per_cylinder', 'C11_8').inputValue()) !== '3300.00') {
    await rateInput('wholesale', 'gas_per_cylinder', 'C11_8').fill('3300');
    await saveRates('wholesale');
  }
  await openRates('retail');
  await expect(rateInput('retail', 'gas_per_kg')).toHaveValue('300.00');
  await expect(rateInput('retail', 'empty_cylinder', 'C11_8')).toHaveValue('4500.00');
  await openRates('wholesale');
  await expect(rateInput('wholesale', 'gas_per_cylinder', 'C11_8')).toHaveValue('3300.00');
});

test('S-05 Test customers exist', async () => {
  await page.goto('customers');
  for (const [name, list, credit] of [['TC Shop Default', 'shop_default', true], ['TC Wholesale', 'wholesale', true], ['TC Retail', 'retail', false]]) {
    if (await page.locator('table.datatable tbody tr', { hasText: name }).count()) continue;
    await page.locator('button[data-bs-target="#form"]').click();
    await page.fill('#form input[name="name"]', name);
    await page.selectOption('#form select[name="customer_type"]', list);
    await page.setChecked('#form input[name="allow_credit_sale"]', credit);
    await Promise.all([page.waitForURL(/\/customers/), page.getByRole('button', { name: 'Save Customer' }).click()]);
  }
  for (const name of ['TC Shop Default', 'TC Wholesale', 'TC Retail']) await expect(page.locator('table.datatable tbody tr', { hasText: name })).toHaveCount(1);
});

// ---------- R. LPG Rates screen ----------
test('RT-01 Grid shows the prices in force', async () => {
  await openRates('retail');
  await expect(rateInput('retail', 'gas_per_kg')).toHaveValue('300.00');
  await expect(page.locator('#rates-retail')).toContainText('Since');
  await openRates('wholesale');
  await expect(rateInput('wholesale', 'gas_per_cylinder', 'C11_8')).toHaveValue('3300.00');
});

test('RT-02 Blank prices show what POS will use', async () => {
  await openRates('retail');
  const cylinder = rateInput('retail', 'gas_per_cylinder', 'C11_8');
  await expect(cylinder).toHaveValue('');
  await expect(cylinder).toHaveAttribute('placeholder', '3540.00');
  await expect(page.locator('#rates-retail tbody tr').filter({ hasText: /^\s*C11_8 / })).toContainText('Not set, uses Gas per KG × capacity 3,540.00');
  await openRates('wholesale');
  await expect(page.locator('#rates-wholesale tbody tr').filter({ hasText: /^\s*C11_8 / })).toContainText('Not set, uses Retail 4,500.00');
});

test('RT-03 Zero or invalid prices are rejected', async () => {
  await openRates('retail');
  await rateInput('retail', 'gas_per_kg').evaluate((el) => { el.removeAttribute('min'); el.value = '0'; });
  await saveRates('retail');
  await expect(flash()).toContainText('Rates must be numbers greater than zero');
  await expect(rateInput('retail', 'gas_per_kg')).toHaveValue('300.00');
});

test('RT-04 Saving with no change saves nothing', async () => {
  await openRates('retail');
  await saveRates('retail');
  await expect(flash()).toContainText('No rate changes to save.');
});

test('RT-05 A future price is scheduled, then cancelled', async () => {
  await openRates('retail');
  await rateInput('retail', 'empty_cylinder', 'C45').fill('4300');
  await expect(page.locator('#rates-retail .rate-save')).toContainText('(1)');
  await saveRates('retail', futureDate());
  await expect(flash()).toContainText('1 rate change(s) saved');
  await expect(page.locator('#rates-retail tbody tr').filter({ hasText: /^\s*C45 / })).toContainText('Next: 4,300.00');
  await openRates('history');
  const row = page.locator('#rates-history tbody tr').filter({ hasText: 'Scheduled' }).filter({ hasText: '4,300.00' }).first();
  await expect(row).toBeVisible();
  await Promise.all([page.waitForURL(/\/rates/), row.getByRole('button', { name: 'Cancel' }).click()]);
  await expect(flash()).toContainText('Scheduled rate cancelled.');
  await openRates('retail');
  await expect(page.locator('#rates-retail tbody tr').filter({ hasText: /^\s*C45 / })).not.toContainText('Next:');
});

test('RT-06 A new price applies at POS, and Stop brings back the fallback', async () => {
  await openRates('wholesale');
  await rateInput('wholesale', 'gas_per_cylinder', 'C45').fill('12600');
  await saveRates('wholesale');
  await expect(rateInput('wholesale', 'gas_per_cylinder', 'C45')).toHaveValue('12600.00');
  let book = await (await page.request.get('sales/rates')).json();
  const c45 = await page.locator('#rates-wholesale tbody tr').filter({ hasText: /^\s*C45 / }).locator('input[name$="[gas_per_cylinder]"]').getAttribute('name');
  const c45Id = c45.match(/\[types\]\[(\d+)\]/)[1];
  expect(book.books.wholesale[c45Id].gas_per_kg.rate).toBeCloseTo(280, 2);
  await Promise.all([page.waitForURL(/\/rates/), page.locator('#rates-wholesale tbody tr').filter({ hasText: /^\s*C45 / }).getByRole('button', { name: 'Stop' }).click()]);
  await expect(flash()).toContainText('Price stopped.');
  book = await (await page.request.get('sales/rates')).json();
  expect(book.books.wholesale[c45Id].gas_per_kg.source).toBe('retail_fallback');
  await openRates('history');
  await expect(page.locator('#rates-history tbody tr').filter({ hasText: 'Stopped' }).filter({ hasText: '12,600.00' }).first()).toBeVisible();
});

test('RT-07 Wholesale tab warns when Wholesale is off', async () => {
  await setPricing({ wholesale: false });
  await openRates('wholesale');
  await expect(page.locator('#rates-wholesale')).toContainText('The Wholesale price list is turned off');
  await setPricing(DEFAULTS);
});

test('RT-08 A rate cannot start in the past', async () => {
  await openRates('retail');
  await rateInput('retail', 'empty_cylinder', 'C45').fill('4300');
  // The field's min stops this in the browser; remove it to prove the server rejects it too.
  await page.locator('#rates-retail input[name="effective_from"]').evaluate((el) => el.removeAttribute('min'));
  await saveRates('retail', '2025-01-01T09:00');
  await expect(flash()).toContainText('Effective From cannot be in the past');
  await openRates('retail');
  await expect(page.locator('#rates-retail tbody tr').filter({ hasText: /^\s*C45 / })).not.toContainText('Next:');
});
// ---------- A. Shop Settings → Pricing ----------
test('PS-01 Pricing tab shows the defaults', async () => {
  await setPricing(DEFAULTS);
  await page.click('button[data-bs-target="#pricing"]');
  await expect(page.locator('#wholesalePriceListEnabled')).toBeChecked();
  await expect(page.locator('select[name="default_price_list"]')).toHaveValue('retail');
  await expect(page.locator('select[name="walkin_price_list"]')).toHaveValue('retail');
  await expect(page.locator('#customerSpecialRatesEnabled')).toBeChecked();
  await expect(page.locator('select[name="pos_rate_change_mode"]')).toHaveValue('reason_required');
  await expect(page.locator('#posPriceListSwitch')).not.toBeChecked();
});

test('PS-02 Pricing settings save', async () => {
  await setPricing({ defaultRate: 'wholesale', rateMode: 'allowed', switch: true });
  await page.click('button[data-bs-target="#pricing"]');
  await expect(page.locator('select[name="default_price_list"]')).toHaveValue('wholesale');
  await expect(page.locator('select[name="pos_rate_change_mode"]')).toHaveValue('allowed');
  await expect(page.locator('#posPriceListSwitch')).toBeChecked();
});

test('PS-04 Turning Wholesale off locks the dependent fields', async () => {
  await page.goto('shop-settings');
  await page.click('button[data-bs-target="#pricing"]');
  await page.uncheck('#wholesalePriceListEnabled');
  for (const sel of ['select[name="default_price_list"]', 'select[name="walkin_price_list"]', '#posPriceListSwitch']) await expect(page.locator(sel)).toBeDisabled();
  await expect(page.locator('select[name="default_price_list"]')).toHaveValue('retail');
  await expect(page.locator('#posPriceListSwitch')).not.toBeChecked();
});

test('PS-03 Saving with Wholesale off stores Retail everywhere', async () => {
  await setPricing({ defaultRate: 'wholesale', walkin: 'wholesale', switch: true });
  await page.click('button[data-bs-target="#pricing"]');
  await page.uncheck('#wholesalePriceListEnabled');
  pricingChanged = true;
  await Promise.all([page.waitForURL(/\/shop-settings/), page.getByRole('button', { name: 'Save Shop Settings' }).click()]);
  await page.click('button[data-bs-target="#pricing"]');
  await expect(page.locator('#wholesalePriceListEnabled')).not.toBeChecked();
  await expect(page.locator('select[name="default_price_list"]')).toHaveValue('retail');
  await expect(page.locator('select[name="walkin_price_list"]')).toHaveValue('retail');
  await expect(page.locator('#posPriceListSwitch')).not.toBeChecked();
  await setPricing(DEFAULTS);
});

// ---------- B. Customers ----------
test('CU-01 Customers list shows the Price List', async () => {
  await page.goto('customers');
  await expect(page.locator('table.datatable thead')).toContainText('Price List');
  await expect(page.locator('table.datatable tbody tr', { hasText: 'TC Shop Default' })).toContainText('Shop default');
  await expect(page.locator('table.datatable tbody tr', { hasText: 'TC Wholesale' })).toContainText('Wholesale');
});

test('CU-02 New customer defaults to Shop default', async () => {
  await page.goto('customers');
  await page.locator('button[data-bs-target="#form"]').click();
  await expect(page.locator('#form select[name="customer_type"]')).toHaveValue('shop_default');
  await page.keyboard.press('Escape');
});

// ---------- C. POS pricing ----------
test('POS-P01 Walk-in pays the Retail rate', async () => {
  await openPos('gas_sale');
  await fillGasLine({ qty: 2, pay: 600 });
  await expect(page.locator('#priceListBadge')).toHaveText('Retail');
  await expect(page.locator('#saleTotal')).toHaveText('600.00');
  await expectPosted(await post(), { total: 600, priceList: 'retail', source: 'retail' });
});

test('POS-P02 Changed rate needs a reason', async () => {
  await openPos('gas_sale');
  await fillGasLine({ qty: 2, rate: 280, pay: 560 });
  await expect(page.locator('#rateReasonBox')).toBeVisible();
  await page.click('#saveBtn');
  await expect(page.locator('#posValidationAlert')).toContainText('Enter the reason for the rate change');
});

test('POS-P03 Changed rate with a reason posts and keeps the reason', async () => {
  await openPos('gas_sale');
  await fillGasLine({ qty: 2, rate: 280, reason: 'Regular buyer', pay: 560 });
  const d = await expectPosted(await post(), { total: 560, priceList: 'retail', source: 'manual' });
  expect(d.sale.rate_change_reason).toBe('Regular buyer');
  expect(String(d.items[0].custom_rate_flag)).toBe('1');
});

test('POS-P04 Wholesale customer pays per full cylinder', async () => {
  await openPos('gas_sale', 'TC Wholesale');
  await fillGasLine({ mode: 'cylinders', qty: 1, pay: 3300 });
  await expect(page.locator('#gasRateHeader')).toHaveText('Price / Cylinder');
  await expect(page.locator('#saleTotal')).toHaveText('3,300.00');
  await expectPosted(await post(), { total: 3300, priceList: 'wholesale', source: 'wholesale' });
});

test('POS-P05 Wholesale customer can buy by KG', async () => {
  await openPos('gas_sale', 'TC Wholesale');
  const row = await fillGasLine({ qty: 2, pay: 559.32 });
  await expect(row.locator('.gasRate')).toHaveValue('279.66');
  await expectPosted(await post(), { total: 559.32, priceList: 'wholesale', source: 'wholesale' });
});

test('POS-P06 Shop default customer follows the Default Rate', async () => {
  await setPricing({ defaultRate: 'wholesale' });
  await openPos('gas_sale', 'TC Shop Default');
  await fillGasLine({ qty: 1, pay: 279.66 });
  await expect(page.locator('#priceListBadge')).toHaveText('Wholesale');
  await expectPosted(await post(), { total: 279.66, priceList: 'wholesale' });
});

test('POS-P07 Retail customer ignores the Default Rate', async () => {
  await setPricing({ defaultRate: 'wholesale' });
  await openPos('gas_sale', 'TC Retail');
  await fillGasLine({ qty: 1, pay: 300 });
  await expect(page.locator('#priceListBadge')).toHaveText('Retail');
  await expectPosted(await post(), { total: 300, priceList: 'retail' });
  await setPricing(DEFAULTS);
});

test('POS-P08 Server rejects a price list switch when the switch is off', async () => {
  await openPos('gas_sale');
  const typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), 'C11_8');
  const reply = await postRaw({ transaction_type: 'gas_sale', customer_id: '', transaction_at: nowLocal(), lines_json: rawGasLine(typeId, 1), payments_json: rawCash(279.66), price_list: 'wholesale' });
  expect(reply.success).toBe(false);
  expect(reply.error).toContain('Changing the price list on the invoice is not allowed');
});

test('POS-P09 Walk-in switched to Wholesale on the invoice', async () => {
  await setPricing({ switch: true });
  await openPos('gas_sale', '', 'wholesale');
  await fillGasLine({ qty: 1, pay: 279.66 });
  await expect(page.locator('#priceListBadge')).toHaveText('Wholesale');
  await expectPosted(await post(), { total: 279.66, priceList: 'wholesale' });
  await setPricing(DEFAULTS);
});

test('POS-P10 Server rejects a changed rate when changes are not allowed', async () => {
  await setPricing({ rateMode: 'not_allowed' });
  await openPos('gas_sale');
  const typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), 'C11_8');
  const reply = await postRaw({ transaction_type: 'gas_sale', customer_id: '', transaction_at: nowLocal(), lines_json: rawGasLine(typeId, 1, 250), payments_json: rawCash(250), rate_change_reason: 'Any reason' });
  expect(reply.success).toBe(false);
  expect(reply.error).toContain('Changing the rate at POS is not allowed');
  await setPricing(DEFAULTS);
});

test('POS-P11 Missing Wholesale price falls back to Retail', async () => {
  await openPos('gas_sale', 'TC Wholesale');
  await fillGasLine({ type: 'C45', qty: 1, pay: 300 });
  await expectPosted(await post(), { total: 300, priceList: 'wholesale', source: 'retail_fallback' });
});

test('POS-P12 Wholesale price list off charges Retail', async () => {
  await setPricing({ wholesale: false });
  await openPos('gas_sale', 'TC Wholesale');
  await fillGasLine({ qty: 1, pay: 300 });
  await expect(page.locator('#priceListBadge')).toHaveText('Retail');
  await expectPosted(await post(), { total: 300, priceList: 'retail' });
  await setPricing(DEFAULTS);
});

test('POS-P13 Empty cylinder uses the LPG Rates price', async () => {
  const row = await openPos('cylinder_sale');
  await row.locator('.cyl').selectOption(await optionValue(row.locator('.cyl'), 'C11_8'));
  await row.locator('.cylStatus').selectOption('empty');
  await row.locator('.qty').fill('1');
  await expect(row.locator('.cylRate')).toHaveValue('4500.00');
  await page.locator('.payment .amount').first().fill('4500');
  await expectPosted(await post(), { total: 4500, priceList: 'retail' });
});

test('POS-P14 Security Deposit charges only the gas, at the Wholesale rate', async () => {
  await openPos('security_deposit', 'TC Wholesale');
  await page.click('#openIssuePicker');
  await page.selectOption('#issuePickerType', await optionValue(page.locator('#issuePickerType'), 'C11_8'));
  await page.selectOption('#issuePickerStatus', 'filled');
  const card = page.locator('#issuePickerCards label').first();
  const gas = Number(((await card.innerText()).match(/([\d.]+) KG/) || [])[1]);
  await card.click();
  await page.click('#confirmIssuePicker');
  await page.fill('#securityDeposit', '0');
  // No cylinder price: the cylinder stays company property on custody.
  const expected = Math.round(gas * WHOLESALE_PER_KG * 100) / 100;
  const d = await expectPosted(await post(), { total: expected, priceList: 'wholesale', source: 'wholesale' });
  expect(Number(d.items[0].cylinder_price)).toBe(0);
});

test('POS-P15 Rates follow the transaction date', async () => {
  const row = await openPos('gas_sale');
  await row.locator('.cyl').selectOption(await optionValue(row.locator('.cyl'), 'C11_8'));
  await expect(row.locator('.gasRate')).toHaveValue('300.00');
  await page.fill('input[name="transaction_at"]', '2025-01-01T10:00');
  await page.locator('input[name="transaction_at"]').dispatchEvent('change');
  await expect(row.locator('.gasRate')).toHaveValue('');
});

test('POS-P16 Server rejects an invalid transaction date', async () => {
  await openPos('gas_sale');
  const typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), 'C11_8');
  const reply = await postRaw({ transaction_type: 'gas_sale', customer_id: '', transaction_at: 'not-a-date', lines_json: rawGasLine(typeId, 1), payments_json: rawCash(300) });
  expect(reply.success).toBe(false);
  expect(reply.error).toBe('Invalid transaction date/time.');
});

test('POS-P17 Server rejects a future transaction time', async () => {
  await openPos('gas_sale');
  const typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), 'C11_8');
  const tomorrow = new Date(Date.now() + 864e5), p = (n) => String(n).padStart(2, '0');
  const at = `${tomorrow.getFullYear()}-${p(tomorrow.getMonth() + 1)}-${p(tomorrow.getDate())}T10:00`;
  const reply = await postRaw({ transaction_type: 'gas_sale', customer_id: '', transaction_at: at, lines_json: rawGasLine(typeId, 1), payments_json: rawCash(300) });
  expect(reply.success).toBe(false);
  expect(reply.error).toBe('Transaction date/time cannot be in the future.');
});

test('POS-P18 Backdated time with different prices needs a reason', async () => {
  // On 01-Jan-2025 no rate was set, so the backdated prices differ from today's.
  await openPos('gas_sale');
  const typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), 'C11_8');
  const reply = await postRaw({ transaction_type: 'gas_sale', customer_id: '', transaction_at: '2025-01-01T10:00', lines_json: rawGasLine(typeId, 1, 300), payments_json: rawCash(300) });
  expect(reply.success).toBe(false);
  expect(reply.error).toContain('The transaction time is in the past and its prices differ');
  await page.fill('input[name="transaction_at"]', '2025-01-01T10:00');
  await page.locator('input[name="transaction_at"]').dispatchEvent('change');
  await expect(page.locator('#rateReasonBox')).toBeVisible();
});

test('POS-P19 Security Deposit of an empty cylinder charges nothing', async () => {
  await openPos('security_deposit', 'TC Shop Default');
  await page.click('#openIssuePicker');
  await page.selectOption('#issuePickerType', await optionValue(page.locator('#issuePickerType'), 'C11_8'));
  await page.selectOption('#issuePickerStatus', 'empty');
  await page.locator('#issuePickerCards label').first().click();
  await page.click('#confirmIssuePicker');
  await page.fill('#securityDeposit', '0');
  const d = await expectPosted(await post(), { total: 0, priceList: 'retail' });
  expect(Number(d.items[0].cylinder_price)).toBe(0);
});

test('POS-P20 Gas first: credit cannot pay the Security Deposit', async () => {
  // Shop Settings → POS: payment allocation Gas First. Restored afterwards.
  const setRule = async (rule) => {
    await page.goto('shop-settings');
    await page.click('button[data-bs-target="#pos"]');
    const before = await page.locator('#depositPaymentAllocationRule').inputValue();
    await page.selectOption('#depositPaymentAllocationRule', rule);
    await Promise.all([page.waitForURL(/\/shop-settings/), page.getByRole('button', { name: 'Save Shop Settings' }).click()]);
    return before;
  };
  const previous = await setRule('gas_first');
  try {
    await openPos('security_deposit', 'TC Shop Default');
    await page.click('#openIssuePicker');
    const typeId = await optionValue(page.locator('#issuePickerType'), 'C11_8');
    await page.selectOption('#issuePickerType', typeId);
    await page.selectOption('#issuePickerStatus', 'filled');
    const card = page.locator('#issuePickerCards label').first();
    const gas = Number(((await card.innerText()).match(/([\d.]+) KG/) || [])[1]);
    const unitId = await card.locator('input').getAttribute('value');
    const gasCost = Math.round(gas * 300 * 100) / 100;
    // Credit covering the gas plus a 1,000 deposit: the deposit part must be refused.
    const reply = await postRaw({
      transaction_type: 'security_deposit', customer_id: await customerId('TC Shop Default'), transaction_at: nowLocal(), security_deposit_amount: '1000',
      lines_json: JSON.stringify([{ cylinder_type_id: String(typeId), cylinder_status: 'filled', selected_cylinder_unit_ids: [Number(unitId)], gas_weight_kg: gas, gas_rate: '' }]),
      payments_json: JSON.stringify([{ payment_type: 'combined', payment_mode: 'credit', amount: String(gasCost + 1000) }]),
    });
    expect(reply.success).toBe(false);
    expect(reply.error).toBe('Security Deposit cannot be received on credit.');
  } finally {
    await setRule(previous);
  }
});

// ---------- E. Customer special rates ----------
// TC Special (Retail price list) gets special Gas per KG 290 and a C11_8 empty cylinder price of 4,000.
async function openCustomerRates(name) {
  await page.goto('rates');
  await page.click('#rateTabs [data-bs-target="#rates-customer"]');
  const id = await page.locator('#rateCustomer option').filter({ hasText: new RegExp('^' + name + '\\b') }).first().getAttribute('value');
  await Promise.all([page.waitForURL(new RegExp('customer=' + id)), page.selectOption('#rateCustomer', id)]);
  await expect(page.locator('#rates-customer')).toBeVisible();
  return id;
}
const specialInput = (item, code = '') => item === 'gas_per_kg'
  ? page.locator('#rates-customer input[name="rates[gas_per_kg]"]')
  : page.locator('#rates-customer tbody tr').filter({ hasText: new RegExp('^\\s*' + code + ' ') }).locator(`input[name$="[${item}]"]`);
async function saveSpecial(reason = 'Pricing browser test') {
  await page.fill('#rates-customer input[name="reason"]', reason);
  await Promise.all([page.waitForURL(/\/rates/), page.locator('#rates-customer .rate-save').click()]);
}
async function posRates(customerName) {
  const id = customerName ? await customerId(customerName) : '';
  return (await page.request.get('sales/rates?customer_id=' + id)).json();
}

test('CR-01 Special rates are saved for one customer', async () => {
  await page.goto('customers');
  if (!(await page.locator('table.datatable tbody tr', { hasText: 'TC Special' }).count())) {
    await page.locator('button[data-bs-target="#form"]').click();
    await page.fill('#form input[name="name"]', 'TC Special');
    await page.selectOption('#form select[name="customer_type"]', 'retail');
    await page.setChecked('#form input[name="allow_credit_sale"]', true);
    await Promise.all([page.waitForURL(/\/customers/), page.getByRole('button', { name: 'Save Customer' }).click()]);
  }
  await openCustomerRates('TC Special');
  await expect(page.locator('#rates-customer')).toContainText('Price list: Retail');
  let changed = false;
  if ((await specialInput('gas_per_kg').inputValue()) !== '290.00') { await specialInput('gas_per_kg').fill('290'); changed = true; }
  if ((await specialInput('empty_cylinder', 'C11_8').inputValue()) !== '4000.00') { await specialInput('empty_cylinder', 'C11_8').fill('4000'); changed = true; }
  if (changed) {
    await saveSpecial();
    await expect(flash()).toContainText('rate change(s) saved');
  }
  await expect(specialInput('gas_per_kg')).toHaveValue('290.00');
  await expect(specialInput('empty_cylinder', 'C11_8')).toHaveValue('4000.00');
  await expect(page.locator('#customerRateHistory')).toContainText('290.00');
  await expect(page.locator('#rates-customer .rate-customer-chips')).toContainText('TC Special');
});

test('CR-02 POS uses the special rate for that customer', async () => {
  await openPos('gas_sale', 'TC Special');
  await expect(page.locator('#specialRateBadge')).toBeVisible();
  const row = await fillGasLine({ qty: 2, pay: 580 });
  await expect(row.locator('.gasRate')).toHaveValue('290.00');
  const d = await expectPosted(await post(), { total: 580, priceList: 'retail', source: 'customer_special' });
  expect(String(d.items[0].custom_rate_flag)).toBe('0');
});

test('CR-03 Special per-KG rate prices whole cylinders too', async () => {
  await openPos('gas_sale', 'TC Special');
  await fillGasLine({ mode: 'cylinders', qty: 1 });
  await expect(page.locator('#lines tbody tr .gasRate').first()).toHaveValue('3422.00'); // 290 x 11.8
  const typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), 'C11_8');
  const book = await posRates('TC Special');
  expect(book.books.retail[typeId].cylinder_gas_per_kg).toMatchObject({ rate: 290, source: 'customer_special' });
});

test('CR-04 Special empty cylinder price on Cylinder Sale', async () => {
  const row = await openPos('cylinder_sale', 'TC Special');
  await row.locator('.cyl').selectOption(await optionValue(row.locator('.cyl'), 'C11_8'));
  await row.locator('.cylStatus').selectOption('empty');
  await row.locator('.qty').fill('1');
  await expect(row.locator('.cylRate')).toHaveValue('4000.00');
  await page.locator('.payment .amount').first().fill('4000');
  await expectPosted(await post(), { total: 4000, priceList: 'retail', source: 'customer_special' });
});

test('CR-05 Walk-in and other customers keep their price list', async () => {
  await openPos('gas_sale');
  await expect(page.locator('#specialRateBadge')).toBeHidden();
  const row = await fillGasLine({ qty: 1 });
  await expect(row.locator('.gasRate')).toHaveValue('300.00');
  const typeId = await optionValue(page.locator('#lines tbody tr .cyl').first(), 'C11_8');
  const book = await posRates('TC Retail');
  expect(book.books.retail[typeId].gas_per_kg).toMatchObject({ rate: 300, source: 'retail' });
});

test('CR-06 Special rates off in Shop Settings', async () => {
  await setPricing({ special: false });
  try {
    await openCustomerRates('TC Special');
    await expect(page.locator('#rates-customer')).toContainText('Customer special rates are turned off');
    await openPos('gas_sale', 'TC Special');
    const row = await fillGasLine({ qty: 1 });
    await expect(row.locator('.gasRate')).toHaveValue('300.00');
    await expect(page.locator('#specialRateBadge')).toBeHidden();
  } finally {
    await setPricing(DEFAULTS);
  }
});

test('CR-07 Stopping a special rate brings back the price list', async () => {
  await openCustomerRates('TC Special');
  // Self-contained: give TC Special its special Gas per KG again when an earlier run stopped it.
  if ((await specialInput('gas_per_kg').inputValue()) !== '290.00') {
    await specialInput('gas_per_kg').fill('290');
    await saveSpecial();
    await expect(specialInput('gas_per_kg')).toHaveValue('290.00');
  }
  const stop = page.locator('#rates-customer .rate-kg').getByRole('button', { name: 'Stop' });
  await Promise.all([page.waitForURL(/\/rates/), stop.click()]);
  await expect(flash()).toContainText('Price stopped.');
  await expect(specialInput('gas_per_kg')).toHaveValue('');
  await expect(page.locator('#customerRateHistory')).toContainText('Stopped');
  const typeId = await page.locator('#rates-customer tbody tr').filter({ hasText: /^\s*C11_8 / }).locator('input[name$="[empty_cylinder]"]').getAttribute('name').then((n) => n.match(/\[types\]\[(\d+)\]/)[1]);
  const book = await posRates('TC Special');
  expect(book.books.retail[typeId].gas_per_kg).toMatchObject({ rate: 300, source: 'retail' });
  expect(book.books.retail[typeId].empty_cylinder).toMatchObject({ rate: 4000, source: 'customer_special' });
});

test('CR-08 Customers screen links to Special Rates', async () => {
  await page.goto('customers');
  const row = page.locator('table.datatable tbody tr', { hasText: 'TC Special' });
  await Promise.all([page.waitForURL(/rates\?customer=/), row.getByRole('link', { name: 'Special Rates' }).click()]);
  await expect(page.locator('#rates-customer')).toBeVisible();
  await expect(page.locator('#rateCustomer option:checked')).toContainText('TC Special');
});

test('UI-10 LPG Rates fits a phone screen', async () => {
  const original = page.viewportSize();
  await page.setViewportSize({ width: 390, height: 844 });
  try {
    await openRates('retail');
    const [scrollWidth, innerWidth] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    expect(scrollWidth).toBeLessThanOrEqual(innerWidth);
    // The grid scrolls inside its own box instead of widening the page.
    const grid = page.locator('#rates-retail .rate-grid-scroll');
    expect(await grid.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true);
    await expect(page.locator('#rates-retail')).toContainText('Scroll sideways to see all columns.');
  } finally {
    await page.setViewportSize(original);
  }
});

// ---------- D. POS screen ----------
test('UI-01 Walk-in rate is shown', async () => {
  await openPos('gas_sale');
  await fillGasLine({ qty: 2 });
  await expect(page.locator('#priceListBadge')).toHaveText('Retail');
  await expect(page.locator('#lines tbody tr .gasRate').first()).toHaveValue('300.00');
  await expect(page.locator('#saleTotal')).toHaveText('600.00');
});

test('UI-02 Changing the rate asks for a reason', async () => {
  await openPos('gas_sale');
  await fillGasLine({ qty: 2 });
  await page.locator('#lines tbody tr .gasRate').first().fill('280');
  await expect(page.locator('#rateReasonBox')).toBeVisible();
  await page.locator('.payment .amount').first().fill('560');
  await page.click('#saveBtn');
  await expect(page.locator('#posValidationAlert')).toContainText('Enter the reason for the rate change');
});

test('UI-03 Sale posts once a reason is given', async () => {
  await openPos('gas_sale');
  await fillGasLine({ qty: 2, rate: 280, pay: 560 });
  await expect(page.locator('#rateReasonBox')).toBeVisible();
  await page.fill('#rateChangeReason', 'Browser test');
  const d = await expectPosted(await post(), { total: 560, priceList: 'retail', source: 'manual' });
  expect(d.sale.rate_change_reason).toBe('Browser test');
});

test('UI-04 Wholesale customer sees Wholesale per-KG rate', async () => {
  await setPricing({ switch: true });
  await openPos('gas_sale', 'TC Wholesale');
  await fillGasLine({ qty: 1 });
  await expect(page.locator('#priceListBadge')).toHaveText('Wholesale');
  await expect(page.locator('#lines tbody tr .gasRate').first()).toHaveValue('279.66');
});

test('UI-05 Cylinders mode shows the per-cylinder price', async () => {
  await openPos('gas_sale', 'TC Wholesale');
  await fillGasLine({ mode: 'cylinders', qty: 1 });
  await expect(page.locator('#gasRateHeader')).toHaveText('Price / Cylinder');
  await expect(page.locator('#lines tbody tr .gasRate').first()).toHaveValue('3300.00');
  await expect(page.locator('#saleTotal')).toHaveText('3,300.00');
});

test('UI-06 Invoice price list switch re-prices the line', async () => {
  await setPricing({ switch: true });
  await openPos('gas_sale', 'TC Wholesale');
  await fillGasLine({ mode: 'cylinders', qty: 1 });
  await expect(page.locator('#lines tbody tr .gasRate').first()).toHaveValue('3300.00');
  await page.selectOption('#priceList', 'retail');
  // Retail has no full-cylinder price for C11_8, so it is Retail gas per KG x capacity.
  await expect(page.locator('#lines tbody tr .gasRate').first()).toHaveValue('3540.00');
  await expect(page.locator('#gasRateHeader')).toHaveText('Price / Cylinder');
  await expect(page.locator('#saleTotal')).toHaveText('3,540.00');
  await setPricing(DEFAULTS);
});

test('UI-07 No price list dropdown when the switch is off', async () => {
  await openPos('gas_sale');
  await expect(page.locator('#priceList')).toHaveCount(0);
  await expect(page.locator('#priceListBadge')).toBeVisible();
});

test('UI-08 Rate field is read-only when changes are not allowed', async () => {
  await setPricing({ rateMode: 'not_allowed' });
  const row = await openPos('gas_sale');
  await row.locator('.cyl').selectOption(await optionValue(row.locator('.cyl'), 'C11_8'));
  await expect(row.locator('.gasRate')).toHaveJSProperty('readOnly', true);
  await setPricing(DEFAULTS);
});

test('UI-09 No JavaScript errors on POS and Shop Settings', async () => {
  await openPos('gas_sale', 'TC Wholesale');
  await fillGasLine({ mode: 'cylinders', qty: 1 });
  await openPos('cylinder_sale');
  await openPos('security_deposit', 'TC Wholesale');
  await page.goto('shop-settings');
  await page.click('button[data-bs-target="#pricing"]');
  await page.waitForTimeout(500);
  expect(jsErrors).toEqual([]);
});
