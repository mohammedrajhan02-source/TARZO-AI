<?php
declare(strict_types=1);
namespace Petrol;

/** Additive, restartable upgrade. DDL commits in MySQL; keep a full backup first. */
function upgrade_workspace_4(Db $db,array $config): void {
    $database=(string)$db->value('SELECT DATABASE()');
    $lock='efs-schema-'.substr(hash('sha256',$database),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another EFS database upgrade is running. Refresh after it finishes.',503);
    try{
        if((int)$db->value('SELECT MAX(version) FROM schema_migrations')>=4)return;
        $folder=(string)($config['backup_dir']??dirname(__DIR__).'/var/backups');
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create the pre-upgrade backup folder. Check var/backups permissions.',500);
        $db->backup($folder.'/EFS-before-workspace-4-'.substr(hash('sha256',$database),0,10).'-'.gmdate('Ymd-His').'-'.substr(uid(),0,6).'.sql');
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS categories (id VARCHAR(64) NOT NULL PRIMARY KEY,code VARCHAR(32) COLLATE utf8mb4_general_ci NOT NULL UNIQUE,name VARCHAR(160) NOT NULL,created_at VARCHAR(32) NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $columns=[
            'products'=>['category_id'=>'VARCHAR(64) NULL'],
            'pumps'=>['description'=>"VARCHAR(400) NOT NULL DEFAULT ''",'start_meter_ml'=>'BIGINT NOT NULL DEFAULT 0','end_meter_ml'=>'BIGINT NOT NULL DEFAULT 0'],
            'employees'=>['address'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'shift_type'=>"VARCHAR(8) NOT NULL DEFAULT 'day'"],
            'suppliers'=>['code'=>'VARCHAR(64) COLLATE utf8mb4_general_ci NULL','address'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'city'=>"VARCHAR(160) NOT NULL DEFAULT ''",'opening_balance_cents'=>'BIGINT NOT NULL DEFAULT 0'],
            'purchase_invoices'=>['con_bill_no'=>'VARCHAR(80) NULL','purchase_date'=>'CHAR(10) NULL'],
        ];
        foreach($columns as $table=>$items)foreach($items as $name=>$definition){
            if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',[$database,$table,$name]))$db->pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$name.'` '.$definition);
        }

        if((int)$db->value('SELECT COUNT(*) FROM products WHERE category_id IS NULL')>0){
            $category=$db->one('SELECT id FROM categories WHERE code=?',['UNCAT']);
            $id=$category?$category['id']:uid();
            if(!$category)$db->insert('categories',['id'=>$id,'code'=>'UNCAT','name'=>'Uncategorised','created_at'=>stamp()]);
            $db->run('UPDATE products SET category_id=? WHERE category_id IS NULL',[$id]);
        }
        foreach($db->all("SELECT id FROM suppliers WHERE code IS NULL OR code=''") as $vendor){
            $code='V-'.(strlen($vendor['id'])<=62?$vendor['id']:substr(hash('sha256',$vendor['id']),0,62));
            $db->run('UPDATE suppliers SET code=? WHERE id=?',[$code,$vendor['id']]);
        }
        $db->pdo->exec('ALTER TABLE suppliers MODIFY COLUMN code VARCHAR(64) COLLATE utf8mb4_general_ci NOT NULL');
        $indexes=['suppliers'=>['uq_supplier_code','code'],'purchase_invoices'=>['uq_purchase_con_bill','con_bill_no']];
        foreach($indexes as $table=>$index)if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',[$database,$table,$index[0]]))$db->pdo->exec('ALTER TABLE `'.$table.'` ADD UNIQUE KEY `'.$index[0].'` (`'.$index[1].'`)');
        if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=?',[$database,'fk_products_category']))$db->pdo->exec('ALTER TABLE products ADD CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)');
        $db->pdo->exec("UPDATE pumps p SET description=COALESCE((SELECT GROUP_CONCAT(DISTINCT f.name ORDER BY f.name SEPARATOR ', ') FROM nozzles n JOIN tanks t ON t.id=n.tank_id JOIN fuel_types f ON f.id=t.fuel_type_id WHERE n.pump_id=p.id),p.name) WHERE description=''");
        $db->pdo->exec('UPDATE pumps p SET start_meter_ml=COALESCE((SELECT MIN(n.last_meter_ml) FROM nozzles n WHERE n.pump_id=p.id),0),end_meter_ml=COALESCE((SELECT MAX(n.last_meter_ml) FROM nozzles n WHERE n.pump_id=p.id),0) WHERE start_meter_ml=0 AND end_meter_ml=0');
        $tz=new \DateTimeZone($db->setting('timezone','Asia/Colombo'));
        foreach($db->all('SELECT i.purchase_id,i.con_bill_no,i.purchase_date,COALESCE(d.received_at,p.received_at) received_at FROM purchase_invoices i LEFT JOIN deliveries d ON d.id=i.purchase_id LEFT JOIN product_purchases p ON p.id=i.purchase_id WHERE i.con_bill_no IS NULL OR i.purchase_date IS NULL') as $row){
            $day=(new \DateTimeImmutable($row['received_at']?:'now'))->setTimezone($tz)->format('Y-m-d');
            $db->run('UPDATE purchase_invoices SET con_bill_no=COALESCE(con_bill_no,?),purchase_date=COALESCE(purchase_date,?) WHERE purchase_id=?',['LEG-'.$row['purchase_id'],$day,$row['purchase_id']]);
        }
        $db->pdo->exec("CREATE OR REPLACE SQL SECURITY INVOKER VIEW supplier_balances AS SELECT s.*,s.opening_balance_cents+COALESCE((SELECT SUM(d.total_cents-d.paid_cents) FROM deliveries d WHERE d.supplier_id=s.id),0)+COALESCE((SELECT SUM(p.total_cents-p.paid_cents) FROM product_purchases p WHERE p.supplier_id=s.id),0)-COALESCE((SELECT SUM(p.amount_cents) FROM supplier_payments p WHERE p.supplier_id=s.id),0) balance_cents FROM suppliers s");
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(4,?) ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at)',[stamp()]);
    }finally{$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function upgrade_workspace_5(Db $db,array $config): void {
    $database=(string)$db->value('SELECT DATABASE()');
    $lock='efs-schema-'.substr(hash('sha256',$database),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another EFS database upgrade is running. Refresh after it finishes.',503);
    try{
        $version=(int)$db->value('SELECT MAX(version) FROM schema_migrations');
        if($version>=5)return;
        if($version!==4)throw new Problem('The EFS workspace upgrade cannot run from this database version.',500);
        $folder=(string)($config['backup_dir']??dirname(__DIR__).'/var/backups');
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create the pre-upgrade backup folder. Check var/backups permissions.',500);
        $db->backup($folder.'/EFS-before-workspace-5-'.substr(hash('sha256',$database),0,10).'-'.gmdate('Ymd-His').'-'.substr(uid(),0,6).'.sql');
        foreach(['deliveries','product_purchases'] as $table){
            foreach([
                'voided_at'=>'VARCHAR(32) NULL',
                'voided_by'=>'VARCHAR(64) NULL',
                'void_reason'=>"VARCHAR(1000) NOT NULL DEFAULT ''",
            ] as $name=>$definition){
                if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',[$database,$table,$name])){
                    $db->pdo->exec('ALTER TABLE `'.$table.'` ADD COLUMN `'.$name.'` '.$definition);
                }
            }
        }
        if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',[$database,'bank_transactions','purchase_id'])){
            $db->pdo->exec('ALTER TABLE bank_transactions ADD COLUMN purchase_id VARCHAR(64) NULL');
        }
        if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',[$database,'bank_transactions','idx_bank_purchase_id'])){
            $db->pdo->exec('ALTER TABLE bank_transactions ADD KEY idx_bank_purchase_id (purchase_id)');
        }
        $db->pdo->exec("UPDATE bank_transactions b JOIN deliveries d ON b.source_type='fuel_purchase' AND b.source_id=d.id SET b.purchase_id=d.id WHERE b.purchase_id IS NULL");
        $db->pdo->exec("UPDATE bank_transactions b JOIN product_purchases p ON b.source_type='product_purchase' AND b.source_id=p.id SET b.purchase_id=p.id WHERE b.purchase_id IS NULL");
        $db->pdo->exec("CREATE OR REPLACE SQL SECURITY INVOKER VIEW supplier_balances AS SELECT s.*,s.opening_balance_cents+COALESCE((SELECT SUM(d.total_cents-d.paid_cents) FROM deliveries d WHERE d.supplier_id=s.id AND d.voided_at IS NULL),0)+COALESCE((SELECT SUM(p.total_cents-p.paid_cents) FROM product_purchases p WHERE p.supplier_id=s.id AND p.voided_at IS NULL),0)-COALESCE((SELECT SUM(p.amount_cents) FROM supplier_payments p WHERE p.supplier_id=s.id),0) balance_cents FROM suppliers s");
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(5,?) ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at)',[stamp()]);
    }finally{$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}

/** Schema 6: additive vendor payable and cheque ledgers; old payments stay intact. */
function upgrade_accounts_payable_6(Db $db,array $config): void {
    $database=(string)$db->value('SELECT DATABASE()');
    $lock='efs-schema-'.substr(hash('sha256',$database),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another EFS database upgrade is running. Refresh after it finishes.',503);
    try{
        $version=(int)$db->value('SELECT MAX(version) FROM schema_migrations');
        if($version>=6)return;
        if($version!==5)throw new Problem('Accounts Payable upgrade requires schema 5.',500);
        $folder=(string)($config['backup_dir']??dirname(__DIR__).'/var/backups');
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create the pre-upgrade backup folder.',500);
        $db->backup($folder.'/EFS-before-payables-6-'.substr(hash('sha256',$database),0,10).'-'.gmdate('Ymd-His').'-'.substr(uid(),0,6).'.sql');
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS vendor_payables (
            _seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
            id VARCHAR(64) NOT NULL PRIMARY KEY,supplier_id VARCHAR(64) NOT NULL,description VARCHAR(400) NOT NULL,amount_cents BIGINT NOT NULL,
            occurred_at VARCHAR(32) NOT NULL,created_at VARCHAR(32) NOT NULL,created_by VARCHAR(64) NOT NULL,
            KEY idx_vendor_payables (supplier_id,occurred_at),
            FOREIGN KEY (supplier_id) REFERENCES suppliers(id),FOREIGN KEY (created_by) REFERENCES users(id),CHECK(amount_cents>0)
        ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS party_cheques (
            id VARCHAR(64) NOT NULL PRIMARY KEY,bank_name VARCHAR(160) COLLATE utf8mb4_general_ci NOT NULL,branch VARCHAR(120) COLLATE utf8mb4_general_ci NOT NULL,
            account_no VARCHAR(64) COLLATE utf8mb4_general_ci NOT NULL,cheque_no VARCHAR(64) COLLATE utf8mb4_general_ci NOT NULL,cheque_date CHAR(10) NOT NULL,
            holder VARCHAR(160) NOT NULL,source_reference VARCHAR(160) NOT NULL,amount_cents BIGINT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'available',
            endorsed_at VARCHAR(32) NULL,supplier_id VARCHAR(64) NULL,created_at VARCHAR(32) NOT NULL,created_by VARCHAR(64) NOT NULL,
            UNIQUE KEY uq_party_cheque (bank_name,branch,account_no,cheque_no),FOREIGN KEY (supplier_id) REFERENCES suppliers(id),FOREIGN KEY (created_by) REFERENCES users(id),CHECK(amount_cents>0)
        ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $columns=['method'=>"VARCHAR(24) NOT NULL DEFAULT 'bank'",'occurred_at'=>'VARCHAR(32) NULL','shift_id'=>'VARCHAR(64) NULL','cheque_no'=>'VARCHAR(64) COLLATE utf8mb4_general_ci NULL','cheque_date'=>'CHAR(10) NULL','cheque_bank'=>"VARCHAR(160) NOT NULL DEFAULT ''",'cheque_branch'=>"VARCHAR(120) NOT NULL DEFAULT ''",'cheque_account'=>"VARCHAR(64) NOT NULL DEFAULT ''",'cheque_holder'=>"VARCHAR(160) NOT NULL DEFAULT ''",'party_cheque_id'=>'VARCHAR(64) NULL'];
        foreach($columns as $name=>$definition)if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?',[$database,'supplier_payments',$name]))$db->pdo->exec('ALTER TABLE supplier_payments ADD COLUMN `'.$name.'` '.$definition);
        $db->run('UPDATE supplier_payments SET occurred_at=created_at WHERE occurred_at IS NULL');
        foreach(['uq_own_cheque'=>'bank_account_id,cheque_no','uq_used_party_cheque'=>'party_cheque_id'] as $name=>$columns)if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',[$database,'supplier_payments',$name]))$db->pdo->exec('ALTER TABLE supplier_payments ADD UNIQUE KEY '.$name.' ('.$columns.')');
        foreach(['fk_vendor_payment_shift'=>['shift_id','shifts'],'fk_vendor_payment_party'=>['party_cheque_id','party_cheques']] as $name=>$fk)if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=?',[$database,$name]))$db->pdo->exec('ALTER TABLE supplier_payments ADD CONSTRAINT '.$name.' FOREIGN KEY ('.$fk[0].') REFERENCES '.$fk[1].'(id)');
        $db->pdo->exec("CREATE OR REPLACE SQL SECURITY INVOKER VIEW supplier_balances AS SELECT s.*,s.opening_balance_cents
            +COALESCE((SELECT SUM(d.total_cents-d.paid_cents) FROM deliveries d WHERE d.supplier_id=s.id AND d.voided_at IS NULL),0)
            +COALESCE((SELECT SUM(p.total_cents-p.paid_cents) FROM product_purchases p WHERE p.supplier_id=s.id AND p.voided_at IS NULL),0)
            +COALESCE((SELECT SUM(a.amount_cents) FROM vendor_payables a WHERE a.supplier_id=s.id),0)
            -COALESCE((SELECT SUM(p.amount_cents) FROM supplier_payments p WHERE p.supplier_id=s.id),0) balance_cents FROM suppliers s");
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(6,?) ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at)',[stamp()]);
    }finally{$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}
