# EFS 2.0H – Purchase Management

Open Work Space → Purchasing. Add a vendor or select an existing one. Select a category, search by product code or name, then choose **Add Item**. Enter received liters and the unit cost. Use **Done** to finish editing a line, or **Purchase** to post the order. **Clear** discards the entry form. Sale Price starts at the catalog rate and is recorded on the PO. Change the retail price for future sales in the product register.

**Purchase Report** groups product receipts and earlier fuel deliveries by their recorded PO number. Search by inclusive dates, vendor, PO number or your own reference. View, edit or print one order. The report Print button prints all matching orders, even beyond the current page (narrow the search for more than 5,000 orders). Use the print view to print on A4 or save a PDF. Old POs retain their existing numbers. A dash means the historical sale price was not recorded.

**Existing station update.** Back up your station first. Extract the ZIP into a temporary folder. Copy the application files from its inner `efs2.0` into your current application directory, preserving the live `config` and `var` folders and the existing database. On the phone this application directory is normally `~/efs2.0` in Termux. Refresh the current site. The first request backs up the existing schema and upgrades to version 10, adding a nullable recorded sale price to product purchase lines. No reinstall or manual SQL import is needed.

The purchase stylesheet is compiled and works offline. Its Tailwind source and license are in `assets`. To recompile with Tailwind CSS 3.4.17, from the project root run `tailwindcss -i assets/purchase-management.tailwind.css -o assets/purchase-management.css --content assets/purchasing-batch.js --minify`.
