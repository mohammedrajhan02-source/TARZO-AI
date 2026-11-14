# EFS 2.0F — Direct pump sales and saved-sale editing

3 November 2026. Based on the supplied `efs2.0.zip`. Database schema remains version 9.

## Requested workflow

1. Open Sales → Pump Sales.
2. In **Daily pump sales**, select Shift, Pump and Employee; enter Closing Meter.
3. Press **Enter**, or click **Save sale**. The sale is saved immediately and appears in **Today’s sales**.
4. The upper form clears completely after success. Select the next pump to load its current starting meter.
5. Click **Edit** on a saved row. Change the employee/shift or closing reading, then press Enter or click **Update sale**. **Cancel edit** leaves the saved data untouched.

There are no staging cards, Add Another Pump Sale or Save All controls on this page. An invalid entry stays in the form with its error; it is not added to Today’s sales. Network retries and repeated Enter presses do not create duplicate sales.

## Existing installation update — பழைய system-க்கு

1. Current application folder மற்றும் database-ஐ backup எடுக்கவும். Pending பழைய draft sales இருந்தால், update செய்யும் முன் save செய்து முடிக்கவும்.
2. எல்லா users-ஐயும் logout செய்து XAMPP Apache-ஐ Stop செய்யவும்.
3. ZIP-க்குள் இருக்கும் `efs2.0` folder-இன் files-ஐ உங்கள் existing application folder-க்குள் copy/replace செய்யவும். Extra nested folder உருவாக்க வேண்டாம்.
4. **உங்கள் existing `config/database.php`, `config/local.php`, `var` folder மற்றும் database-ஐ அப்படியே வைத்திருக்கவும்.** இந்த ZIP-ல் private database configuration அல்லது பழைய SQL backups சேர்க்கப்படவில்லை. Existing system-க்கு setup மறுபடியும் run செய்ய வேண்டாம்; empty database create செய்ய வேண்டாம்.
5. Apache Start செய்து, உங்கள் வழக்கமான application URL-ஐ திறந்து **Ctrl+F5** அழுத்தவும். Footer-ல் **2.0F** தெரியும்.

If applying only the application patch, copy these complete files, preserving their paths:

- `api.php`
- `app/PumpMeterSales.php`
- `app/templates/workspace.html`
- `assets/pump-meter-sales.js`
- `assets/pump-meter-sales.css` (new file)
- `assets/brand.js`

For a fresh installation only: put the extracted `efs2.0` folder under `C:\xampp\htdocs`, start Apache/MySQL and open `http://localhost/efs2.0/setup.php`. Use your own station and database details.

## Editing rules

- Admins/managers can edit every today-sale row. Cashiers can edit their own rows. Viewers cannot edit. Earlier days remain in the read-only Sales Report.
- The original pump, date, starting meter, selling price and cost rate are retained. Price changes in Products do not reprice an old sale.
- Editing an earlier closing meter also changes the next sale’s starting meter on that same pump. The preview shows the next sale’s new litres and amount before saving. Both changes commit together. Readings must remain in sequence, with positive quantities; a different-day sale or a legacy billing shift can prevent a boundary correction.
- Stock, sales receipts, costs, profit and outstanding employee balances follow the corrected quantities. The original stock movements and cash receipts are retained; corrections have audit records and stock adjustments.
- A corrected amount cannot fall below cash already collected, including on an affected next sale. Once collected, its employee and shift cannot be reassigned here.
- If another user edits or collects against an open edit, cancel it and click Edit again to reload current values.
- Insufficient stock still prevents saving. The screenshot’s “Available 0.000 L” requires the correct purchase/opening stock to be recorded first; this update does not invent stock.

See **VERIFICATION-2.0F.md** for executed checks and environment limits. Older release and verification documents are retained as historical records; this document defines the current Pump Sales behaviour.
