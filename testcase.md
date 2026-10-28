# Pricing Test Cases — Retail / Wholesale Price Lists and Customer Special Rates

## Purpose
Regression checklist for pricing on branch `feature/pricing-rate-resolver`:
- LPG Rates screen (price grids, Customer Rates tab, Stop / Cancel, history, layout);
- Shop Settings → Pricing;
- customer Price List and customer special rates;
- POS pricing for Gas Sale, Cylinder Sale, Security Deposit and Cylinder Return.

Every case is automated in [tests/e2e/pricing.spec.mjs](tests/e2e/pricing.spec.mjs) (Playwright). Each case drives the real screens so a person can watch the run. A few server-only guards that the POS screen prevents are checked with direct requests.

## Progress — resume point for the next session

**Last updated:** 2026-10-21  
**Code state:** uncommitted changes on `feature/pricing-rate-resolver` (on top of `75eb899`). Nothing is committed or pushed yet. Ask the owner before committing.  
**Environment:** local XAMPP, `app.baseURL` from `.env` (http://localhost:180/lpg-management-system/public/), database `perfect_lpg`, MariaDB 10.4, PHP 8.2. Migrations 034, 035 and 036 have been applied.  
**PHPUnit:** 93 tests. The only failures are 2 `ExampleDatabaseTest` errors, caused by the missing local sqlite3 driver (environment only). `RateResolverTest`: 10 of 10 pass.

| Section | Cases | Status on the current code |
|---|---|---|
| S. Setup | S-01 … S-05 | PASS |
| R. LPG Rates screen | RT-01 … RT-08 | PASS |
| A. Shop Settings → Pricing | PS-01 … PS-04 | PASS |
| B. Customers | CU-01, CU-02 | PASS |
| C. POS pricing | POS-P01 … POS-P20 | PASS |
| E. Customer special rates | CR-01 … CR-08 | PASS |
| D. POS screen and layout | UI-01 … UI-10 | PASS |

**57 of 57 cases passed** on 2026-10-21, using the parallel wave runner (headed Chrome, up to 4 windows).
- Waves 1–3 ran first. UI-09 failed there on debug-toolbar noise (see the notes), was fixed and passed.
- Waves 4–7 then all passed. CR-07 was also made self-contained, and waves 6–7 passed again in the new single-session mode.

**Next step:** nothing pending for the current scope. After any pricing change, run `npm run test:pricing:waves` and update this table.

**Open work:**
- Review findings 7–8 are not fixed yet:
  - a price can change between quoting and posting when the rate itself changes;
  - an open POS keeps stale shop settings (wholesale on/off, switch) until reload.
- No automated case yet for a changed return gas rate on Cylinder Return (server rule done 2026-10-21).

**Database note (2026-10-21, later):** the owner reinstalled `perfect_lpg` with First-Time Database Installation, so all earlier test data is gone.
- The install first failed at migration 032 (see the migrations README, rule 6). That is fixed: every migration now shows success or skipped, and the install is recorded as complete. S-01 passes again.
- The next full run recreates the test data with S-03 to S-05 and CR-01.
- Since 2026-10-21 the seed has only C11_8 and C45 (owner's decision). The cases that used C15 now use C45. Statuses above are from runs before this change. Rerun RT-05, RT-06, RT-08, POS-P11 and S-03 on a database installed with the new seed.

## How to run

Prerequisites, once per machine:
- the app running in XAMPP;
- `npm install` in the repo root (installs `@playwright/test` 1.56.1);
- Google Chrome installed (the config uses `channel: 'chrome'` locally).

| Goal | Command (repo root) |
|---|---|
| **All cases, parallel waves, visible (the owner's preferred way)** | `npm run test:pricing:waves` |
| Resume from wave N after a fix | `node tests/e2e/run-cases.mjs --from N` |
| One wave only | `node tests/e2e/run-cases.mjs --only N` |
| Ad-hoc waves | `node tests/e2e/run-cases.mjs "RT-05,RT-06" "RT-08"` |
| One case, visible | `LPG_SLOWMO=500 npx playwright test tests/e2e/pricing.spec.mjs -g "\bUI-01\b" --headed --reporter=line` |
| All cases in one process, visible | `npm run test:pricing:watch` |
| Playwright UI mode / last HTML report | `npm run test:pricing:ui` / `npm run test:pricing:report` |

The wave runner ([tests/e2e/run-cases.mjs](tests/e2e/run-cases.mjs)) runs the plan in [tests/e2e/pricing.plan.json](tests/e2e/pricing.plan.json):
- **Cases in a wave run in parallel**, each in its own Chrome window, up to `--workers` (default 4) at a time.
- **A `serial` wave** runs its cases in order in one browser session. It is used for the cases that change Shop Settings.
- **Waves run in order.** The run stops after a wave with a failure and prints its output.
- Roughly 18–20 minutes for all 57 cases on the owner's PC, against about 45 minutes one by one. This is an estimate from the 2026-10-21 wave times; the whole plan has not yet been timed in a single run.

| Wave | Cases | Why together |
|---|---|---|
| 1 | S-01, S-02, S-03, S-05 | Setup that does not overlap |
| 2 | S-04, CU-01, CU-02, PS-04 | Base rates, plus read-only screens |
| 3 | RT-01…RT-04, POS-P01…P05, P08, P11, P13, P15…P18, UI-01…03, UI-05, UI-07, UI-09, UI-10 | Default settings; no case changes a rate or setting another case reads |
| 4 | RT-05, RT-06, CR-01, POS-P14, POS-P19 | Rate changes on different items; the two cases that pick a specific physical cylinder (kept away from other sales) |
| 5 | RT-08, CR-02…CR-05, CR-08 | Checks that need wave 4's rates |
| 6 (serial) | RT-07, PS-01…PS-03, POS-P06, P07, P09, P10, P12, P20, UI-04, UI-06, UI-08, CR-06 | Each changes and restores Shop Settings, so they must not overlap |
| 7 | CR-07 | Stops TC Special's special rate; it runs last because CR-02…CR-05 need that rate |

When you add a case, put it in a wave where it cannot affect the other cases, following the plan notes.

Other run details:
- The base URL comes from `.env` `app.baseURL`, with a trailing `/`. Override it with `LPG_BASE_URL`. Tests use relative paths.
- `LPG_SLOWMO` slows each browser action (default 250 ms).
- Failure screenshots and traces go to `writable/playwright-results/<case>/`.
- The VS Code Playwright extension (ms-playwright.playwright) also lists and runs single cases.

The cases change data in the local database:
- they create TC customers (including TC Special), rates, special rates and opening stock when these are missing;
- they post real test sales;
- they change Shop Settings and restore the defaults afterwards.

Opening Inventory allows one entry per cylinder type per day, so S-03 tops up stock on earlier dates.

## Test data
S-03 to S-05 and CR-01 create this data when it is missing. All amounts are in Rs.

| Item | Value |
|---|---|
| Retail gas per KG | 300.00 |
| Retail empty cylinder price, C11_8 | 4,500.00 |
| Wholesale full cylinder, C11_8 (11.8 KG) | 3,300.00 → 279.66 per KG |
| C45 (45 KG) | No wholesale price (used for the Retail fallback case). Fresh installs seed only C11_8 and C45. |
| Opening stock | At least 45 KG of filled C11_8, filled C45, and 3 empty C11_8 per run |
| Customers | TC Shop Default (Shop default, credit), TC Wholesale (Wholesale, credit), TC Retail (Retail), TC Special (Retail, credit) |
| TC Special special rates | Gas per KG 290.00; C11_8 empty cylinder 4,000.00 |

## S. Setup

| ID | Test Case | Steps | Expected Result | Status | Notes |
|---|---|---|---|---|---|
| S-01 | Migrations and sign-in | Open login; click Execute Pending Migrations if shown; sign in as admin. | Migrations succeed; dashboard opens. | PASS | 036 ran with 6 statements. |
| S-02 | Cash session | Cash → open a session if none is open. | "Cash session: OPEN". | PASS | |
| S-03 | Opening stock | Inventory → Opening, on earlier dates when stock is low. | ≥ 45 KG C11_8, ≥ 2 KG C45; 3 empty C11_8 added. | PASS | |
| S-04 | Rates | LPG Rates grid: Test data rates, Effective From = now. | Grid shows 300.00, 4500.00 and 3300.00. | PASS | |
| S-05 | Customers | Customers → add the TC customers. | Each is saved with its Price List. | PASS | |

## R. LPG Rates screen

| ID | Test Case | Steps | Expected Result | Status | Notes |
|---|---|---|---|---|---|
| RT-01 | Prices in force | Open the Retail and Wholesale tabs. | Current prices with "Since …". | PASS | |
| RT-02 | Blank shows fallback | Retail C11_8 full cylinder blank. | Placeholder 3540.00 with "Not set, uses Gas per KG × capacity 3,540.00". Wholesale C11_8 empty shows "Not set, uses Retail 4,500.00". | PASS | |
| RT-03 | Zero rejected | Force 0 into Retail Gas per KG and save. | "Rates must be numbers greater than zero…"; unchanged. | PASS | |
| RT-04 | No change | Save without changes. | "No rate changes to save." | PASS | |
| RT-05 | Schedule, then cancel | Retail C45 empty 4300 in the future → History → Cancel. | Saved; "Next: 4,300.00"; then "Scheduled rate cancelled." | PASS | |
| RT-06 | New price, then Stop | Wholesale C45 full cylinder 12600; check POS rates; Stop. | Per KG 280; after Stop it is retail_fallback; History shows Stopped. | PASS | |
| RT-07 | Wholesale off warning | Wholesale list off; open the Wholesale tab. | Warning shown. | PASS | |
| RT-08 | No past start | Effective From 2025-01-01 (browser `min` removed). | "Effective From cannot be in the past…"; nothing scheduled. | PASS | |

## A. Shop Settings → Pricing

| ID | Test Case | Steps | Expected Result | Status | Notes |
|---|---|---|---|---|---|
| PS-01 | Defaults | Open the Pricing tab. | Wholesale ON, Default Retail, Walk-in Retail, Special rates ON, Rate change = Allowed with reason, Switch OFF. | PASS | |
| PS-02 | Save settings | Default Wholesale, Rate change Allowed, Switch ON; save. | Kept after reload. | PASS | |
| PS-04 | Dependent fields lock | Untick Wholesale (no save). | Dependent fields disabled, showing Retail / OFF. | PASS | |
| PS-03 | Wholesale off saves Retail | Set Wholesale values, untick Wholesale, save. | Saved as Retail / Retail / OFF. | PASS | |

## B. Customers

| ID | Test Case | Steps | Expected Result | Status | Notes |
|---|---|---|---|---|---|
| CU-01 | Price List column | Open Customers. | Price List column shows the values. | PASS | |
| CU-02 | New customer default | Add Customer. | Price List defaults to Shop default. | PASS | |

## C. POS pricing

| ID | Test Case | Steps | Expected Result | Status | Notes |
|---|---|---|---|---|---|
| POS-P01 | Walk-in retail | Walk-in, 2 KG C11_8, pay 600. | 600.00; Retail; source retail. | PASS | |
| POS-P02 | Changed rate needs reason | 2 KG at 280, no reason. | Blocked: "Enter the reason for the rate change". | PASS | |
| POS-P03 | Changed rate with reason | Same with a reason. | 560.00; reason saved; custom; source manual. | PASS | |
| POS-P04 | Wholesale per cylinder | TC Wholesale, Cylinders mode, 1 × C11_8. | "Price / Cylinder"; 3,300.00. | PASS | |
| POS-P05 | Wholesale by KG | TC Wholesale, 2 KG. | Rate 279.66; total 559.32. | PASS | |
| POS-P06 | Shop default follows Default Rate | Default Wholesale; TC Shop Default, 1 KG. | 279.66; Wholesale. | PASS | |
| POS-P07 | Retail customer ignores Default Rate | Default Wholesale; TC Retail, 1 KG. | 300.00; Retail. | PASS | |
| POS-P08 | Switch OFF blocks list change | Direct post asking for Wholesale. | "Changing the price list on the invoice is not allowed…" | PASS | |
| POS-P09 | Switch ON allows change | Walk-in switched to Wholesale. | 279.66; Wholesale. | PASS | |
| POS-P10 | Rate change not allowed | Direct post at 250 with a reason. | "Changing the rate at POS is not allowed…" | PASS | |
| POS-P11 | Retail fallback | TC Wholesale, 1 KG C45. | 300.00; retail_fallback. | PASS | |
| POS-P12 | Wholesale OFF | Wholesale off; TC Wholesale, 1 KG. | 300.00; Retail. | PASS | |
| POS-P13 | Empty cylinder price | Cylinder Sale, empty C11_8 × 1. | 4,500.00. | PASS | |
| POS-P14 | Security Deposit charges only gas | TC Wholesale, one filled C11_8, deposit 0. | Gas KG × 279.66; cylinder_price 0. | PASS | |
| POS-P15 | Rates by date | POS time 2025-01-01. | Gas Rate blank. | PASS | |
| POS-P16 | Invalid date | Direct post "not-a-date". | "Invalid transaction date/time." | PASS | |
| POS-P17 | Future time rejected | Direct post with tomorrow's time. | "Transaction date/time cannot be in the future." | PASS | |
| POS-P18 | Backdated prices need a reason | Direct post dated 2025-01-01 without reason; then set the POS time to 2025-01-01. | "The transaction time is in the past and its prices differ…"; reason box shown. | PASS | |
| POS-P19 | Deposit of an empty cylinder | TC Shop Default, one empty C11_8. | Total 0.00. | PASS | |
| POS-P20 | Gas First: no deposit on credit | Gas First; credit of gas + 1,000 with deposit 1,000. | "Security Deposit cannot be received on credit." | PASS | |

## E. Customer special rates

| ID | Test Case | Steps | Expected Result | Status | Notes |
|---|---|---|---|---|---|
| CR-01 | Save special rates | LPG Rates → Customer Rates → TC Special: Gas per KG 290, C11_8 empty 4000; save. | Saved; grid shows the values; history and quick link show TC Special; "Price list: Retail". | PASS | |
| CR-02 | POS uses the special rate | Gas Sale, TC Special, 2 KG, pay 580. | "Special rates" badge; rate 290.00; total 580; source customer_special; not a custom rate. | PASS | Found and fixed a time-precision bug (see the notes). |
| CR-03 | Special per KG prices whole cylinders | Cylinders mode, 1 × C11_8. | 3422.00 (290 × 11.8); POS book cylinder_gas_per_kg 290 customer_special. | PASS | |
| CR-04 | Special empty price | Cylinder Sale, TC Special, empty C11_8 × 1. | Cylinder Rate 4000.00; total 4,000; source customer_special. | PASS | |
| CR-05 | Others unaffected | Walk-in and TC Retail. | Rate 300.00, source retail; no badge. | PASS | |
| CR-06 | Special rates off | Shop Settings special rates OFF. | Customer Rates tab warns; TC Special pays 300.00; no badge. | PASS | |
| CR-07 | Stop a special rate | Stop TC Special's Gas per KG. | "Price stopped."; history shows Stopped; POS per KG 300 retail; special empty price still 4000. | PASS | Self-contained: sets the rate first if missing. |
| CR-08 | Link from Customers | Customers → TC Special → Special Rates. | Opens LPG Rates → Customer Rates with TC Special selected. | PASS | |

## D. POS screen and layout

| ID | Test Case | Steps | Expected Result | Status | Notes |
|---|---|---|---|---|---|
| UI-01 | Walk-in rate shown | Gas Sale, walk-in, 2 KG. | Badge Retail; 300.00; 600.00. | PASS | |
| UI-02 | Reason prompt | Change the rate to 280 and Post. | Reason box; post blocked. | PASS | |
| UI-03 | Post with reason | Rate 280, reason "Browser test". | Posts 560; reason saved. | PASS | |
| UI-04 | Wholesale customer | Switch ON; TC Wholesale. | Badge Wholesale; 279.66. | PASS | |
| UI-05 | Wholesale per cylinder | Cylinders mode, 1. | "Price / Cylinder"; 3300.00. | PASS | |
| UI-06 | Invoice switch | Switch the list to Retail. | 3540.00; "Price / Cylinder"; 3,540.00. | PASS | |
| UI-07 | Dropdown hidden | Switch OFF. | No dropdown; badge visible. | PASS | |
| UI-08 | Read-only rates | Rate change Not allowed. | Gas Rate read-only. | PASS | |
| UI-09 | No script or server errors | POS (gas, cylinder, deposit) and Shop Settings. | No JavaScript errors and no 5xx responses from the app. | PASS | Ignores the CI debug toolbar's own `?debugbar_time` requests (see the notes). |
| UI-10 | LPG Rates on a phone | 390 px wide. | No sideways page scroll; the grid scrolls inside its box; "Scroll sideways" hint shown. | PASS | New. |

## F. Custody with customer special rates (scenario)

Spec: [tests/e2e/custody-special-rates.spec.mjs](tests/e2e/custody-special-rates.spec.mjs). The cases share state, so run the whole file in order:
`LPG_SLOWMO=400 npx playwright test tests/e2e/custody-special-rates.spec.mjs --headed --reporter=list`

It uses its own customers, **SR Customer A** and **SR Customer B** (both on the Retail price list), and only their special rates, so the shop's price-list rates stay untouched. It works with the shop's live data: it needs at least 2 filled C11 cylinders and an open cash session.

| ID | Test Case | Expected Result | Status (2026-10-22) | Actual |
|---|---|---|---|---|
| CS-01 | Define two new customers | Both are saved | PASS | IDs 7 and 8 |
| CS-02 | Different special rate for the same type (C11, 11.8 KG) | A: 11,000 per full cylinder; B: 10,500. POS book source customer_special | PASS | A 932.2034 per KG, B 889.8305 per KG |
| CS-03 | Stock before | Snapshot taken; no custody for A or B | PASS | 6 filled C11 (70.8 KG), 0 empty |
| CS-04 (A) | Issue one filled C11 to A, deposit 2,000 | Gas only at A's special rate; cylinder price 0 | PASS | S20261022-00001: 11.8 KG × 932.2034 = 11,000.00 |
| CS-04 (B) | Issue one filled C11 to B, deposit 2,000 | Gas only at B's special rate | PASS | S20261022-00002: 11.8 KG × 889.8305 = 10,500.00 |
| CS-05 | Stock after issuing | Filled −2, gas −23.6 KG, both units on custody, deposits 2,000 each | PASS | 4 filled (47.2 KG), custody C11-000001 (A), C11-000002 (B) |
| CS-06 (A) | Return A's cylinder, refund 2,000 | Return posted; refund 2,000 | PASS | S20261022-00003 |
| CS-06 (B) | Return B's cylinder, refund 2,000 | Return posted; refund 2,000 | PASS | S20261022-00004 |
| CS-07 | Stock after the returns | Returned empty: filled unchanged, empty +2, no custody, deposits 0 | PASS | 4 filled (47.2 KG), 2 empty, deposits 0 |

The database check after the run matched the screen:
- both units are `empty` and not on custody;
- inventory movements: 2 filled cylinders and 23.6 KG of gas out (issue), 2 empty cylinders in (return);
- the deposit ledger for both customers is back to 0.
## Notes from the 2026-10-21 runs
- **Time-precision bug, found by CR-02 and fixed:**
  - the POS time field is filled in to the minute when the screen opens, while a new rate starts at the exact second it is saved;
  - so the POS showed the special rate (290), but the server priced the sale at the minute-earlier time and charged 300;
  - fix: a POS time up to 10 minutes old counts as "now" (`RateResolver::pricingTime`), so the price shown is the price charged.
- **Debug-toolbar noise in parallel runs:**
  - in development mode, CodeIgniter's debug toolbar deletes old history files on every request;
  - parallel requests can race on the same file, and the toolbar's own `?debugbar_time` request then returns 500;
  - this is framework-only (production has no toolbar), and the app pages were fine;
  - UI-09 now ignores toolbar requests but still fails on any real 5xx from the app.

## Not covered yet
- Cylinder Return with a changed return gas rate (rate-change rule): server done, no automated case.

## Run history
- **2026-10-21, first run:** 36 of 36 TRUE (earlier design, HTTP and headless).
- **2026-10-21, headed one by one:** S-01 … POS-P20 PASS; UI not run.
- **2026-10-21, final code, parallel waves:** 57 of 57 PASS.
