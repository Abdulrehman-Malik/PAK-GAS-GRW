# Perfect LPG Project Tracker

This file is a concise continuation point for development.

## Current State
- Target architecture is documented in ARCHITECTURE.md.
- The complete one-shot MySQL schema is database/schema.sql.
- The old schema_v2.sql is no longer the authoritative schema.
- TESTING.md is the mandatory user/developer test gate.
- Phase 1 foundation changes are pushed and ready for local testing.

## Phase 1 Ready for User Testing
1. Execute database/schema.sql on a fresh MySQL server.
2. Configure CodeIgniter .env for perfect_lpg.
3. Test login with admin / admin123.
4. Test dashboard.
5. Record PASS/FAIL and exact errors in TESTING.md.
6. Push the updated TESTING.md.

## Important Design Rules
- Transaction tables are source of truth.
- Inventory is represented by opening balances plus immutable inventory movements.
- Counter cash is represented by cash sessions plus cash transactions.
- Posted transactions are reversed/voided; they are not hard-deleted.
- Sales must preserve the actual applied rate on every line.
- Financial posting must be atomic inside a database transaction.
- Walk-in customers are cash-only and cannot create credit or cylinder-return balances.
- Credit sales must enforce the customer's credit limit.
- Controllers should not contain financial posting logic; use application services.

## Next Development Gate
After Phase 1 PASS, implement Phase 2 master data. If Phase 1 has FAIL entries, fix those first and update TESTING.md before continuing.


---

## October 2026 Current Implementation Baseline

This section supersedes older POS assumptions in this file. Do not revert the newer transaction architecture to the historical five-mode model unless explicitly requested.

### Repository / branch
- Primary branch: `main`.
- Known-good historical baseline: `b91321ebc38ee2daac71310653153041682f7745`.
- Current automated CI is defined in `.github/workflows/ci.yml`.

### Current POS transaction architecture
POS now uses one transaction type per invoice:
- `gas_sale` — Gas Sale / Refill.
- `cylinder_sale` — Cylinder Sale.
- `security_deposit` — Security Deposit / Issue Cylinder.
- `cylinder_return` — Cylinder Return / Refund Deposit.

The branch default transaction type is stored in `shop_settings.default_transaction_type` and is selected by Shop Settings. The old `default_sale_mode` field remains for backward compatibility and should not be treated as the current POS architecture.

### Gas sale / physical-cylinder behavior
- Normal gas sale quantity is KG, not whole-cylinder quantity.
- Selling 1 KG consumes only 1 KG of gas.
- When individual cylinder tracking is OFF, POS shows aggregate available gas by cylinder type instead of a physical source-cylinder dropdown.
- Backend automatically allocates gas across available filled physical cylinders of that type.
- Physical cylinder records are still maintained in the backend.
- If a physical source cylinder reaches 0 KG, it becomes empty and the corresponding inventory movements are recorded.
- Gas quantity cannot exceed available gas for the applicable transaction unless an explicit stock-override configuration permits it.
- A single POS gas-sale line can therefore consume gas from more than one physical cylinder internally while remaining one sales line.

### Individual cylinder tracking setting
- Shop Settings contains `individual_cylinder_tracking`.
- Default is OFF.
- OFF: aggregate stock by cylinder type is shown on normal POS gas sales and backend allocation is automatic.
- ON: normal POS can expose physical source-cylinder selection.
- Customer custody cylinder selection remains physical-unit based because custody/return/refill workflows require identifying the cylinder held by the customer.

### Security deposit / custody
- Company cylinders can move to customer custody when a security deposit is issued.
- Security deposit is a liability/hold, not sales revenue. The gas charged on an issue IS sales revenue: Dashboard and Reports use `SalesService::revenueSaleCondition()` (Gas Sale, Cylinder Sale, Security Deposit with total_amount > 0; never Cylinder Return), and payment-mode totals count only `payment_type = 'sale'`.
- Cylinder return requires the customer's custody cylinder and refunds the applicable deposit.
- Repeated custody cycles are supported.
- Security deposit and cylinder-return transactions are not treated like ordinary voidable revenue sales.

### Customer OS / payments / credit
- Previous customer OS is carried into the current receivable.
- Payments are applied to previous OS first, then the current sale.
- Remaining unpaid amount becomes customer OS.
- Credit limits support branch validation modes: none, customer, or shop.
- Credit sales require the applicable customer/branch credit validation.

