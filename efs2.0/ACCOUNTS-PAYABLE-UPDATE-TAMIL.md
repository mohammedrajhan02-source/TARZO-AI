# EFS — Accounts Payable upgrade

இந்த update, நீங்கள் அனுப்பிய efs2.0 project மற்றும் Accounts Payable sketch அடிப்படையில் செய்யப்பட்டுள்ளது. பழைய Category, Products, Pumps, Vendor, Purchasing UI source மாற்றப்படவில்லை.

## ஏற்கனவே இயங்கும் XAMPP project-ல் update செய்வது

1. Company Profile → Download backup மூலம் தற்போதைய database backup எடுக்கவும். உங்கள் தற்போதைய `htdocs/efs2.0` folder-ஐயும் ஒரு copy வைத்துக்கொள்ளவும்.
2. மற்ற users-ன் பதிவுகள் முடிந்ததும் அனைவரும் logout செய்யவும்.
3. இந்த ZIP-ஐ extract செய்யவும். அதிலுள்ள `efs2.0` folder-இலிருந்து **`app` folder, `assets` folder, `api.php`, `setup.php`** ஆகியவற்றை மட்டும் தற்போது இயங்கும் `htdocs/efs2.0` folder-க்குள் copy செய்து Replace செய்யவும். `app/AccountsPayable.php`, `assets/accounts-payable.js`, `assets/accounts-payable.css` ஆகிய புதிய files-உம் சேர வேண்டும்.
4. உங்கள் தற்போதைய **`config/database.php`, `config/local.php`, `var` folder** ஆகியவற்றை மாற்ற வேண்டாம். Existing database-ல் `mysql/efs.sql` மீண்டும் import செய்ய வேண்டாம். Setup மூலம் database-ஐ புதிதாக உருவாக்க வேண்டியதில்லை.
5. பழைய அதே local URL-ஐத் திறந்து **Ctrl + F5** செய்யவும். முதல் load-ல் schema 5 → 6 automatic upgrade நடக்கும். Schema 3/4 இருந்தாலும் வரிசையாக upgrade நடக்கும்.
6. Upgrade முன் SQL backup `var/backups/EFS-before-payables-6-....sql`-ல் தானாக உருவாகும். அந்த folder writable-ஆக இருக்க வேண்டும். Permission error வந்தால் அதனைச் சரிசெய்து reload செய்யவும்; database-ஐ delete செய்ய வேண்டாம்.
7. Work Space → **Accounts Payable** திறக்கவும்.

ZIP-ல் ஒரே ஒரு `efs2.0` root folder உள்ளது; தேவையில்லாமல் `htdocs/efs2.0/efs2.0` என nested folder உருவாக்க வேண்டாம்.

## பயன்படுத்தும் முறை

- **Transaction date + Vendor:** vendor தேர்வு செய்ததும் அதன் தற்போதைய balance மற்றும் statement வரும்.
- **Add payable:** Description + Amount உள்ளிட்டு Add payable அழுத்தவும். இது vendor-க்கு செலுத்த வேண்டிய தொகையை அதிகரிக்கும். Purchasing page-ல் ஏற்கனவே பதிவு செய்த purchase-ஐ இங்கே மீண்டும் சேர்க்க வேண்டாம். இந்த entry stock அல்லது sales-ஐ மாற்றாது.
- **Cash:** open shift drawer தேர்வு செய்யவும். Payment vendor balance மற்றும் அந்த drawer cash இரண்டையும் குறைக்கும். இன்று தேதியில் மட்டுமே cash payment பதிவு செய்யலாம். Drawer-ல் போதுமான cash இருக்க வேண்டும்.
- **Own cheque:** Bank Admin-ல் account number உடன் பதிவு செய்த own bank account தேர்வு செய்யவும். Account number, bank name தானாக வரும். Branch, cheque number, cheque date, amount, payment reference உள்ளிட்டு Record payment அழுத்தவும். ஒரே bank account-ல் ஒரே cheque number இருமுறை பயன்படுத்த முடியாது. Bank book balance issue செய்யும் நேரத்திலேயே குறையும்; cheque date எதிர்காலத்தில் இருந்தாலும் இதே நடைமுறை.
- **Party cheque:** கையில் உள்ள cheque-ஐ select செய்யவும். Cheque number, date, holder, bank/branch, முழுத் தொகை தானாக வரும். Amount மாற்ற முடியாது. ஒருமுறை செலுத்திய cheque மீண்டும் selection-ல் வராது. Own bank அல்லது cash drawer-ல் பணம் குறைக்கப்படாது.
- **Register cheque:** பழைய code-ல் cheque-in-hand register இல்லாததால் இது சேர்க்கப்பட்டுள்ளது. ஏற்கனவே அதன் source receipt-ல் கணக்கிடப்பட்ட, கையில் உள்ள transferable cheque-ஐ source reference உடன் பதிவு செய்யவும். இது customer receivable collection அல்லது bank deposit-ஐ தானாக பதிவு செய்யாது. Confirmation checkbox அவசியம்.
- **Bank transfer:** பழைய bank-payment செயல்பாடும் தொடர்கிறது.
- **Vendor statement:** Vendor, From, To தேர்வு செய்து Filter செய்யவும். Credit / Added, Paid, running Balance, opening balance, totals கிடைக்கும். Print selected statement மட்டும் திறக்கும்; CSV full filtered statement-ஐ export செய்யும்.

எல்லா monetary records-உம் integer cents-ல் கணக்கிடப்படுகின்றன. Browser-ல் காட்டுவது preview; server மீண்டும் balance மற்றும் payment details-ஐச் சரிபார்த்த பிறகே save ஆகும். API retry மற்றும் concurrent payments மூலம் double posting ஏற்படாதபடி transaction/request-key checks உள்ளன.

Bank clearance, bounced-cheque reversal மற்றும் புதிய customer cheque collection workflow இந்த update-ல் தானியங்கியாக சேர்க்கப்படவில்லை. Cheque issue/endorsement பதிவு மற்றும் statement மட்டுமே இந்த Accounts Payable scope.

## Compatibility / verification

- PHP 7.4+ 64-bit syntax conventions retained; tested here on PHP 8.3.6 + MariaDB 10.11.14.
- Chromium desktop 1440px, mobile 390px, dark/light modes tested.
- 203 backend assertions + 21 browser workflow assertions passed.
- Uploaded ZIP-ல் இருந்த 18 SQL backups-ஐ isolated databases-ல் migrate செய்து பழைய row counts மற்றும் vendor balances மாறவில்லை என்று சரிபார்க்கப்பட்டது.
- உங்கள் Windows/XAMPP machine-ல் நேரடியாக இயக்கவில்லை. PHP 7.4 மற்றும் MySQL 8 runtime tests இங்கே செய்யப்படவில்லை.
- மேலும் test விவரங்கள்: `ACCOUNTS-PAYABLE-VERIFICATION.md`.
