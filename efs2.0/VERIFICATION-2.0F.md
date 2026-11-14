# EFS 2.0F verification

Executed 3 November 2026 against isolated test databases and disposable application copies. No production station database was used.

| Environment | Executed version |
| --- | --- |
| PHP | 8.3.6, 64-bit Linux |
| MariaDB | 10.11.14, InnoDB |
| Browser | Chromium 131.0.6778.204 via Playwright |
| Viewports | 1440 × 1000 desktop; 390 × 844 mobile |

| Automated suite | Passing checks |
| --- | ---: |
| Core station (`tests/run.php`) | 45 |
| MySQL invariants/concurrency (`tests/mysql.php`) | 20 |
| Workspace/accounts (`tests/workspace.php`) | 44 |
| Registration/purchasing/migrations (`tests/workspace-upgrade.php`) | 57 |
| Accounts payable (`tests/accounts-payable.php`) | 44 |
| Existing fixes (`tests/regressions.php`) | 44 |
| Saved pump sale editing (`tests/pump-edit.php`) | 52 |
| Browser flows (`tests/browser-regressions.cjs`) | 45 |
| **Total** | **351** |

31 PHP source/test files passed syntax checks; 9 JavaScript assets/test files passed Node syntax checks. The packaged archive and SHA-256 file manifest were verified. The PHP 7.4 compatibility target is retained, but this update was executed on PHP 8.3 only. Windows/XAMPP, physical printers and external sync services were not exercised.

## Pump Sales acceptance evidence

- Enter and Save sale immediately persist a single sale to Today’s sales. The preview is never displayed as a saved row. Success clears shift, pump, employee and closing fields.
- Every saved row has an Edit action for an administrator. Edit preloads persisted values; Enter and Update sale change that row without another sale. Cancel leaves it unchanged.
- A new entry starts at the corrected latest meter reading. Earlier-row corrections preview and atomically adjust the following opening meter, volume and amount at each row’s original prices and costs.
- Product and tank stock follow quantity differences; historical stock movements remain and adjustments are appended. Standalone pumps without nozzles also support editing.
- Current receipts, item quantities, costs, profit and outstanding employee balances agree after corrections. Actual cash receipts remain unchanged.
- Tests reject overselling, tank overcapacity, zero/negative quantity, extra decimal precision, forged identity/opening/price, employee/shift mismatch, changed tank connection, and conflicting billing shift boundaries.
- Tests protect collected cash on both affected rows, disallow reassignment after collection, reject stale edits after another edit/collection/new sale, and enforce today-only and role/ownership rules.
- Lost-response retries for both creation and editing commit once. Repeated Enter sends one request. If only the metadata reload fails after a successful save, the fresh form is blocked until Reload readings succeeds.
- Browser reload retains all saved rows and totals. An existing unsaved form is protected when another row’s Edit is clicked.
- Desktop/mobile screenshots were inspected. Long employee names wrap; table scrolling stays inside its container and the Edit column remains visible on mobile. No uncaught browser JavaScript errors occurred.
- Existing purchasing, report download, numeric precision, collection pagination and purchase-void failure flows passed alongside the new workflow.

Detailed assertion output is in `tests/verification-results.txt`. The expected invalid-backup rejection in the regression suite is a deliberate negative test; its exception is logged by the application and caught by the test. All suites exited successfully.

## Re-running

Use a disposable MariaDB/MySQL service. PHP tests create and remove uniquely named `efs_check_*` databases and require a test account with database-create privileges. Never point the browser runner at an installed station: it expects an unconfigured disposable application copy and creates a new test station.

```sh
php tests/run.php
php tests/mysql.php
php tests/workspace.php
php tests/workspace-upgrade.php
php tests/accounts-payable.php
php tests/regressions.php
php tests/pump-edit.php
```

Connection overrides: `EFS_TEST_HOST`, `EFS_TEST_PORT`, `EFS_TEST_USER`, `EFS_TEST_PASSWORD`. For browser checks, install Playwright in the test environment, serve a clean disposable application copy on localhost and set `EFS_TEST_URL`, `EFS_TEST_PORT`, and optionally `EFS_CHROME_PATH` before running `node tests/browser-regressions.cjs`.