### Inventory
- Opening inventory supports filled cylinders and empty cylinders; gas-only opening records remain historically supported.
- Gas stock is never stored independently. Available Gas KG is always derived from `cylinder_units.gas_weight_kg` for company-owned cylinders with `status='filled'`.
- Inventory has a separate Stock Adjustment & History screen for physical-cylinder and cylinder-count adjustments. Gas adjustments must target a specific physical cylinder and change that unit's actual gas weight; bulk independent Gas KG adjustments are not allowed.
- Filled-cylinder IN requires actual gas weight and cannot exceed cylinder capacity. Purchasing gas must therefore be represented by receiving filled physical cylinders with actual gas weights.
- Inventory Detail Report shows current gas stock, filled/empty cylinder counts, stock by cylinder type, and partially used physical cylinders.

### Pricing (Retail / Wholesale price lists)
Requirements: `requirements.md` → Rate Configuration. Test status and resume point: `testcase.md` → Progress.
- All POS prices come from `App\Services\RateResolver`, scoped to the current shop (`rate_cards.location_id`) and the transaction time. Do not read `rate_cards` for pricing anywhere else.
- Rates belong to a price list (`rate_cards.price_list` = retail / wholesale; migration 035). There are three rate types:
  - `gas_per_kg` (all cylinders);
  - `gas_per_cylinder` (per cylinder type);
  - `empty_cylinder` (per cylinder type).
- Price list per invoice: invoice switch (if `pos_price_list_switch`) → walk-in uses `walkin_price_list` → customer `customer_type` (`retail` / `wholesale` / `shop_default` → `default_price_list`). `wholesale_price_list_enabled = 0` forces Retail everywhere.
- Customer special rates (`customer_rates`, migration 036, `CustomerRateModel`) come first for a named customer: `RateResolver::forCustomer($customer)`. Walk-in never gets them, and nothing applies when `customer_special_rates_enabled = 0`. They are set in LPG Rates → Customer Rates (`Rates::saveCustomer` / `stopCustomer` / `cancelCustomer`). POS reloads `sales/rates?customer_id=` on a customer change, and lines record `rate_source = customer_special`.
- Lookup and fallback:
  - per KG and per full cylinder convert into each other within a list (× / ÷ capacity);
  - Wholesale falls back to Retail;
  - an empty cylinder falls back to `cylinder_types.empty_cylinder_price`.
- Which price each transaction type uses:
  - **Gas Sale:** KG or amount mode uses `gasPerKg`. Cylinders mode uses `gasPerCylinder`, and the entered rate is per cylinder.
  - **Cylinder Sale:** empty lines charge `emptyCylinder`. Filled lines charge `cylinderGasPerKg` × actual gas (full-cylinder price ÷ capacity, else per KG) **plus** `emptyCylinder`.
  - **Security Deposit:** charges **only the gas** of filled / part-filled cylinders (`cylinderGasPerKg` × actual gas). There is **no cylinder price**, because the cylinder stays company property; empty cylinders cost 0. The deposit is a refundable hold, not a charge (owner rule, 2026-10-21).
  - **Cylinder Return:** returned gas is valued at the custody `issued_gas_rate`. A different rate is a POS rate change.
- POS sends a rate only when the cashier changed it; blank means "use the price list". `pos_rate_change_mode` (`not_allowed` / `allowed` / `reason_required`) is enforced server-side for all four transaction types. The reason is stored in `sales.rate_change_reason`.
- Transaction time:
  - up to 10 minutes old counts as now (`RateResolver::pricingTime`), because the POS fills the time in when the screen opens;
  - more than 5 minutes in the future is rejected;
  - more than 10 minutes in the past, when its prices differ from today's, counts as a rate change: blocked when `not_allowed`, needs a reason when `reason_required`, and sets `custom_rate_flag`.
- Sales store `price_list`. Sale lines store `rate_source` (`customer_special`, `retail`, `wholesale`, `retail_fallback`, `cylinder_type`, `manual`, `issued_rate`).
- LPG Rates screen:
  - Retail / Wholesale grids, a Customer Rates tab and a History tab, with the grids and histories scrolling inside their own boxes;
  - Effective From cannot be in the past (up to 1 hour old is treated as now);
  - rates are never edited or deleted: **Stop** ends the current price, and **Cancel** removes a scheduled one that has not started;
  - every save, stop and cancel runs in one transaction and writes `rate_change_log` and an audit entry.
- Security Deposit combined payments: credit can pay gas only, never the deposit, under both `gas_first` and `deposit_first`.

### Browser tests (Playwright)
- Spec: `tests/e2e/pricing.spec.mjs` (57 cases). Case list, status and resume point: `testcase.md`. Config: `playwright.config.mjs`. Install with `npm install`.
- The owner wants headed runs that can be watched, **run in parallel where possible** to save time. Use the wave runner:
  `npm run test:pricing:waves`, which is `node tests/e2e/run-cases.mjs` with the plan in `tests/e2e/pricing.plan.json`.
  - Cases in a wave run in parallel, up to 4 Chrome windows.
  - Waves run in order, and the run stops after a wave with a failure. Resume with `--from N`.
  - Single-case waves change Shop Settings or rates that other cases read.
