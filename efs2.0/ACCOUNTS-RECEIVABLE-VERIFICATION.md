# Accounts Receivable — verification and release limits

Prepared 13 November 2026 from the supplied `efs2.0.zip`.

## What was checked here

- JavaScript syntax checks passed for the changed application scripts.
- `node tests/accounts-receivable-ui.cjs`: **22 boundary checks passed**. These execute the actual new JavaScript with mocked DOM/API boundaries, including decimal calculations, pending item add/edit/remove, retained invoice headers, save failure preservation, request-key reuse, register refresh and out-of-order network responses.
- Reviewed API wiring, existing role/date rules, transaction boundaries, ledger attribution, payment reconciliation, historical rates, source locks, schema upgrade and backup/restore integration.
- Compared the application against the supplied ZIP: no original files were removed. Changes are listed below; the original archive was left untouched.

## Not verified here

**This package is not certified for live station use.** PHP and MySQL/MariaDB were unavailable in this execution environment. PHP lint, SQL migrations, database integration tests and existing PHP regression suites could not be run. Chromium could not launch because the environment denied a required socket operation, so browser layout, keyboard interaction and printing are not browser-verified. The mock UI checks do not prove these runtime behaviours.

Historical verification reports already included in the original project refer to earlier releases. They do not validate this update.

## Required test-copy checks

Use a separate database and application copy, as described in `ACCOUNTS-RECEIVABLE-INSTALL.md`. Do not test against your live station database.

With XAMPP's PHP CLI and MySQL available:

```bat
C:\xampp\php\php.exe -l app\AccountsReceivable.php
C:\xampp\php\php.exe -l app\Core.php
C:\xampp\php\php.exe -l app\Station.php
C:\xampp\php\php.exe -l app\PumpMeterSales.php
C:\xampp\php\php.exe -l app\Recovery.php
C:\xampp\php\php.exe -l api.php
C:\xampp\php\php.exe -l setup.php
C:\xampp\php\php.exe tests\accounts-receivable.php
C:\xampp\php\php.exe tests\pump-edit.php
C:\xampp\php\php.exe tests\purchase-orders.php
C:\xampp\php\php.exe tests\accounts-payable.php
C:\xampp\php\php.exe tests\mysql.php
```

The integration test uses the existing `tests/TestDb.php` helper, creates isolated `efs_check_*` databases and removes those databases on exit. It requires database creation privileges. Set `EFS_TEST_HOST`, `EFS_TEST_PORT`, `EFS_TEST_USER` and `EFS_TEST_PASSWORD` in your shell if your local test server is not the default root account at 127.0.0.1:3306. Never use production credentials.

Also run the remaining existing PHP regression scripts on the test server. With Node available, run `node tests/accounts-receivable-ui.cjs` for the mock boundary checks.

### Manual acceptance cases

| Check | Expected result |
| --- | --- |
| Open an existing schema 10 test database | Additive schema 11 upgrade; pre-upgrade SQL backup created; old records retained |
| Select a date and pump with 90,000 in saved meter sales | Read-only Pump Sales shows 90,000 |
| Enter Cash 60,000, Card Pay 20,000, Credit Sales 10,000 | Vertical Total Amount 90,000; Difference 0 underneath |
| Save allocation | Saved difference near Pump Sales updates; credit entry opens |
| Change Cash to 59,000 and save | Total 89,000; Difference 1,000; credit amount remains 10,000 |
| Enter two lines, pressing Enter after each | Earlier lines stay above form; customer/contact/invoice retained; product/litres reset |
| Save complete bill | One register entry with all lines; matching customer debt; no extra sale or stock deduction |
| Save a bill above remaining credit | Rejected; pending items retained; no partial ledger/line records |
| Retry same request after a lost response | No duplicate allocation, invoice or customer debit |
| Edit an unpaid invoice | Existing invoice and debit update; released credit capacity recalculated |
| Collect payment using existing customer collection screen | Debt reduced; paid invoice cannot be edited |
| Change product price after meter sale | Credit entry uses the sale's historical rate |
| Open same allocation in two browsers, save both | Second stale write rejected; first saved value preserved |
| Viewer / previous-date cashier | Writes rejected by server |
| Light and dark themes, narrow screen, Enter and Tab, print preview | Legible fields/tables, usable controls and complete invoice printing |
| Export and restore schema 11 backup to another test database | New allocation, invoice, line, payment-link and ledger records preserved |
| Restore a complete pre-update schema 10 backup through recovery | Original data restored with empty new receivables tables |

## Operating boundaries

- The source is saved **Pump Sales meter entries**, not manually typed totals or unrelated POS transactions.
- Finalize that pump's readings before saving an allocation. Once allocated, additional readings/corrections and legacy collections for that pump/date are locked to protect the saved split. Later dates remain available. No unlock/void workflow was added in this update.
- A pump/date with legacy collections or other payments must keep its original collection workflow; it cannot be silently converted.
- Cash excess is retained as a reconciliation difference; payments posted to original sales are capped at their sale value. Difference is a reconciliation figure, not a newly created shift shortage/debt or bank deposit.
- Credit Remaining is the saved credit allocation minus customer invoices. Complete customer attribution for all credit; an allocation alone does not assign debt to a customer.
- An invoice may include products from several pumps on the same date. Every involved pump must already have sufficient credit allocation. Limits: 100 lines per invoice, existing customer credit rules and original recorded litres/rates.
- Existing customer receipt, FIFO settlement, authentication, licensing and backup facilities are reused. Remote synchronization of these new event types was not exercised.
- Existing 64-bit PHP requirements are unchanged. No Supabase migration or 32-bit conversion is included.

## Changed application files

- `app/AccountsReceivable.php` — new allocation/invoice API and additive migration.
- `app/Core.php`, `app/Station.php`, `api.php`, `setup.php` — schema, trait and request integration.
- `app/PumpMeterSales.php` — source/collection protection after allocation.
- `app/Recovery.php` — schema 11 backup acceptance and older backup compatibility.
- `assets/accounts-receivable.js`, `assets/accounts-receivable.css` — new page and responsive theme styling.
- `assets/workspace.js`, `assets/app.js`, `app/templates/workspace.html` — navigation, draft/session integration and assets.
- `tests/accounts-receivable-ui.cjs`, `tests/accounts-receivable.php` — new test coverage (PHP suite supplied but not run here).
- This report, installation notes and refreshed `SHA256SUMS.txt`.
