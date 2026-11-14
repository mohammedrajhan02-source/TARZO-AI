# MySQL / MariaDB database

**efs.sql** is the complete fresh EFS 1.4.0 MySQL/MariaDB schema: 46 tables, 4 views, 70 foreign-key constraints, indexes, numeric/state checks and schema version 4. There are no default login credentials or sample sales.

Normally run **http://localhost/efs/** and use the setup page. It creates the database and installs this schema automatically; no phpMyAdmin import is required.

For manual import, create a NEW empty database in phpMyAdmin, select it, choose **Import → efs.sql → Go**, then open setup.php to create your station owner. Do not import the fresh schema over an existing station database. Conditional unique indexes use generated columns compatible with MySQL 5.7+ and MariaDB 10.3+. MySQL versions before 8.0.16 do not enforce CHECK constraints; application validation, foreign keys, unique indexes and transaction checks still apply.

This file is not SQLite SQL. The original uploaded project's SQLite data is not migrated automatically. Do not import a .sqlite file or that project's SQLite database.sql into MySQL.

Application backups are complete .sql exports, including committed records and portable views. Restore a backup into a separate EMPTY database, then point config/database.php to it. Keep backups private.

Existing EFS 1.3.0 schema 3 databases are upgraded by app/Migrations.php at startup, after a private SQL backup. Keep config/database.php, stop Apache while replacing source, then start Apache and refresh. Do not import efs.sql over an existing database. The test fixture tests/fixtures/schema-3.sql exists only for automated migration verification.
