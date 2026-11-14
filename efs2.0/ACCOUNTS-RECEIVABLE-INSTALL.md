# Accounts Receivable update

This update adds pump/date payment allocation and multi-product customer credit invoices to the uploaded EFS application. Use the Accounts Receivable navigation entry. Existing pump shortage and customer collection screens remain available.

## Workflow

1. Select the sale date and pump. The total comes from saved Pump Sales, not manual entry.
2. Enter Cash, Card Pay and Credit Sales. Total Amount is their sum; Difference is recorded pump sales minus that sum. Save the allocation before entering invoices.
3. A positive saved credit allocation opens the credit invoice form. Select a customer and enter an invoice number. Contact information comes from that customer.
4. Select a pump/product and its recorded selling rate, enter litres, then press Enter to add a pending line. Customer, contact, invoice number and date stay selected. Repeat for more products. Each pump needs its own saved credit allocation for that date.
5. Review/edit/remove pending lines, then Save credit invoice once. This creates one multi-line invoice and updates the register. View and print from the register; unpaid invoices may be edited subject to permissions.

Sales Difference and Credit Remaining are separate: the first reconciles pump sales against payment categories; the second shows credit allocation not yet assigned to customer invoices.

The invoice assigns already-recorded pump sales to customer debt. It does not create a second stock deduction or second sales revenue entry. Customer collections use the existing customer payment workflow.

## Install on a test copy first

This release includes a database upgrade. Do not import mysql/efs.sql over an existing station database; that file is for fresh installations only.

1. Back up the entire existing project folder AND export the current database. Keep both backups outside the folder being updated.
2. Create a separate XAMPP project/database test copy. Keep its config/database.php pointed at the test database. Preserve your existing config/local.php and var directory when replacing application files.
3. Copy the updated application files into that test project. Open the app. Schema 10 upgrades additively to schema 11 and creates a pre-upgrade SQL backup; the database user needs the corresponding DDL permissions and the backup folder must be writable.
4. Sign in and hard-refresh with Ctrl+F5.
5. Run the acceptance checks in ACCOUNTS-RECEIVABLE-VERIFICATION.md before applying the update to your working station.

Keep the new files and the upgraded database together. To roll back, restore the pre-update application AND database backup together; simply copying old PHP over schema 11 is not a safe rollback.

The uploaded project's existing runtime requirement remains 64-bit PHP 7.4+ with PDO MySQL and MySQL/MariaDB. This feature update does not convert the project to 32-bit PHP.
