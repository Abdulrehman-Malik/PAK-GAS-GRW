# Perfect LPG POS & ERP — Requirements

## Purpose
Production-ready POS + ERP for LPG retail operations.

## Stack
- PHP 8.x / CodeIgniter 4
- MySQL 8.0+
- Bootstrap 5.3
- HTML5/CSS3
- JavaScript/jQuery/AJAX
- DataTables
- InnoDB transactions and foreign keys

## Modules
1. Authentication and roles
2. Item/cylinder/rate setup
3. Customer and supplier directory
4. LPG POS
5. Purchases
6. Inventory
7. Customer/supplier ledgers
8. Counter Cash
9. Expenses
10. Reports
11. Audit logging

## Sales Use Cases

The POS provides exactly five business transaction types:

1. **Sell Gas Only** — customer brings their own cylinder. Gas stock is reduced by the entered KG. The user selects the filled-cylinder type used as the gas source. If the sale fully consumes a physical filled cylinder, that exact physical cylinder is moved to empty stock; if only part is consumed, the cylinder remains filled with its remaining actual gas weight.
2. **Sell Gas by Replacing Same-Capacity Cylinder** — customer returns an empty cylinder and receives one or more filled cylinders of the same type. Gas is charged by actual gas weight; filled-cylinder stock decreases and returned empty-cylinder stock increases.
3. **Sell Filled Cylinder with Gas + Cylinder Price** — customer buys a filled cylinder. Gas is charged by actual gas weight and the cylinder price is charged separately. Gas stock and filled-cylinder stock decrease.
4. **Sell Filled Cylinder with Gas + Replace Different-Capacity Cylinder** — same as type 3, except the returned empty cylinder may be a different cylinder type/capacity. Gas stock, sold filled-cylinder stock and the received empty-cylinder stock are updated.
5. **Sell Empty Cylinder Only** — only an empty physical cylinder is sold. Gas stock is not affected; empty-cylinder stock decreases.

The branch administrator sets the default POS transaction type in **Shop Settings**. The POS uses that branch default for the first new sale line.

## Master Data Seed (fresh install)
- A fresh install (`database/schema.sql`) seeds only two cylinder types: **C11_8 — 11.8 kg** and **C45 — 45 kg**. Other sizes are added by the shop under cylinder type setup.
- The Opening Inventory Excel template's example rows use the same two types.

## Shop / Branch Configuration

Shop Settings is branch-level and provides tabs for:
- General branch identity/contact details.
- POS default transaction type and default payment mode.
- Sale stock-validation control and stock-override permission.
- Cash/payment defaults.
- Receipt title, footer and address display.
- Database backup URL/endpoint and maintenance notes.
- Pricing: default price list, wholesale, walk-in list, customer special rates and POS rate-change rules (see **Rate Configuration**).

Cylinder-type inventory policy overrides remain available under Inventory Controls.

## Critical Rules
- Rate history stores effective rate, timestamp, old/new values and user.
- Every sale line stores the actual applied rate.
- Custom-rate lines are explicitly flagged.
- Walk-in customers are cash-only. Named customers are required when a cylinder is returned in transaction types 2 or 4; walk-in sales may still use type 1, 3 or 5.
- Credit limit is checked before posting a credit sale.
- Sales screen displays Previous OS + Current Credit = New OS.
- Payment modes: cash, cheque, online, credit.
- Counter Cash includes actual cash only.
- Posted transactions are reversed/voided rather than hard-deleted.
- Financial amounts use DECIMAL.
- Inventory and cash posting is atomic inside a DB transaction.

## Reports
- **What counts as sales** (Dashboard Sales card, Reports sales total, sales list and payment-mode totals):
  - included: Gas Sale, Cylinder Sale, and the gas charged on a Security Deposit / Issue Cylinder;
  - not included: the security deposit amount (a refundable hold), deposit and refund payments, Cylinder Return, and issues with nothing charged;
  - payment-mode totals count only sale payments.
- Customer ledger
- Supplier ledger
- Daily transactions
- Custom-rate sales
- Daily stock
- Counter Cash reconciliation
- Daily expenses
- Outstanding balances
- Payment-mode analysis

