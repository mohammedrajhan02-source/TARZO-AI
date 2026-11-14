# Ezy Filling System (EFS) 1.4.0 — XAMPP Edition


**Work Space 1.4 upgrade:** Category, Products, Pumps, Employees, Vendor, Purchasing forms மாற்றப்பட்டுள்ளன. Existing 1.3 users முதலில் **WORKSPACE-UPGRADE-TAMIL.md** படியுங்கள். `config/database.php`-ஐ வைத்துக்கொண்டு source files மட்டும் update செய்யவும்; existing database-க்கு fresh SQL import செய்ய வேண்டாம்.

## Run செய்வது — 5 steps

1. ZIP file-ஐ **Extract All** செய்யுங்கள்.
2. உள்ளே இருக்கும் **efs** folder-ஐ `C:\xampp\htdocs\`-க்குள் copy செய்யுங்கள்.
   சரியான file location: `C:\xampp\htdocs\efs\index.php`.
   XAMPP வேறு drive-ல் இருந்தால் அந்த XAMPP-ன் `htdocs` folder-ஐ பயன்படுத்துங்கள்.
3. **XAMPP Control Panel** திறந்து **Apache → Start**, **MySQL → Start** செய்யுங்கள். இரண்டும் running ஆக இருக்க வேண்டும்.
4. Chrome / Edge address bar-ல் **http://localhost/efs/** என்று type செய்யுங்கள்.
5. Setup page-ல் database details, உங்கள் பெயர், station name, owner username, password கொடுத்து **Install & open EFS** அழுத்துங்கள். பின்னர் **Open EFS workspace** அழுத்தி நீங்கள் உருவாக்கிய account-ல் login செய்யுங்கள்.

**Database-ஐ முன்பே create செய்யவோ SQL import செய்யவோ தேவையில்லை. Setup தானாக create செய்யும்.**

Fresh XAMPP-க்கான defaults:

| Setting | Value |
| --- | --- |
| MySQL host | `127.0.0.1` |
| MySQL port | `3306` |
| Database name | `efs` |
| MySQL username | `root` |
| MySQL password | காலியாக விடுங்கள் — password வைத்திருந்தால் அந்த password-ஐ கொடுங்கள் |
| EFS login username / password | Setup-ல் நீங்களே உருவாக்குங்கள் |

**MySQL password-உம் EFS login password-உம் வேறு.** EFS password குறைந்தது 10 characters இருக்க வேண்டும். Default login password கிடையாது.

## Empty / Demo

- **Empty station:** உங்கள் உண்மையான station details-ஐ சேர்க்க. Sample pumps, inventory, sales இல்லை.
- **Demo station:** Run செய்து பார்க்க sample fuel, tanks, pumps, customer, vendor, product இருக்கும். இதில் காட்டும் விலைகள் உண்மையான தற்போதைய விலைகள் இல்லை. Demo sales உண்மையான accounts-உடன் கலக்கக் கூடாது.

Demo பார்த்த பிறகு real business-க்கு வேறு folder மற்றும் புதிய empty database பயன்படுத்துங்கள். Database-ஐ delete செய்ய வேண்டாம்.

## Folder-க்குள் என்ன இருக்கிறது?

| File / folder | பயன்பாடு |
| --- | --- |
| `index.php` | Main entry; setup முடிந்ததும் station workspace திறக்கும் |
| `setup.php` | MySQL database, tables, owner account setup |
| `api.php`, `receipt.php` | Billing / accounts API மற்றும் receipt print |
| `assets/` | HTML workspace-க்கான CSS, JavaScript, icons; internet தேவையில்லை |
| `app/` | Application source + HTML template |
| `mysql/efs.sql` | phpMyAdmin-ல் import செய்யக்கூடிய **MySQL / MariaDB SQL** |
| `config/database.example.php` | Manual connection setup example |
| `config/database.php` | Setup உருவாக்கும் private database connection settings |
| `var/backups/` | Application மூலம் உருவாக்கும் `.sql` backup files |
| `OPEN-EFS.cmd` | Default localhost address-ஐ browser-ல் திறக்க |
| `START-HERE.html` | Offline setup guide — browser-ல் வாசிக்கலாம் |

MySQL server-ன் internal `data` directory, `.ibd` files இந்த ZIP-ல் இல்லை; அவை உங்கள் XAMPP database server உருவாக்கும் files. தேவையான schema மற்றும் application source அனைத்தும் இந்த ZIP-ல் இருக்கின்றன.

## phpMyAdmin மூலம் manual import — தேவைப்பட்டால் மட்டும்

1. Apache + MySQL running என்பதை உறுதி செய்யுங்கள்.
2. http://localhost/phpmyadmin/ திறக்கவும்.
3. **New** → database name `efs` → collation `utf8mb4_bin` → **Create**.
4. `efs` database-ஐ select செய்யவும் → **Import** → **Choose File** → `efs\mysql\efs.sql` → **Go**.
5. http://localhost/efs/setup.php திறந்து அதே database details கொடுத்து station owner உருவாக்குங்கள். Completed empty SQL import-ஐ setup கண்டுபிடித்து பயன்படுத்தும்; மீண்டும் schema import செய்யாது.

Existing EFS MySQL database-ஐ reconnect செய்ய: `config/database.example.php`-ஐ `config/database.php` என copy செய்து உங்கள் database details மாற்றுங்கள். ஏற்கெனவே owner இருக்கும் database-ல் setup புதிய owner உருவாக்காது. பழைய username/password-ஐ பயன்படுத்துங்கள்.

Uploaded original project SQLite பயன்படுத்தியது. இந்த MySQL edition **புதிய installation**; பழைய `.sqlite` database அல்லது SQLite SQL-ஐ MySQL-ல் import செய்ய முடியாது. Existing business records automatic-ஆ convert செய்யப்படவில்லை; original files-ஐ பாதுகாப்பாக வைத்திருங்கள்.

## PHP compatibility

- Target: **64-bit PHP 7.4 மற்றும் PHP 8.x**; modern Chrome / Edge / Firefox browser.
- PDO + `pdo_mysql`, JSON, session, ctype ஆகிய extensions தேவை. சாதாரண XAMPP installation-ல் இவை பொதுவாக இருக்கும்.
- SQLite extension, Composer, npm, Python தேவையில்லை.
- MySQL 5.7+ / MariaDB 10.3+ compatible schema பயன்படுத்தப்பட்டுள்ளது. Actual test environments / limits: `VERIFICATION.md`.
- PHP 5.x மற்றும் PHP 7.0–7.3 supported இல்லை. எல்லா historical / future PHP versions-க்கும் guarantee கிடையாது. PHP 8.2+ XAMPP பயன்படுத்துவது நல்லது.
- Optional cloud / licensing default-ஆ disabled. Online services முன்பே deploy செய்யப்படவில்லை; owner console இந்த local package-ல் இல்லை. Licensing-ஐ தனியாக enable செய்தால் sodium extension மற்றும் உங்கள் server configuration தேவை.

## Error வந்தால்

**Desktop-ல் double-click செய்தால் PHP code காட்டுகிறது / run ஆகவில்லை:**
PHP file-ஐ நேரடியாக திறக்க வேண்டாம். `htdocs\efs` folder-ல் வைத்துவிட்டு `http://localhost/efs/` வழியாகத் திறக்கவும். Visual Studio version application run செய்வதை நிர்ணயிக்காது; Apache + PHP + MySQL தான் run செய்கின்றன.

