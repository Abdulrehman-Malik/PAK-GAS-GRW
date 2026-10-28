# Perfect LPG — Engineering Analysis Report

Date: 2026-10-15 · Branch: `main` · Scope: static review of the repository (code, schema, migrations, CI, docs).
Not done: the app was not run, PHPUnit/Playwright were not executed, and views/JS were only sampled. Findings are from reading the code; items marked *(verify)* need a runtime check.

---

## 1. Executive summary

Perfect LPG is a CodeIgniter 4 / PHP 8.2 / MySQL 8 point-of-sale and inventory system for an LPG (cooking-gas) distributor: POS (gas sale, cylinder sale, security deposit, cylinder return), per-cylinder inventory, customer credit/OS, purchases, counter cash, expenses, reports, RBAC, audit log, backup/restore.

**Overall verdict: solid domain model and database design, with good transactional discipline in the core POS path — but the surrounding code has real security gaps, a permission-sync bug, almost no automated tests on money logic, and significant maintainability debt.** It is fit for staging/SQA; it needs the High items below fixed before a production go-live.

| Area | Rating | One-line reason |
|---|---|---|
| Domain / data model | Strong | Immutable movements, RESTRICT FKs, CHECK constraints, per-unit cylinder tracking |
| Financial integrity (POS) | Good | DB transactions + `FOR UPDATE` locks; weakened by float arithmetic |
| Security | Needs work | Public migration endpoint, over-broad restore, permission re-grant bug, no login throttling |
| Code quality | Weak–Fair | Minified one-line controllers, 1,486-line service, legacy path still present |
| Testing | Weak | 1 framework-stub unit test; no tests on posting/void/credit logic |
| Operations / DevOps | Fair | CI exists; vendor + system committed; partial migration coverage in CI |
| Documentation | Good | ARCHITECTURE, TESTING, SQA, workflow docs — but duplicated/conflicting files |

---

## 2. System overview

- **Stack:** CodeIgniter 4 (PHP ^8.2), MySQLi, server-rendered PHP views, file sessions.
- **Size:** ~12.2k lines of app PHP (non-view), ~5.6k lines of views. Largest: `SalesService` (1,486), `InventoryOpening` (745), `DatabaseBackupService` (617), `MigrationRunnerService` (458).
- **Layers:** Controllers (25) → Services (12) → Models (18) / raw `Database::connect()` queries.
- **Schema:** `database/schema.sql` (one-shot, ~30 tables) + 31 SQL migrations applied by an in-app `MigrationRunnerService` that gates login until migrations are complete.
- **Tenancy:** single DB, `location_id` on every transactional table, taken from the session.
- **Routing:** one `auth` filter group; everything else guarded per-controller by `PermissionService::allows(...)`.

### Core domain rules (as implemented)
- Gas stock is derived from `cylinder_units.gas_weight_kg` (company-owned, `status='filled'`) — never stored separately. ✔ matches CLAUDE.md.
- Sales preserve applied rate per line (`applied_rate`, `gas_rate`, `cylinder_price`, `standard_rate`, `custom_rate_flag`). ✔
- Posted records are voided, not deleted (sales/purchases). Deposits and returns are non-voidable by design. ✔
- Walk-in = cash only; credit limits validated with none/customer/shop modes. ✔ (checked in service code)

---

## 3. Strengths

1. **Database design is the best part of the project.** `ON DELETE RESTRICT` on financial FKs, `CHECK(amount>0)`, unique business keys per location (`uq_sale_no`), composite indexes for stock lookups, ENUM-typed statuses, JSON old/new values in `audit_logs`.
2. **Concurrency handled deliberately.** `SELECT … FOR UPDATE` on customers, cylinder units and sales rows; deterministic lock ordering (`ORDER BY cu.id`); a shop-level credit-limit lock; explicit rollback in every posting path.
3. **Atomic posting.** Sales, deposits, returns, receipts, expenses, supplier payments all run in `transBegin/Commit/Rollback`.
4. **Authorisation present on every controller action** (permission guard), CSRF enabled globally, `password_hash`/`password_verify`, session regeneration on login, `httponly` + `SameSite=Lax` cookies.
5. **Defensive admin tooling.** The transactional-data refresh requires ADMIN, an exact confirmation phrase, and a verified safety backup first.
6. **Operational guard rails.** Migration gate blocks login on pending migrations; audit log; backup with email/Drive delivery.
7. **Documentation culture** — architecture, workflows, test cases and SQA gates exist and are mostly current.