## SQA-Confirmed Inventory Control & Wastage Requirements
- Stock validation must be configurable at shop/location level, with optional cylinder-type overrides.
- When stock validation is ON, gas sales must not exceed available gas stock.
- When stock validation is OFF, a gas sale that exceeds available stock requires an explicit user confirmation and the override must be traceable.
- The system must provide a separate gas-wastage report with date-range and cylinder-type filtering. Wastage reduces the actual gas weight on the affected physical filled cylinder.
- A filled physical cylinder can be converted to empty stock through an authorized inventory wastage/leakage adjustment. The gas lost is deducted from gas inventory and recorded with physical cylinder, user, date and reason.
- Wastage policy must support percentage or fixed-KG configuration, at shop level with cylinder-type override capability.
- Residual gas/wastage from a cylinder that is made empty must never exceed the cylinder's current actual gas weight.
- Inventory control and wastage actions must be atomic and auditable.

## Deferred Business Decisions

The following items are intentionally outside the currently implemented functional scope and must be confirmed before adding them:
- GST/tax
- Sale returns/refunds
- Offline POS
- 80mm thermal printer integration
- Multiple cash registers / handover workflows beyond the current foundation
- Cylinder serial-number policy
- Manager override workflows for credit limits
- Pricing rules beyond Retail / Wholesale price lists and customer special rates (for example quantity tiers or time-limited promotions)

## Open Business Decisions
1. Can one sale contain multiple scenarios?
2. Are cylinder serial numbers required?
3. Can managers override credit limits?
4. Are cheque payments excluded from Counter Cash?
5. Single shop or multi-location?
6. Is GST/tax required?
7. Are discounts allowed?
8. Are sale returns/refunds required?
9. 80mm thermal receipt printer?
10. Offline POS required?
11. Are additional taxes/fees required on gas-only or cylinder sales?
12. Are multiple cash registers required?

## Rate Configuration (Retail / Wholesale Price Lists)

### Price lists
- Each shop has two price lists: **Retail** and **Wholesale**. Rates belong to one shop (location) and one price list.
- Every POS transaction type (Gas Sale, Cylinder Sale, Security Deposit, Cylinder Return) is priced from the invoice's price list.
- Each invoice uses exactly one price list. The sale stores it in `sales.price_list`.

### Rate items
Every price list can hold three kinds of rate:

| Rate | Applies to | Used for |
|---|---|---|
| Gas per KG | All cylinder types | Gas sold by KG or amount; gas in Cylinder Sale and Security Deposit lines when the cylinder type has no full-cylinder price |
| Gas per full cylinder | One cylinder type | Gas Sale lines entered by cylinder count; gas in Cylinder Sale and Security Deposit lines (full-cylinder price ÷ capacity × actual gas weight, so a part-filled cylinder is pro-rated) |
| Empty cylinder price | One cylinder type | **Cylinder Sale only**: an empty cylinder, or the cylinder part of a filled one |

### Pricing by transaction type
| Transaction type | Charged |
|---|---|
| Gas Sale / Refill | Gas only: per KG (KG or amount entry) or per full cylinder (cylinder-count entry) |
| Cylinder Sale: empty | Empty cylinder price. Empty-cylinder stock decreases. |
| Cylinder Sale: filled / part-filled | Gas (actual weight) **plus** the empty cylinder price. Filled-cylinder stock decreases. |
| Security Deposit / Issue Cylinder | **Gas only**, for a filled or part-filled cylinder; an empty cylinder costs nothing. **No empty cylinder price**, because the cylinder stays company property on custody. |
| Cylinder Return / Refund Deposit | Returned gas is valued at the rate it was issued at. |

- The **security deposit** is a refundable amount taken from a customer the business does not fully trust when a cylinder is issued. It is not a charge or revenue, and it can be refunded later through Cylinder Return / Refund Deposit.
- A security deposit must be money actually received: it can never be taken on credit, whatever the payment allocation rule (Gas First, Deposit First or Manual).

### Rate lookup and fallback
- A named customer's **special rate** for an item is used first (see **Customer special rates**). Without one, the customer's price list applies.
- When a list has no Gas per full cylinder rate, POS uses Gas per KG × the cylinder capacity. When it has no Gas per KG rate, POS uses that cylinder's full-cylinder price ÷ capacity.
- When Wholesale has no price for an item, POS uses the Retail price.
- When neither list has an empty cylinder price, POS uses the Cylinder Type's empty cylinder price.
- If no price can be found, POS asks the cashier to enter one, or blocks the sale if the cashier cannot change rates.
- The rate in force is the latest rate whose Effective From is at or before the transaction time and that has not been stopped.
- Every sale line stores the applied rate and where it came from (`sale_items.rate_source`: customer_special, retail, wholesale, retail_fallback, cylinder_type, issued_rate or manual).

