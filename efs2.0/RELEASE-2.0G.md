# EFS 2.0G — Daily sale, Day Sales and Sales Report

4 November 2026. Updated from the supplied `efs2.0.zip` (2.0F). Database schema stays at version 9. Existing sales automatically appear in the new daily report; no re-entry or database reset is needed.

## Pudhu workflow

1. Sales → Pump Sales open pannunga.
2. **Daily sale** first row: Date, automatic Sales control no, Shift, Employee, Available stock.
3. Second row: Select the pump (code), Pump name (with fuel), Starting meter, Closing meter. Code/name selectors refer to the same pump and update each other.
4. Details fill panni **Enter** or **Add** click pannunga. Andha line **Day Sales** table-la *Pending* status-la varum. Date, Shift, Employee appadiye irukkum. Pump code, Pump name, Starting meter, Closing meter mattum clear aagum.
5. Adutha pump reading enter pannalaam. Liters = Closing − Starting. Amount = Liters × Sales price. Table bottom-la total update aagum.
6. Pending lines-ai Edit pannalaam. **Save** click pannumbothu pending lines ellam onna save aagi **Sales Report** update aagum. Oru line invalid-na andha Save-la endha line-um post aagaadhu; correct pannittu retry pannunga.
7. Sales Report-la oru date-ku oru row: Date, Sales control no, Total, Profit, Overview. Andha date-ku later save pannura sales-um adhe row total-la add aagum.
8. **Overview** click pannina andha date-oda full sales mela Day Sales-la varum. Saved row **Edit** click panni, form-la change seidhu **Save changes** or Enter press pannunga. Idhu existing sale-ai immediately update seyyum; daily total/profit-um update aagum. **Cancel edit** saved data-ai maathaadhu.

## Control number, dates and stock

- One stable daily control number per station date: `DS-20261104` means 4 November 2026. This identifies the entire day; individual original receipt numbers stay intact.
- Date, Shift and Employee stay selected after Add, Save and page navigation. Pending lines and the current entry also survive a refresh in the same browser tab. Save before logging out or closing the tab/browser; browser storage is not a replacement for the database.
- Save pending entries and clear the active pump entry before switching dates or opening an already-saved row for editing. Changing the shift clears an employee who may belong to the previous shift.
- Available stock appears after choosing a pump. It uses its linked product balance and, where connected, the tank balance. The lower available quantity applies. Pending lines, including other pumps sharing that product/tank, are deducted in the preview. This shows station fuel available through that pump; it is not a separate physical pump tank.
- Admins/managers can select previous dates and edit their saved rows. Cashiers enter today’s sales and edit their own today rows. Viewers cannot write; cost/profit remain restricted to managers/admins.
- New historical entries can be appended only while they remain in chronological meter order. If later meter sales or billing shifts already exist on that pump, use Overview to correct existing rows instead. Future-dated sales are rejected.
- Correcting an earlier saved closing meter adjusts the following sale’s opening meter as well. The preview identifies the affected date and employee. Amounts use each sale’s original selling/cost rates. Stock adjustments and audit records preserve the accounting trail.
- Existing cash receipts are retained. A correction cannot reduce either affected sale below collected cash, and a collected sale cannot be assigned to a different employee/shift.
- Save accepts up to 200 pending lines at once. You can save several batches on the same day; the report still has one daily row. Lost-response retries do not duplicate a sale. If a network result is uncertain, retry Save to confirm it before editing those pending lines.

## Print / WhatsApp PDF

- Both **Day Sales** and **Sales Report** have **A4 Print**, **PDF** and **Share PDF**.
- A4 Print opens a clean portrait print view. Allow pop-ups for the local app if the browser blocks it. Use the printer dialog to print or save PDF.
- PDF downloads an actual paginated A4 PDF offline, including Tamil names. These downloadable PDFs use rendered page images to preserve font shaping.
- Share PDF opens the device share chooser when file sharing is supported; choose WhatsApp yourself. Otherwise it downloads the PDF, which you can attach in WhatsApp. The app does not send files automatically.
- Day Sales export includes the selected date and its displayed pending lines; any pending lines clearly mark it **DRAFT**. Save first for a final report.
- Sales Report export includes every saved day in the applied **From / To** filter, across all report pages. Filter dates are inclusive. All dates clears the filter. Very large exports are limited to 5,000 days; narrow the date range if needed.

## Existing-system update

1. Existing application folder and database-oda backup edunga. Pending sales-ai old version-la save pannittu users logout pannunga.
2. XAMPP Apache Stop pannunga.
3. ZIP-kkulla irukkira `efs2.0` folder-oda files-ai existing app folder-kulla copy/replace pannunga. Double nested folder create panna vendaam.
4. **Existing `config/database.php`, `config/local.php`, `var` folder, database ellathaiyum preserve pannunga.** Existing system-ku setup thirumba run panna vendaam; empty database create panna vendaam.
5. Apache Start panni usual URL open pannunga; **Ctrl+F5** reload seyyunga. Footer-la **2.0G** theriyum.

Application files changed for this release:

- `api.php`
- `app/PumpMeterSales.php`
- `app/PumpDaySales.php` (new)
- `app/templates/workspace.html`
- `assets/pump-meter-sales.js`
- `assets/pump-meter-sales.css`
- `assets/pump-sales-export.js` (new)
- `assets/brand.js`

For a fresh installation only, extract `efs2.0` under `C:\xampp\htdocs`, start Apache/MySQL and open `http://localhost/efs2.0/setup.php` with your own database and station details.

`VERIFICATION-2.0G.md` records the executed checks and limits. Older release/verification files are historical; this document defines the current workflow. The code was tested in isolated environments, not installed into your live station.