---

## 4. Findings

Severity: **H** = fix before production · **M** = fix soon · **L** = improve.

### 4.1 Security

| # | Sev | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| S1 | **H** | **Unauthenticated migration endpoint.** `POST /migrations/run` is registered outside the `auth` group and the controller has no check. Anyone who can reach the site (and obtain a CSRF token from the login page) can execute pending DDL. | `app/Config/Routes.php` line 5; `app/Controllers/Migrations.php` | Require ADMIN session, or make it CLI-only (`php spark`). If it must stay public for first install, disable it once the schema is current. |
| S2 | **H** | **Permission re-grant bug.** `PermissionService::all()` calls `syncStandardRolePermissions()` which `INSERT IGNORE`s the default permissions for ADMIN/MANAGER/CASHIER **on every request**. Removing a permission from a standard role in *Users → Role permissions* is silently undone on the next request. | `app/Services/PermissionService.php` (sync + `roleDefaults`) | Run the sync once, as a migration (009 already exists for this). Remove runtime sync. Also removes ~20+ queries per request. |
| S3 | **H** | **Restore is too broadly granted and executes arbitrary SQL.** `BACKUP_MANAGE` is in the MANAGER default set. Restore runs the full uploaded `.sql` through the native client or statement-by-statement; any file with the `-- PERFECT_LPG_NATIVE_BACKUP_V1` header runs arbitrary SQL (e.g. insert an admin user, drop tables). | `DatabaseBackup::restore`, `DatabaseBackupService::restoreNativeBackup` | Restore → ADMIN only + re-enter password + confirmation phrase + automatic pre-restore backup. Consider signing/whitelisting statement types. |
| S4 | **M** | **No brute-force protection** on login (no throttle, lockout, or captcha) and no password policy (any non-empty string). | `Auth::attemptLogin`, `Users::save` | Add per-IP/per-user throttling (CI4 Throttler), min length, lockout + audit entry. |
| S5 | **M** | **Deactivated users keep working.** Permission lookup joins `users` by id but never checks `is_active`; the session isn't re-validated. | `PermissionService::all`, `AuthFilter` | Check `is_active` (cached per request) in `AuthFilter`; destroy session if disabled. |
| S6 | **M** | **User admin can mint ADMINs / edit ADMIN role.** `Users::save` accepts any `role_id`; `rolePermissions` can edit any role. No audit entry for user/role changes. | `app/Controllers/Users.php` | Only ADMIN may assign/modify ADMIN; write `AuditService::log` for user and permission changes; prevent self-lockout. |
| S7 | **M** | **Cookie/transport hardening off.** `Cookie::$secure=false`, `forceGlobalSecureRequests=false`, `secureheaders` filter commented out, `CI_ENVIRONMENT=development` in `.env`. Fine for dev; mandatory change for prod. | `Config/Cookie.php`, `Config/App.php`, `Config/Filters.php` | Production checklist: HTTPS, `secure` cookies, enable `secureheaders`, `CI_ENVIRONMENT=production`, toolbar off. (Keep env-specific values in `.env`, per the project rule.) |
| S8 | **M** | **SMTP password stored in plaintext** in `shop_settings.smtp_password` (TEXT) and included in DB backups. | `database/schema.sql` shop_settings | Encrypt with CI4 `Encryption` service, or move to `.env`. |
| S9 | **L** | Login page receives raw exception text (`system_error`) when the migration check fails. | `Auth::showLogin` | Log it; show a generic message. |
| S10 | **L** | Backup routes sit outside the `auth` group, so they skip the migration gate and rely solely on the permission check. Works, but inconsistent. | `Routes.php` | Move into the `auth` group. |

### 4.2 Financial correctness & data integrity

