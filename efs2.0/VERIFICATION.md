# EFS 1.4.0 — Work Space verification

This release upgrades Category, Products, Pumps, Employees, Vendor and Purchasing in the existing XAMPP/MySQL source. It retains the station billing, stock, shift, receivable, payable, bank and report workflows. Schema 3 upgrades additively to schema 4 after a private SQL backup.

## Executed environments

| Component | Actually executed |
| --- | --- |
| PHP 7.4 | 7.4.3, Ubuntu vendor build 7.4.3-4ubuntu2.29, 64-bit |
| PHP 8.3 | 8.3.6, Ubuntu vendor build 8.3.6-0ubuntu0.24.04.10, 64-bit |
| Database | MariaDB 10.11.14 with real InnoDB tables, foreign keys and transactions |
| Browser | Headless Chromium 156 driven by Playwright |
| HTTP mounting | /efs/ subfolder, matching the XAMPP folder layout |

## Fresh passing checks

| Suite | Checks per PHP version | Combined |
| --- | ---: | ---: |
| Core station behavior | 37 | 74 |
| Existing workspace/accounts behavior | 44 | 88 |
| MySQL invariants and simultaneous fuel sales | 20 | 40 |
| New Work Space fields, purchases and migration | 40 | 80 |
| HTTP install/authentication/API flows | 26 | 52 |
| Existing browser journeys | 17 | 34 |
| Upgraded Work Space browser journeys | 36 | 72 |
| **Total** | **220** | **440** |

All 25 PHP files also parsed in PHP 7.4 and PHP 8.3. All JavaScript assets passed syntax checks. The final ZIP was checked for complete contents and archive integrity before delivery.

### Requested behavior verified

- Category Code/Name persists; duplicate codes and unauthorized creation are rejected. Products select saved categories and retain the selection when edited.
- Product item code, name, whole quantity, cost price, selling price and category persist with integer cents/stock units. Unknown categories are rejected.
- An empty station displays three same-page pump entry cards. Add Pump appends a fourth card without navigation. Saving one card preserves unsaved readings on another. Saved cards remain in numeric Pump 1/2/3/4 order.
- Pump descriptions and start/end readings persist; an end below the start is rejected. A registered pump can connect to a tank, receive a delivery and complete a fuel sale. Pumps with shift history retain protected registration readings.
- Employee CodeNo and Name share a row. Shift/address occupy the next row, with Day/Night radio choices on the left and address on the right. Contact follows. Stored address, Night choice and contact were checked.
- Vendor code, name, opening current balance, address and city persist. Desktop layout places balance/city on the left and name/address on the right. Opening balance and later purchases agree with the vendor statement.
- Purchasing uses a date, generated read-only Con Bill No, manual Company Bill No and a selector containing saved vendor codes/names. Product and fuel purchases update stock and vendor payable. Repeated requests do not receive stock twice. Invalid dates and duplicate company bills roll back; failed purchases do not consume a number. Concurrent purchases receive different system-generated numbers.
- Schema-3 migration preserves a seeded owner/password hash, station name, product stock, posted sales/payments, vendor payables, nozzle readings, audit trail and both historical purchase types. Historical purchase references/dates are backfilled. The pre-upgrade SQL backup restores the original schema. Reopening schema 4 does not migrate again.
- Desktop screenshots at 1440px and mobile journeys at 390px were inspected. All six new pages had no horizontal page overflow. Day and night themes, existing station pages and absence of JavaScript errors were checked.

Browser test folders use fresh unique paths. Earlier retries using reused folders exposed a test setup file-write conflict; isolated reruns and the final complete run passed. Tests wait for saved state to refresh before checking history-based read-only controls.

## Included developer checks

Start MySQL, then run from the project directory:

```text
php tests/run.php
php tests/workspace.php
php tests/mysql.php
php tests/workspace-upgrade.php
```

For Windows XAMPP, use C:\xampp\php\php.exe in place of php. Tests default to 127.0.0.1:3306, root and a blank password. Override EFS_TEST_HOST, EFS_TEST_PORT, EFS_TEST_USER and EFS_TEST_PASSWORD for another test account. CREATE/DROP DATABASE privileges are required: suites create unique efs_check_ databases and remove only their own databases. MySQL/concurrency upgrade checks need proc_open enabled in the CLI. These are optional developer checks, not app installation steps.

The fixture tests/fixtures/schema-3.sql is for migration tests only. Do not import it into your station database.

## Evidence limits

- The compatibility target remains 64-bit PHP 7.4 / PHP 8.x. PHP 7.4 and 8.3 were executed; other 8.x versions were not individually run. PHP 5.x / 7.0–7.3 are unsupported.
- SQL targets MySQL 5.7+ / MariaDB 10.3+. MariaDB 10.11.14 was executed; older MariaDB and MySQL Server releases were not separately run. MySQL before 8.0.16 does not enforce CHECK constraints; application validation remains necessary.
- Windows XAMPP, its actual Apache/.htaccess configuration, the desktop CMD launcher and physical printers were not executed here. Standard Apache 2.4 access rules are included; Apache must honour them to protect app/config/mysql/var/tests.
- MySQL DDL commits implicitly. The migration makes a full backup first and checks columns/indexes before adding them; it is not a single rollbackable schema transaction. Stop Apache while copying upgraded source and keep the old source/config and SQL backup.
- This upgrade supports the prior MySQL schema 3 edition. It does not import the original SQLite edition automatically.
- Pump meters on the registration page are opening/reference values, not a replacement for shift readings. Current Balance is an opening payable for a new vendor and calculated thereafter. Purchasing still receives one product or one fuel delivery per entry.
- No hardware drivers, bank feed, cloud deployment, statutory filing, refunds/voids or Windows EXE installer is included. Offline operation requires local Apache and MySQL to remain running.

Primary compatibility references: https://www.php.net/manual/en/migration74.new-features.php, https://www.php.net/manual/en/ref.pdo-mysql.php, https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns.
