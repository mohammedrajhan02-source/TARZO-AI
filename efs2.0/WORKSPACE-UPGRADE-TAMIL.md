# EFS 1.4.0 — Work Space upgrade

இந்த version-ல் நீங்கள் கேட்ட Work Space பகுதிகள் database மற்றும் browser forms-உடன் சேர்க்கப்பட்டுள்ளன.

| Page | Fields / வேலை செய்யும் விதம் |
| --- | --- |
| Category | Code, Name. Save செய்த categories Products dropdown-ல் வரும். |
| Products | Item code, Name, Quantity, Cost price, Selling price, Category select. |
| Pumps | ஒரே page-ல் Pump cards. Empty station-ல் Pump 1 / 2 / 3 forms வரும். Add Pump அழுத்தினால் அடுத்த card அதே page-ல் வரும். Code, Start meter, End meter, Description save செய்யலாம். |
| Employees | முதல் வரி CodeNo + Name. அடுத்த வரி இடப்பக்கம் Day/Night radio; வலப்பக்கம் Address. அதன் கீழ் Contact. |
| Vendor | Code No தனி வரி. அடுத்த வரி இடப்பக்கம் Current Balance, வலப்பக்கம் Name. அடுத்த வரி இடப்பக்கம் City, வலப்பக்கம் Address. |
| Purchasing | Date; Purchasing No பகுதியில் automatic Con Bill No + manual Company Bill No; saved Vendor Code and name dropdown. Product/fuel quantity, cost and payment details தொடரும். |

## புதிய installation

1. ZIP-ஐ extract செய்யுங்கள்.
2. `efs` folder-ஐ `C:\xampp\htdocs\efs` என்ற இடத்தில் வையுங்கள்.
3. XAMPP-ல் Apache + MySQL Start செய்யுங்கள்.
4. `http://localhost/efs/` திறந்து setup முடிக்கவும். Fresh database தானாக உருவாகும்.

## ஏற்கெனவே EFS 1.3.0 run செய்திருந்தால்

1. Company Profile-ல் **Create backup** செய்து SQL copy-ஐ பாதுகாப்பாக வையுங்கள். Existing `efs` source folder-க்கும் ஒரு copy வைத்துக்கொள்ளுங்கள்.
2. XAMPP Control Panel-ல் **Apache → Stop**. MySQL running-ஆக இருக்கட்டும்.
3. இந்த ZIP-ல் உள்ள `efs` files-ஐ உங்கள் existing `htdocs\efs` folder-க்கு copy செய்து source files-ஐ replace செய்யுங்கள்.
4. உங்கள் **`config/database.php`**, custom **`config/local.php`**, **`var`** folder ஆகியவற்றை வைத்துக்கொள்ளுங்கள். இந்த ZIP-ல் உங்கள் connection settings-ஐ overwrite செய்யும் database.php இல்லை.
5. Apache → Start. Browser-ல் `http://localhost/efs/` திறந்து **Ctrl+F5** செய்து refresh செய்யுங்கள்.
6. Existing schema 3 → schema 4 upgrade தானாக நடக்கும். அதற்கு முன் private SQL backup `var/backups/EFS-before-workspace-4-…sql` என்ற பெயரில் உருவாகும். Existing users, passwords, sales, stock, bank entries, payments ஆகியவை வைத்துக்கொள்ளப்படும்.

**Existing database-க்கு `mysql/efs.sql`-ஐ மீண்டும் import செய்ய வேண்டாம்.** அது புதிய empty database-க்கு மட்டும். உங்கள் database-ஐ delete செய்யவும் வேண்டாம்.

Backup permission / schema error வந்தால் Apache PHP error log பார்க்கவும். Pre-upgrade backup மற்றும் old source copy-ஐ வைத்துக்கொள்ளுங்கள். ஒரு interrupted upgrade-ஐ retry செய்வதற்கு புதிய source-ல் column/index checks உள்ளன; DDL updates MySQL-ல் ஒரே transaction-ஆக rollback ஆகாது. Old code-க்கு திரும்ப வேண்டுமானால் pre-upgrade SQL-ஐ புதிய empty database-ல் restore செய்து old source config-ஐ அந்த database-க்கு point செய்யுங்கள்.

## பயன்படுத்தும் வரிசை

**Category → Products:** முதலில் Category page-ல் Code + Name save செய்யுங்கள். Products page-ல் அதன் code/name dropdown option-ஆக வரும். Product edit செய்யும்போதும் ஏற்கெனவே தேர்வு செய்த category காட்டப்படும்.

**Vendor → Purchasing:** Vendor Code No, Name, Address, City கொடுத்து save செய்யுங்கள். Purchasing page-ல் அந்த Code No + Name-ஐ select செய்யலாம்.

**Con Bill No:** `CON-2026-000001` போன்ற number server-ல் save செய்யும் நேரத்தில் உருவாகும். Form-ல் காட்டுவது next-number preview. ஒரே நேரத்தில் வேறு purchase save ஆனால் final number மாறலாம்; register-ல் உண்மையான saved number இருக்கும். Failed entry number-ஐ consume செய்யாது; அதே successful request-ஐ retry செய்தால் duplicate stock வராது. பழைய purchases-க்கு `LEG-…` reference சேர்க்கப்படும்; அவற்றின் Company Bill No மாற்றப்படாது.

**Company Bill No:** Supplier bill-ல் உள்ள number-ஐ type செய்யுங்கள். ஒரே vendor-க்கு ஒரே Company Bill No-ஐ இரண்டு முறை save செய்ய முடியாது. முந்தைய edition போல ஒவ்வொரு entry-யிலும் ஒரு product அல்லது ஒரு fuel tank delivery இருக்கும். Multi-line bill-க்கு `BILL-24/1`, `BILL-24/2` போன்ற line reference பயன்படுத்தலாம்.

## Balance / meter fields

- புதிய Vendor-ல் உள்ள Current Balance என்பது opening balance. Positive என்றால் vendor-க்கு கொடுக்க வேண்டிய தொகை; negative என்றால் vendor-இடம் உள்ள credit. Save செய்த பிறகு Current Balance purchases minus payments மூலம் கணக்கிடப்படும்; edit form-ல் read-only. Metadata edit மூலம் posted balance மாற்றப்படாது.
- Pump Start meter / End meter என்பது registration reference. End meter Start meter-ஐவிடக் குறைவாக இருக்க முடியாது. Supply tank select செய்தால் first nozzle உருவாகும்; அதன் opening totalizer End meter-ஐ பயன்படுத்தும்.
- Shift history வந்த பிறகு pump registration readings read-only. Live meter readings-ஐ Shifts-ல் பதிவு செய்யுங்கள். Registration form stock அல்லது sales-ஐ தானாக உருவாக்காது.
- Product-க்கு sales / purchase history வந்த பிறகு quantity edit read-only. புதிய stock-ஐ Purchasing மூலம் சேர்க்கவும். Cost/selling price/category போன்ற details edit செய்யலாம்.
- Employee Day/Night என்பது usual/preferred shift. Shift operate செய்ய Users Admin-ல் login link தேவையாக இருக்கும்.

PHP compatibility முந்தைய edition போலவே: 64-bit PHP 7.4 / 8.x target; 7.4 மற்றும் 8.3-ல் actual checks. MySQL/MariaDB SQL மற்றும் PHP/HTML/CSS/JavaScript source அனைத்தும் ZIP-ல் உள்ளன. Windows XAMPP / printer checks உங்கள் PC-ல் செய்ய வேண்டும்; complete evidence `VERIFICATION.md`-ல் உள்ளது.