| # | Sev | Finding | Recommendation |
|---|---|---|---|
| F1 | **H** | **Money computed with PHP floats.** `SalesService` has ~83 `(float)` casts and only ~9 `round()` calls; totals compared with a `0.01` tolerance (`abs($paymentTotal-$total)>0.01`). Columns are `DECIMAL(14,2/4)`, so rounding drift between line sums, header totals and ledgers is possible. | Centralise rounding (`round($x,2)` at line and header level, or integer paisa / `bcmath`). Add unit tests with awkward values (0.1+0.2, 33.333 kg × 217.5). |
| F2 | **M** | **Customer balance is calculated in 4+ places** with hand-written SQL: `Receipts::index`, `Receipts::save`, `Reports::ledger`, `CustomerModel::withLedgerTotals`, `SalesService::customerBalance`. Any rule change (e.g. `include_security_deposit_in_os`) must be made everywhere. | One `CustomerLedgerService::balance()`; everything else calls it. |
| F3 | **M** | **No reversal path for receipts, expenses, supplier payments.** `customer_receipts`/`supplier_payments` have a `status='voided'` column but no void route/service; expenses have no status at all. Contradicts "posted transactions are reversed". | Add void/reverse flows with cash-session impact and audit. |
| F4 | **M** | **Document numbers.** Sale numbers use `MAX(seq)+1`: concurrent POSTs can collide (unique key then aborts one sale). Receipts/expenses/payments use `date('YmdHis').'-'.random_int(100,999)`: two in the same second have a ~1/900 collision chance. | Use a counter table (`doc_sequences`, row-locked) per location/date/type. |
| F5 | **M** | **Legacy five-mode path still live.** `SalesService::post()` still contains the old `sell_gas_only / replace_same / sell_filled / …` logic and is entered when `transaction_type` is absent. CLAUDE.md says not to revert to that model; schema ENUMs keep legacy values. | Confirm `Sales::save` always sets `transaction_type`; delete or hard-reject the legacy branch once historical data is migrated. *(verify)* |
| F6 | **L** | `TransactionalDataRefreshService` uses `TRUNCATE` (implicit commit, non-atomic). Controller variable `$refreshAttempted` is never set to `true`, so the "database may be partially refreshed" warning can never display. | Set the flag before calling `refresh()`; document that restore-from-safety-backup is the rollback. |
| F7 | **L** | `Reports` totals for deposits don't filter voided/ownership states and several report queries have no pagination (audit capped at 200 rows, history unpaginated). | Add status filters and paging. *(verify)* |

### 4.3 Code quality & maintainability

| # | Sev | Finding | Recommendation |
|---|---|---|---|
| C1 | **H** | **Business logic in controllers, written as minified one-liners.** `Expenses`, `Receipts`, `SupplierPayments`, `Cash`, `Reports`, `Inventory::adjust` have posting/aggregation logic on single lines of 1–3k characters. This directly violates the project rule "controllers should not contain financial posting logic" and makes review/diff/debugging very hard. `Routes.php` is similarly packed. | Extract `ReceiptService`, `ExpenseService`, `SupplierPaymentService`, `ReportService`; format with the repo's `php-cs-fixer` (already a dev dependency). |
| C2 | **M** | **`SalesService` is a 1,486-line god class** (4 posting flows + void + validation + locking + legacy flow). Methods of 100–200 lines. | Split per transaction type (`GasSalePoster`, `CylinderSalePoster`, `DepositPoster`, `ReturnPoster`) behind one façade. |
| C3 | **M** | **Mixed data access** — Models, query builder and raw SQL used interchangeably; business rules also duplicated in views/JS (`sales/index.php` is 1,133 lines). | Define a convention: services own queries; views only render. |
| C4 | **M** | **Per-request overhead:** `AuthFilter` runs `MigrationRunnerService::status()` on every authenticated request; `PermissionService` re-syncs (S2). | Cache migration status (APCu/file with schema-version hash) and drop the runtime sync. |
| C5 | **L** | Dead/legacy: `Home` controller, `welcome_message.php`, `Reports::stock` redirect, five-mode ENUMs, unused `Migrations/*` README. | Prune. |

### 4.4 Testing & QA