### Transaction time and rate changes
- POS fills in the transaction time, to the minute, when the screen opens. A time up to 10 minutes old counts as **now**: the sale is stored and priced at the posting time, so a price saved a moment earlier already applies, and the price shown is the price charged.
- A transaction time more than 5 minutes in the future is rejected.
- A backdated transaction (more than 10 minutes in the past) whose price-list prices differ from today's prices is treated as a rate change:
  - it is blocked when **Cashier can change rate at POS** is Not allowed;
  - it needs a reason when the setting is Allowed with reason;
  - the sale is flagged as custom-rate.

  This stops backdating from silently picking an old price.
- The rate-change rule applies to all four transaction types, including a changed return gas rate on Cylinder Return.

### Choosing the invoice price list
1. If **Cashier can switch price list on invoice** is ON, the cashier can pick Retail or Wholesale on the invoice.
2. Otherwise a walk-in sale uses the **Walk-in price list**.
3. Otherwise a named customer uses their own price list: Retail, Wholesale, or Shop default (which follows the shop's **Default Rate**).
4. If the **Wholesale price list** is OFF, every invoice uses Retail, whatever the customer or invoice setting.
- POS shows the active price list on the invoice. Prices refresh when the customer, price list or transaction time changes.

### Shop-level pricing settings (Shop Settings → Pricing)

| Setting | Values | Default |
|---|---|---|
| Default Rate | Retail / Wholesale | Retail |
| Wholesale price list | On / Off | On |
| Walk-in price list | Retail / Wholesale | Retail |
| Customer special rates | On / Off | On |
| Cashier can change rate at POS | Not allowed / Allowed / Allowed with reason | Allowed with reason |
| Cashier can switch price list on invoice | On / Off | Off |

- When Wholesale is OFF, the settings that depend on it are locked to Retail.
- A rate changed at POS is flagged as a custom rate (`rate_source = manual`). The server enforces the rate-change mode, and stores the reason in `sales.rate_change_reason` when one is required.
- POS sends a rate only when the cashier changed it. A blank or unchanged rate always uses the price list.

### Customer price list
- Every customer has a **Price List** field: Shop default, Retail or Wholesale. Shop default is the default for new customers.
- Walk-in customers do not have this field; they use the Walk-in price list.

### LPG Rates screen
- The screen has four tabs: **Retail**, **Wholesale**, **Customer Rates** and **History**.
- The Retail and Wholesale tabs show one price grid: Gas per KG at the top, then one row per active cylinder type with Gas per full cylinder and Empty cylinder price.
- Each price field shows the rate in force and when it started. A blank field shows, in grey, the price POS will use and where it comes from (for example "Not set, uses Gas per KG × capacity" or "Not set, uses Retail").
- If a future price is scheduled, the field shows "Next: price from date".
- The user changes any number of prices, enters **Effective From** (now or a future time) and an optional reason, then saves. The Save button shows how many prices changed. Each changed price becomes a new dated rate, and unchanged prices are not saved again.
- Validation:
  - Effective From must be a valid date and time, and cannot be in the past. A time up to one hour old (the form's default when the page was opened) is treated as now.
  - Rates must be numbers greater than zero.
  - An item cannot have two rates starting at the exact same time.
- Rates are never edited or hard-deleted once they have started:
  - **Stop** ends the current price now. It stays in history, and POS falls back as described above.
  - **Cancel** removes a scheduled rate that has not started, so no sale ever used it.
- History lists every rate with its price list, item, cylinder type, value, start and end times, creator and status (Current, Scheduled, Stopped, Old).
- The Wholesale tab warns when the Wholesale price list is turned off in Shop Settings. Those prices are kept but not used at POS.
- Every save, stop and cancel runs inside one database transaction and writes a rate change log entry (old rate, new rate, user, reason) and an audit log entry.
- Posted sales keep the rate they were sold at. Rate changes never alter posted sales.
- Access requires the `RATE_MANAGE` permission.

- Layout:
  - price inputs are compact, and grey suggested prices are shown in italics so they cannot be mistaken for saved prices;
  - each price grid scrolls inside its own box with a sticky header (sideways on a phone, with a "Scroll sideways" hint), so the page never scrolls sideways;
  - the History lists scroll inside their own box with a sticky header;
  - the Effective From / Reason / Save bar stays visible at the bottom of the grid;
  - the tabs scroll sideways on a phone.

### Customer special rates
- Each customer can have their own price for any of the three rate items: Gas per KG (all cylinder types), Gas per full cylinder (one cylinder type) and Empty cylinder price (one cylinder type).
- Special rates belong to one shop (`customer_rates.location_id`), and the same dating rules apply as for price-list rates:
  - Effective From is now or later;
  - a price is never edited: Stop ends it and Cancel removes a scheduled one;
  - each change has a history, a rate change log entry and an audit entry.
- Where to set them: LPG Rates → **Customer Rates**. Pick a customer, change any prices and save. The screen shows the customer's price list and, in grey, what the customer pays without a special rate.
- Customers with special rates are listed as quick links. The Customers screen has a **Special Rates** button on each row.
- POS lookup order for a named customer:
  1. the customer's special rate;
  2. their price list (Retail or Wholesale);
  3. Retail when Wholesale has no price;
  4. the Cylinder Type price, for empty cylinders.
- Items without a special rate follow the price list.
- Inside special rates, per KG and per full cylinder convert into each other like a price list. For example, a special Gas per KG of 290 makes a full 11.8 KG cylinder 3,422, unless a special full-cylinder price is set for that type.
- A special rate applies automatically in Gas Sale, Cylinder Sale and Security Deposit. It is not a cashier rate change, so no reason is asked for. Sale lines record `rate_source = customer_special`.
- POS shows a **Special rates** badge next to the price list, and reloads prices when the customer changes.
- Walk-in sales never use special rates.
- **Customer special rates** in Shop Settings → Pricing turns the feature on or off. When it is off, special rates are kept but ignored at POS, and the Customer Rates tab shows a warning.

## Completion Rule
A requirement is marked Done only after implementation, validation, transaction testing, UI testing, relevant reporting and tracker update.

## Master Data UI Quality Requirements
- Edit actions for master-data records must open the correct edit form and preload the selected record's current values.
- Saving an edit must persist the changed values and retain unchanged values.
- Editing a user must retain the existing password when the password field is left blank.
- Automated or manual regression testing should verify create/edit flows after UI changes to prevent modal or form JavaScript regressions.


## SQA-Confirmed POS / Inventory Requirements
- Filled-cylinder POS selection must show the currently available filled-cylinder quantity for the selected cylinder type and a clear gas-stock figure without misleading the user about whether gas is allocated to individual cylinders.
- The current inventory model does NOT maintain an independent gas KG stock bucket. Available gas is derived from the actual gas weight of company-owned filled physical cylinders. Each filled cylinder unit stores its actual gas weight and status, allowing partial fills and actual-weight deductions.
- The system must support partially filled cylinders: a cylinder type defines maximum capacity, while each filled cylinder may contain an actual gas weight from 0 up to that capacity. Inventory must prevent actual gas weight above cylinder capacity and account for actual weight when selling, refilling and exchanging cylinders.
- POS transaction types must use user-friendly names and display a short operational hint explaining what the selected transaction will do.
- The active Counter Cash session status should be prominent at the top-left of the POS screen.
- Counter Cash must provide a searchable/filterable history view by date range and counter/register, with transaction type, direction, amount, reference and notes visible.
- Counter Cash expected cash must not double-count the opening float: when an opening_float cash transaction exists, expected cash is calculated from cash-in minus cash-out for the session.


## SQA-Confirmed POS Display Requirements
- The POS transaction hint/help box is not required and should not occupy space on the Sales page.
- POS font size must be configurable from Shop Settings at branch level.
- POS font size must apply only to the POS/Sales page; other application pages must retain their normal font sizing.
- POS font size must be validated to a safe configurable range and persist across POS page reloads.


## Global Application Appearance Requirements
- Shop Settings must provide a configurable application theme: Light or Dark.
- Shop Settings must provide a configurable global application font size.
- Shop Settings must provide a configurable global font style from the supported font list.
- Shop Settings must provide configurable primary and accent colors.
- Appearance settings are branch-level and apply consistently across the whole authenticated application through the shared layout.
- Appearance settings must not be POS-only; all application pages use the same configured appearance.
- Appearance values must be validated against supported themes, fonts, safe font-size bounds and six-digit hexadecimal colors.
