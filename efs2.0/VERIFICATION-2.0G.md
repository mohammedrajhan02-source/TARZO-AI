# EFS 2.0G verification

Executed 4 November 2026 using disposable application copies and isolated databases. Production station data was not modified.

| Suite | Passing checks |
| --- | ---: |
| Core station (`tests/run.php`) | 45 |
| MySQL invariants/concurrency (`tests/mysql.php`) | 20 |
| Workspace/accounts (`tests/workspace.php`) | 44 |
| Registration/purchases/migrations (`tests/workspace-upgrade.php`) | 57 |
| Accounts payable (`tests/accounts-payable.php`) | 44 |
| Earlier fixes (`tests/regressions.php`) | 44 |
| Saved meter editing (`tests/pump-edit.php`) | 53 |
| Daily saves, reports and dates (`tests/pump-day.php`) | 31 |
| Real-browser journeys (`tests/browser-regressions.cjs`) | 50 |
| **Total** | **388** |

Runtime: 64-bit PHP 8.3.6, MariaDB 10.11.14, headless Chromium 131.0.6778.204 with Playwright. Desktop viewport 1440 × 1000; mobile 390 × 844. All 33 PHP source/test files and 10 JavaScript assets/test files passed syntax checks. Desktop, mobile, downloaded PDFs and A4 print rendering were visually inspected.

## Requested behaviour verified

- All five header fields fit one desktop row and the second row contains linked pump selectors and meter readings. The desktop Daily sale panel is under 300 pixels high. Mobile reflows without page overflow; Edit and Overview remain reachable.
- Available stock is blank without a selected pump, displays the linked available fuel after selection, and accounts for queued quantities. The backend uses the lower product/tank stock.
- Enter adds a pending table line, calculates its exact litres/amount, retains date/shift/employee and clears only the pump/meter fields. Pending rows can be edited and survive a reload in the same browser tab.
- Save posts the entire batch atomically. Failed later lines roll back preceding sales and inventory. An ambiguous network response retains a stable request key; retry confirms the original batch without duplicate posting or double-counting in the displayed table.
- Multiple lines and batches aggregate to one date/control-number row. Profit is the sum of each line’s selling amount minus its recorded cost amount. Old rows appear automatically without migration.
- Previous-day Overview returns all lines with pump/fuel names. Authorized historical edits update affected daily totals and profits, preserving historical rates, stock, cash receipts, audit history and meter continuity.
- Date filters are inclusive; invalid/reversed dates are rejected. Empty ranges have zero totals. Pagination covered 55 dates; export returned all 55 rather than just the first visible page.
- Backdated sales are booked on the selected station business date. Future entries and out-of-order historical readings are rejected. Cashier/viewer permissions and cost/profit restrictions are enforced server-side.
- Day and report PDF downloads are real nonempty PDFs. Tamil text rendered correctly. A 90-row export produced 7 A4 pages with the final total on the last page. All pages parsed with correct A4 dimensions and content. A4 HTML printing was also rendered and checked, including a long Tamil station name.
- Share PDF’s download fallback was exercised. Native mobile share chooser/WhatsApp delivery and physical printers require the user’s device and were not exercised.
- Existing report downloads, purchasing, numeric precision, employee collection pagination, purchase-void errors, database recovery and upgrade/concurrency suites continue to pass. No uncaught browser JavaScript errors occurred.

## Re-running

Run the PHP files above with `php tests/<filename>`. `EFS_TEST_HOST`, `EFS_TEST_PORT`, `EFS_TEST_USER` and `EFS_TEST_PASSWORD` configure a disposable database service; tests create and remove uniquely named `efs_check_*` databases. Do not use a production database account/service for testing.

For browser checks, use an unconfigured disposable application copy on localhost, install Playwright in the test environment, set `EFS_TEST_URL` and `EFS_TEST_PORT`, and optionally `EFS_CHROME_PATH`/`EFS_TEST_SCREENSHOT`; run `node tests/browser-regressions.cjs`. The browser suite creates an isolated test station and outputs PDFs beside its screenshot path.

Detailed assertions are in `tests/verification-results.txt`. The invalid-backup exception logged during `regressions.php` is an expected negative test; the suite catches it and exits successfully. Compatibility targets are inherited from the project; this release was executed on PHP 8.3/Linux, not Windows/XAMPP, PHP 7.4 or every browser. External sync services were not exercised.
