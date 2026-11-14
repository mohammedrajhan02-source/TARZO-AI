# EFS 2.0E — fixes and verification

All 35 findings in the supplied QA summary have a corresponding code or packaging correction. This records the tested scope; it is not a guarantee that no undiscovered defects remain.

## Reported findings addressed

| # | Reported problem | Correction |
|---|---|---|
| 1 | Saved `efs_restored` connection blocks fresh setup | Release excludes saved connections and runtime state; setup form is tested from an unconfigured copy. |
| 2 | Current application backups rejected | Recovery validates the current schema 9 export format. |
| 3 | Missing migration records break restored station | Require the complete supported migration chain before any data replacement. |
| 4 | Nonzero opening meter cannot save | Convert readings consistently in integer millilitres. |
| 5 | Multi-entry failure persists the first sale | One backend batch endpoint, one transaction, one idempotency record. |
| 6 | Padded password cannot sign in | Password validation preserves the exact input, including spaces. |
| 7 | Correcting invalid setup password leaves unusable database | Validate credentials before database initialization; setup accepts an owner-free schema 9 database. |
| 8 | Long password accepts a different ending | Explicit 72-byte bcrypt limit at creation/reset/login. |
| 9 | 61-character Tamil station name rejected | UTF-8 character-based field validation. |
| 10 | Viewer lacks promised employee record | Create/link staff records for every user role without granting write access. |
| 11 | Two-character usernames cannot sign in | Apply the same 3–64-character username policy at account creation. |
| 12 | Duplicate username error mentions invoices | Return a username-specific conflict message. |
| 13 | Valid closing meter preview asks for a greater reading | Compare opening and closing in the same unit. |
| 14 | Preview inflates meter difference | Remove litres/millilitres mixing. |
| 15 | Preview adds one cent | Use exact integer half-up rounding, including large products. |
| 16 | Draft summary remains zero | Update summary on field input/change. |
| 17 | Editing staged sale clears closing reading | Preserve readings and original entry order. |
| 18 | Next entry ignores staged close | Include staged entries when calculating subsequent openings. |
| 19 | Blank auto-added entry blocks save | Ignore a next card with no pump or closing reading, even with inherited employee/shift. |
| 20 | Successful save leaves controls disabled | Reset saving state before rendering the refreshed form. |
| 21 | Previous-days report stuck loading | Wire initial loading, errors and pagination. |
| 22 | Collections final page falsely shows no debt | Clamp stale offsets and use the returned page offset after settlement. |
| 23 | Excess fuel precision silently rounded for billing | Reject more than three decimals before adding a bill item. |
| 24 | Maximum unpaid invoices zero silently becomes one | Reject out-of-range input with a clear message and matching UI limits. |
| 25 | Tamil category name rejected | Character-based required-field validation. |
| 26 | Non-Latin employee name rejected | Character-based required-field validation. |
| 27 | Non-Latin address causes database error | Validate character limits without splitting UTF-8 byte sequences. |
| 28 | New pump rejects selected product description | Derive missing hidden description from the selected product. |
| 29 | Enter in Purchasing Notes adds a product | Preserve textarea Enter/newline behaviour. |
| 30 | Consumed-purchase void rejection invisible | Display error within the open dialog and restore its button. |
| 31 | Negative Celsius rejected | Allow negative temperature in the form; avoid duplicate conflicting `min` attributes. |
| 32 | Accented expense category rejected | Character-based required-field validation. |
| 33 | Accented expense note rejected | Character-based required-field validation. |
| 34 | Invalid CSV dates navigate to raw JSON | Validate and download via fetch; keep errors in Reports. Server checks actual calendar dates. |
| 35 | Today totals include tomorrow | Use a half-open station-local day interval for sales, fuel and expenses. |

## Additional corrections

- Schema 9 group-column migration can resume after an interrupted DDL operation; it now creates a private pre-upgrade backup.
- A missing station mutex record fails closed instead of allowing unlocked transactions.
- UTF-8-safe validation also covers purchase notes, purchase-edit notes, void reasons, employee designation, bank references and company settings.
- Malformed JSON receives a clear HTTP 400 response; invalid numeric types receive validation errors.
- Meter batch request keys survive network response loss, preventing duplicate stock deductions.
- Unsaved meter drafts participate in logout/reload protection and clear on logout/session expiry; a date rollover preserves them for review.
- Asset version strings and visible release branding were updated to avoid stale JavaScript caches.
- Existing tests had stale schema 6 assertions and an older shift-drawer cash policy. These expectations now reflect the supplied application's schema 9 and explicitly documented independent vendor cash behaviour. Business-balance, transaction, role and concurrency assertions remain.

## Executed verification

Test environment: Linux, PHP 8.3.6, MariaDB 10.11.14, Chrome for Testing 131.0.6778.204, Playwright. Database tests use isolated `efs_check_*` databases. Browser tests run against a clean copy served by PHP's local development server; they do not touch a production installation.

| Suite | Passed checks |
|---|---:|
| Core sales, stock, shifts, banking, backup and licensing (`tests/run.php`) | 45 |
| MySQL integrity and concurrent stock race (`tests/mysql.php`) | 20 |
| Workspace, ledgers and permissions (`tests/workspace.php`) | 44 |
| Purchasing, schema upgrades and concurrent numbering (`tests/workspace-upgrade.php`) | 57 |
| Payables, cheques and concurrent/retried payments (`tests/accounts-payable.php`) | 44 |
| New backend regressions, full restore and rollback (`tests/regressions.php`) | 44 |
| Browser installation, meter, reports, collections and purchase errors | 31 |

**285 automated checks passed in total.** All PHP files and application JavaScript files passed syntax checks. Regression recovery deliberately tries a backup with broken foreign-key links: its expected rejection is logged, and the test confirms original data survives.

Browser coverage includes a space-padded password, Tamil setup fields, duplicate usernames, meter previews, editing/staging, atomic failed batches, inherited blank cards, enabled post-save controls, a lost response after database commit and retry, prior-days report loading, a 390px mobile viewport, Purchasing Notes newlines, CSV validation/download, negative temperature, collection page rollover and an inline rejected purchase void.

## Re-running tests

Use an isolated MySQL/MariaDB server and an account allowed to create/drop temporary `efs_check_*` databases. Set `EFS_TEST_HOST`, `EFS_TEST_PORT`, `EFS_TEST_USER`, `EFS_TEST_PASSWORD` as needed; defaults are localhost port 3306, root, blank password.

```text
php tests/run.php
php tests/mysql.php
php tests/workspace.php
php tests/workspace-upgrade.php
php tests/accounts-payable.php
php tests/regressions.php
```

Browser tests require Node.js and Playwright only on the test machine. The application itself needs neither. Set `EFS_TEST_URL` to a separate, unconfigured test copy at `http://127.0.0.1:PORT/`, `EFS_TEST_PORT` to the test MySQL port, and optionally `EFS_CHROME_PATH`. Run `node tests/browser-regressions.cjs`. This test creates a station and financial fixtures; do not point it at an existing station. Delete only its temporary `efs_check_*` database afterward.

## Limits

No live production migration was performed. Physical printing, actual Windows filesystem behaviour, real devices, public hosting, and optional cloud/license-server integrations were not exercised. The local application requires Apache/PHP and MySQL running; it has no browser-only offline transaction queue. Older SQL backup restoration requires a separate upgrade step. Browser checks use a desktop Chromium engine with a mobile viewport, not a physical Android phone. These checks do not establish exhaustive absence of defects.