**404 / Object not found:**
`C:\xampp\htdocs\efs\index.php` இருக்கிறதா பார்க்கவும். `htdocs\efs\efs\index.php` என double folder இருந்தால் URL `http://localhost/efs/efs/` ஆகிவிடும்; inner efs folder-ஐ நேராக htdocs-ல் வைப்பது எளிது.

**Apache 8080 port-ல் running:**
`http://localhost:8080/efs/` பயன்படுத்துங்கள். MySQL port வேறு setting; setup-ல் உங்கள் MySQL port-ஐ கொடுங்கள்.

**MySQL connect ஆகவில்லை:**
XAMPP MySQL running / host / port / root password சரிபார்க்கவும். Setup முடிந்தபின் password அல்லது port மாறினால் `config/database.php`-ல் மாற்றவும். Database-ஐ delete அல்லது overwrite செய்ய வேண்டாம்.

**Enable pdo_mysql:**
XAMPP Control Panel → Apache → Config → **PHP (php.ini)** → `extension=pdo_mysql` enabled ஆக இருக்க வேண்டும். அந்த line முன்னால் `;` இருந்தால் நீக்கி save → Apache Stop / Start.

**Setup says existing / incomplete database:**
புதிய பெயர் (`efs_station2`) கொடுங்கள் அல்லது உங்கள் existing EFS database-ஐ அதன் config மூலம் reconnect செய்யுங்கள். Setup existing tables-ஐ delete செய்யாது. Partial schema-ஐ மீண்டும் import செய்வதைத் தவிர்க்கவும்.

## Data, backup, editor

- MySQL data உங்கள் XAMPP MySQL data directory-ல் server மூலம் சேமிக்கப்படும்; application files-க்குள் sales database file உருவாகாது.
- Company / Settings → **Create backup** மூலம் SQL backup download செய்யலாம். Backup-ல் users, hashed passwords, business data உள்ளதால் private-ஆ வைத்திருங்கள்.
- Restore: புதிய empty database create செய்து backup `.sql`-ஐ phpMyAdmin import செய்யுங்கள்; பிறகு `config/database.php`-ல் அந்த database name கொடுங்கள். Live database மீது fresh schema அல்லது backup import செய்ய வேண்டாம்.
- Internet இல்லாமல் ஒரே PC-ல் Apache + MySQL running இருந்தால் பயன்படுத்தலாம். Server stopped இருந்தால் sales save ஆகாது; browser offline queue/service worker கிடையாது.
- Source files-ஐ VS Code அல்லது PHP-aware editor-ல் edit செய்யலாம். Visual Studio பழைய versions editor-ஆ மட்டும் பயன்படும்; PHP debugging support அதற்கான extension-ஐப் பொறுத்தது.
- Receipt / reports-ல் paper size மற்றும் printer output உங்கள் Windows printer settings-ல் verify செய்யுங்கள். இந்த package Windows installer `.exe` அல்ல; XAMPP-ல் run செய்யும் full source ZIP.
- இந்த edition localhost use-க்கு அமைக்கப்பட்டுள்ளது. Public hosting / LAN deployment செய்ய வேண்டுமென்றால் allowed hosts, HTTPS, Apache access rules மற்றும் database permissions தனியாக configure செய்ய வேண்டும்.