- When adding a case, put it in a wave where it cannot affect the other cases (see the plan notes).
- One case on its own: `LPG_SLOWMO=500 npx playwright test tests/e2e/pricing.spec.mjs -g "\bPOS-P01\b" --headed --reporter=line`.
- The base URL comes from `.env` `app.baseURL` (e.g. `http://localhost:180/lpg-management-system/public/`). Never hard-code `/login`; use relative paths.
- The tests write to the local database: TC customers, rates, opening stock, test sales and Shop Settings changes (restored afterwards).
- After every run, update the Status column and the Progress table in `testcase.md`, so the next session can continue from there.

### Permissions
Sidebar entries are permission-aware and hidden when the current user lacks the relevant permission.
Current mappings include:
- DASHBOARD_VIEW
- POS_SALE
- CASH_MANAGE
- CUSTOMER_MANAGE
- SUPPLIER_MANAGE
- INVENTORY_MANAGE
- RATE_MANAGE
- PURCHASE_MANAGE
- EXPENSE_MANAGE
- REPORT_VIEW
- USER_MANAGE
- AUDIT_VIEW

### Automated CI status
Latest verified CI result:
- Workflow: **LPG CI #20**
- Commit: `f19b68e730d49ba5b40be9ed98d4c0b9b407f7ae`
- PHP syntax validation: PASS
- Composer dependency installation: PASS
- MySQL 8 schema bootstrap: PASS
- Required migrations: PASS
- `php spark`: PASS
- PHPUnit: **5/5 tests, 6 assertions**
- PHPUnit coverage is intentionally disabled in CI with `--no-coverage` because the CI runner has no coverage driver configured.

Important: CI success is an automated code/database smoke result. It is NOT browser/SQA sign-off.

### Current real-world testing path
Browser/E2E testing still requires a reachable staging application.
Recommended setup:
`Windows PC/XAMPP -> Cloudflare Tunnel -> public HTTPS staging URL -> browser testing`.

Do not expose the production application/database. Use a dedicated test database and test credentials.


## Environment Configuration Rule — Important

- **Never add Cloudflare/tunnel-specific URL logic to application code.**
- The application must use the normal CodeIgniter `app.baseURL` configuration from the local `.env` file.
- For staging, set `app.baseURL` in the staging machine's local `.env` to the staging HTTPS URL.
- For production, set `app.baseURL` in the production machine's local `.env` to the production URL.
- Do not commit tunnel URLs, environment-specific hostnames, production URLs, or environment secrets to the repository.
- Testing infrastructure may use external URLs, but it must not require application-code changes to accommodate a temporary tunnel.


## Windows client installer
- `installer/` builds `PerfectLPG-Setup-<version>.exe` with `.\installer\build.ps1` (Inno Setup 6; Composer needs PHP on PATH). See `installer/README.md`.
- The exe carries only the app. On the client PC it downloads the latest PHP 8.5 / Apache 2.4 / MySQL 8.4 patch (`installer/scripts/resolve.ps1`), installs the `PerfectLPG-Apache` / `PerfectLPG-MySQL` services under `C:\PerfectLPG` and opens the web Installation page. Re-running it upgrades and keeps data.
- Client databases are upgraded only through migrations, so every schema change needs a new migration file.
- Installer scripts must run on Windows PowerShell 5.1: call external programs through `Invoke-Native` in `configure.ps1` (PS 5.1 turns redirected stderr into fatal errors under `ErrorActionPreference = 'Stop'`).

## Client installer
- `installer/` is self-contained and app-agnostic: everything app-specific is in `installer/app.ini` (it can be copied into another PHP app's root). See `installer/README.md`.
- Build: double-click `installer/build.bat` (asks version + notes, updates `app.ini [release]` and `installer/RELEASES.md`; commit both).
- Machine-locked licensing: `installer/license/keygen.bat` issues keys; the private signing key lives outside the repo in `%USERPROFILE%\.app-licenses\<short_name>\`. Runtime check is `installer/scripts/license-check.php`, hooked via php.ini `auto_prepend_file` and injected by the build into `[license] entry_points` (shipped copy only) — do not add license logic to app code.

## Git Workflow Rule
- Flow is feature -> stage -> main. Never push directly to `main` or `stage`.
- Branch from `stage` (`feature/`, `fix/`, `chore/`), push, open a PR into `stage`, merge after CI passes. Promote with a PR from `stage` into `main`.
- Run `git config core.hooksPath .githooks` once per clone to enable the pre-push guard.