| # | Sev | Finding | Recommendation |
|---|---|---|---|
| T1 | **H** | **No automated tests for the part that matters.** `tests/unit/HealthTest.php` is the CodeIgniter stub; "5 tests / 6 assertions" in CI are framework health checks. No tests for posting, void, credit limits, stock allocation, deposits, or permission checks. | Add DB-backed PHPUnit tests (CI already boots MySQL 8): gas allocation across cylinders, credit-limit modes, payment allocation order, void symmetry, deposit/return cycle, walk-in rules. Target the invariants in CLAUDE.md. |
| T2 | **M** | **CI doesn't prove the migration chain.** It loads `schema.sql` and then only migrations 007, 008, 010, 011, 025 by hand; 012–024 and 026–031 are never executed in CI. Whether `schema.sql` already contains everything is not verified. | Run `MigrationRunnerService` (or all files in order) in CI against a fresh DB and diff the resulting schema against `schema.sql`. |
| T3 | **M** | E2E exists (`tests/e2e/lpg-runtime.spec.mjs`, ~14 tests, `runtime-qa.yml`) but the workflow has `contents: write` and pushes report updates on every `main` push. | Scope permissions down; run on PR; don't auto-commit to `main`. |
| T4 | **L** | `TESTING.md` (manual gate) and CLAUDE.md "Phase 1" text are out of date relative to the October baseline. | Refresh. |

### 4.5 Repository & operations

| # | Sev | Finding | Recommendation |
|---|---|---|---|
| R1 | **M** | **`vendor/` (3,056 files) and `system/` (653 files) are committed**, while CI also runs `composer install`. The working tree currently shows hundreds of deleted `vendor/codeigniter4/framework/*` files — 805 pending changes, which makes real changes impossible to review. | Remove both from Git, add to `.gitignore`, rely on `composer.lock`. Commit only intentional changes. |
| R2 | **M** | **Uncommitted feature work** (`Landing` controller, `landing/` and `partials/` views, `public/css/`, edits to login/dashboard/layout/routes) sits beside vendor noise. Risk of loss and of mixing it into a bad commit. | Commit on a feature branch, separate from vendor cleanup. |
| R3 | **L** | Duplicated/conflicting instruction files: `CLAUDE.md` and `claude.md` (case collision on Windows/macOS), `agent.md`, `env` and `.env`. | Keep one of each; make `env` an explicit `.env.example`. |
| R4 | **L** | Default `database.default.username = root` in local `.env`. | Dedicated least-privilege DB user for staging/production. |
| R5 | **L** | Backups are written under `writable/` on the app host. | Off-host copy is partially covered by email/Drive upload; add retention and a restore drill. |

---

## 5. Prioritised action plan

**Week 1 – must-fix (security + trust)**
1. Lock `POST /migrations/run` behind ADMIN/CLI (S1).
2. Remove runtime permission sync; ship it as a migration (S2).
3. Restore → ADMIN-only with password + confirmation + auto pre-restore backup (S3).
4. Check `is_active` per request (S5).
5. Clean repo: untrack `vendor/` and `system/`; commit landing-page work separately (R1, R2).

**Weeks 2–3 – financial hardening**
6. Introduce one rounding policy (or integer minor units) in `SalesService` and all receipt/expense code (F1).
7. Single customer-ledger service (F2); document-number sequence table (F4).
8. Write the PHPUnit suite for posting/void/credit/deposit invariants (T1) and run the whole migration chain in CI (T2).

**Month 2 – structure**
9. Move controller logic into services and format code (C1, C2); delete the legacy sale path (F5).
10. Receipt/expense/supplier-payment reversal flows (F3).
11. Login throttling, password policy, audit of user/role changes, encrypt SMTP password (S4, S6, S8).
12. Production hardening checklist: HTTPS-only cookies, secure headers, `production` environment (S7).

---

## 6. Open questions for the team

1. Is the legacy five-mode sale path still needed for any historical import or API caller? (F5)
2. Should MANAGER be able to restore backups, or ADMIN only? (S3)
3. Will the system stay single-location, or are multi-branch users (null `location_id`) expected? Several queries use `location_id=0` for such users.
4. Is `schema.sql` intended to equal "schema + all migrations", and who owns keeping them in sync? (T2)
