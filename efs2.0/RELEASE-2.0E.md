# EFS 2.0E — bug-fix release

Date: 3 November 2026. Based on the supplied EFS 2.0D ZIP and its 35-finding QA report. Database schema remains version 9. This is the PHP/MySQL XAMPP application, not an APK or Windows EXE.

## புதிதாக install செய்ய / Fresh installation

1. ZIP-ஐ extract செய்து `efs` folder-ஐ `C:\xampp\htdocs\efs`-க்கு copy செய்யுங்கள்.
2. XAMPP-ல் Apache மற்றும் MySQL இரண்டையும் Start செய்யுங்கள்.
3. Browser-ல் `http://localhost/efs/setup.php` திறக்கவும்.
4. புதிய database name, station details, owner username/password கொடுத்து install செய்யவும். உங்கள் உண்மையான station-க்கு **Empty station** தேர்வு செய்யவும்.
5. Login செய்து products, employees, pumps மற்றும் opening stock சரிபார்க்கவும்.

The release deliberately contains no saved `config/database.php`, installation lock, old SQL backups or test databases. The installer creates your own connection file. PHP 8.2+ on 64-bit XAMPP is recommended; application code retains the original PHP 7.4 compatibility target.

## ஏற்கெனவே பயன்படுத்தும் system-ஐ update செய்ய / Existing installation

1. தற்போதைய database-ஐ phpMyAdmin மூலம் SQL export செய்து, முழு பழைய `efs` folder-யும் தனியாக backup எடுக்கவும். பழைய application Recovery-ல் defect இருப்பதால் அதன் restore-ஐ நம்பி மட்டும் update செய்ய வேண்டாம்.
2. அனைத்து users-ஐ logout செய்து Apache-ஐ நிறுத்தவும். பழைய folder-ஐ delete செய்ய வேண்டாம்.
3. இந்த ZIP-இல் உள்ள `efs` application files-ஐ பழைய `efs` folder-க்கு copy/replace செய்யவும். **உங்களுடைய `config/database.php`, `config/local.php` மற்றும் `var` folder-ஐ வைத்திருக்கவும்.** புதிய காலியான database create செய்ய வேண்டாம்.
4. Apache/MySQL start செய்து browser-ல் Ctrl+F5 மூலம் reload செய்யவும். Existing schema 9 data needs no new schema change. Older supported MySQL schemas upgrade automatically after private SQL backups.
5. ஒரு test sale, stock balance, customer/vendor balance, report மற்றும் backup/restore flow-ஐ தனி test database-ல் சரிபார்த்து பயன்படுத்தவும்.

## Important corrected behaviour

- **Save All is atomic:** all pump entries save together, or none save. Retry after a lost response returns the already saved batch.
- Meter readings and quantities use millilitres consistently. Preview and saved money use the same half-up rounding.
- Password spaces are preserved. New/reset passwords require at least 10 Unicode characters and at most **72 UTF-8 bytes**. Passwords longer than this are explicitly rejected to prevent bcrypt truncation. Existing bcrypt hashes are not rewritten. An older account created with an overlong password needs an admin reset to a supported password; preserve access through a working owner account before updating.
- Fuel quantity supports at most **3 decimal places**. `0.0017` is rejected for correction, never silently billed as `0.002`.
- Maximum unpaid invoices accepts **1–10000**. Zero gives a clear validation error instead of silently saving as one. Use a zero credit limit to stop new credit.
- Recovery accepts complete **schema 9** exports. Older exports must first be imported into a separate empty database and upgraded, then exported again. Never import a backup over a live working database.
- Draft meter entries remain available for review when the station day changes. Old-date entries must be removed and current-day readings entered. Navigation/reload/logout protection covers unsaved meter drafts.
- Vendor cash payments retain the existing application's independent cash-payment behaviour; they do not reduce an operator's shift drawer. Bank and cheque flows retain their own ledgers.

See `FIXES-AND-TESTS.md` for every reported issue and verification. This release was tested locally; the user's production database, printer, Windows/XAMPP installation and optional external integrations were not accessed.
