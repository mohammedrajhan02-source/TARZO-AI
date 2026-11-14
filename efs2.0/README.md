# Ezy Filling System 2.0H — XAMPP / MySQL Edition

**Purchasing update:** See RELEASE-2.0H.md for installation and Purchase Management.

**Start with RELEASE-2.0G.md for the compact Daily sale / Day Sales / Sales Report workflow, printing/PDF and installation steps. VERIFICATION-2.0G.md records 388 passing checks. Preserve your existing database connection and data. Older release documents are historical.**

Extract **efs** into **C:\xampp\htdocs\efs**, start **Apache** and **MySQL** in XAMPP, then visit **http://localhost/efs/**. The setup page creates an empty MySQL database, installs all tables, and creates your own station owner. Choose empty or clearly labelled demo records. No default application password.

The six Work Space registers now include Category, Products, same-page Pump cards, Employees with Day/Night selection, Vendor details and Purchasing with automatic Con Bill No. Category and Vendor selectors load saved records. Read **WORKSPACE-UPGRADE-TAMIL.md** before upgrading an existing station.

Read **START-HERE.html** for the illustrated layout guide or **README-TAMIL.md** for complete Tamil instructions and troubleshooting. The package contains application PHP, HTML templates, CSS, JavaScript and **mysql/efs.sql** for an optional phpMyAdmin import. No Composer, Node, Python, SQLite extension or internet is needed to run it.

The compatibility target is **64-bit PHP 7.4 and PHP 8.x**, using PDO MySQL, JSON, session and ctype; MySQL 5.7+ / MariaDB 10.3+. PHP 8.2+ is recommended. PHP 5.x / 7.0–7.3 are unsupported. See **VERIFICATION.md** for versions actually executed; compatibility targets are not claims of testing every runtime.

This MySQL edition supports automatic additive upgrades from EFS 1.3.0 schema 3 to schema 4, including a private SQL backup before migration. It does not migrate the original SQLite edition. Keep any original SQLite business database. Existing tables are never automatically deleted; setup refuses databases already containing a station owner. After installation, connection settings are in **config/database.php**. The protected **var/backups** folder stores portable SQL exports; import them into a new empty database to restore. Apache must honour the included .htaccess files (standard XAMPP configuration does).

The station workspace retains products, pumps/nozzles/tanks, employees, shifts, vendors, fuel and product purchasing, payables, customers, pump and customer credit collections, statements, expenses, bank accounts/reconciliation, user access, billing, receipts and reports. Optional cloud and licensing remain disabled unless the owner separately configures their own services. Hardware drivers, tax filing, refund/void workflows, a cloud deployment and a Windows EXE installer are not included.

All business writes use InnoDB transactions and a station mutex to retain the source application's serial write behavior. Partial unique indexes have been converted to MySQL/MariaDB generated-column indexes. Monetary values remain integer cents; fuel volumes remain integer millilitres. The API includes CSRF/session checks, permissions and retry protection. SQL backups include portable view definitions and exclude generated columns from INSERT statements.

This package is configured for localhost. Do not expose XAMPP to the public internet. Local use requires Apache and MySQL to remain running; there is no browser-only offline queue. Test your actual station settings and printer before entering real transactions.
