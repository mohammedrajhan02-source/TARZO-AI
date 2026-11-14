# Accounts Payable verification — 2026-10-31

## Implemented contract

- Compact, responsive EFS page with vendor/date/current balance context; inline additional payable and vendor payment forms; embedded statement.
- Positive manual payables increase liabilities without creating stock, sales or a bank entry.
- Cash reduces an open shift drawer and vendor liability once. Overdrawn cash and non-today cash dates are rejected.
- Own cheque stores bank/account/branch/number/date/payee, rejects reused bank/cheque number, and reduces book funds on issue. Existing direct bank payment callers remain compatible.
- Existing third-party cheques can be registered in custody with source acknowledgement and endorsed once at their full amount. Registration does not collect customer debt; endorsement does not debit the station's bank or cash.
- All payment methods reject overpayment. Recorded date must be valid, not in the future and not before vendor creation; dated vendor balance is checked as well as current balance.
- Money stays in integer cents. Serialized database transactions and idempotency keys protect posting and retry behavior.
- Vendor statement reads the complete underlying ledger, including purchases, manual payables and payments, with opening carry-forward and period totals. Same-second payments use insertion order. Bank-specific statements and old vendor statement navigation remain available.
- Schema 6 upgrade is additive and restartable, takes a pre-upgrade SQL backup, and preserves old payments as bank-method records with their original timestamps.

## Fresh test evidence

Environment: PHP 8.3.6 CLI, MariaDB 10.11.14, local Chromium 153 through Playwright. Tests ran against temporary databases, not the user's installed station database.

| Check | Result |
|---|---:|
| `php tests/accounts-payable.php` | 45 passed |
| `php tests/run.php` | 37 passed |
| `php tests/workspace.php` | 44 passed |
| `php tests/workspace-upgrade.php` | 57 passed |
| `php tests/mysql.php` | 20 passed |
| Browser workflows against real PHP API and MariaDB | 21 passed |
| Every original uploaded SQL backup imported and upgraded independently | 18 passed |
| PHP syntax lint, including new trait and migration | Passed |
| JavaScript `node --check` | Passed |

The new AP suite checks exact-cent balances; idempotency; invalid amounts/dates; cash, bank and cheque effects; duplicate own/party cheques; rollback on insufficient bank funds; role restrictions; statement reconciliation; same-second payment order; real concurrent overpayment; concurrent identical retries; and restart after a partially applied upgrade.

Browser checks cover real sign-in, vendor selection, previews, cash/own/party/bank posting, autofilled cheque/account fields, errors, used-cheque removal, CSV totals, isolated print content, date-filter errors, party cheque registration with preservation of the other form's draft, day mode, mobile overflow and uncaught JavaScript errors. Visual inspection used only synthetic test data.

Uploaded-backup checks compare all original business-table row counts and every vendor balance before/after migration. No imported data is added to the deliverable. The original uploaded backup files and database configuration are byte-preserved.

## Changes

New: `app/AccountsPayable.php`, `assets/accounts-payable.js`, `assets/accounts-payable.css`, `tests/accounts-payable.php`, this verification note and the Tamil update guide.

Updated: `app/Core.php`, `app/Migrations.php`, `app/Workspace.php`, `app/Station.php`, `api.php`, `app/templates/workspace.html`, `assets/workspace.js`, `setup.php`. Existing workspace test expectations now target schema 6 and three chained migration backups.

No package dependency, service, runtime binary, generated screenshot, temporary database, or test-generated SQL backup is included. Existing project assets are retained.

## Limits

Windows Apache/XAMPP, PHP 7.4 runtime and MySQL 8 were not executed here. Network loss across a complete browser restart is not an offline payment queue; review the saved statement before re-entering an uncertain payment. Automatic cheque clearance/bounce reversal and customer-receipt cheque ingestion are separate workflows, not part of this update. No claim of an exhaustive audit of unrelated EFS modules is made.

To run backend suites on a development machine: set `EFS_TEST_HOST`, `EFS_TEST_PORT`, `EFS_TEST_USER`, `EFS_TEST_PASSWORD` as needed, then run the commands above with PHP CLI and PDO MySQL enabled. Defaults are localhost TCP port 3306, root, no password. The suite creates and drops only randomly named `efs_check_*` databases; it needs permission to do so.
