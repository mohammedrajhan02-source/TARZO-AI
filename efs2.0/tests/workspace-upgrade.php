<?php
declare(strict_types=1);
require __DIR__.'/TestDb.php';
require dirname(__DIR__).'/app/Station.php';
use Petrol\{Db,Station,Problem};
use function Petrol\{uid,stamp};
if(($argv[1]??'')==='--purchase-worker'){
    $job=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $db=new Db($job['config']);$station=new Station($db);
    while(microtime(true)<$job['start'])usleep(1000);
    try{echo json_encode($station->purchase($job['data'],$job['user']),JSON_THROW_ON_ERROR);}
    catch(Throwable $e){fwrite(STDERR,(string)$e);exit(1);}exit;
}
$count=0;
function upgraded(bool $condition,string $label): void {global $count;if(!$condition)throw new RuntimeException('FAIL: '.$label);$count++;echo 'PASS '.$label.PHP_EOL;}
function upgrade_reject(callable $fn,string $label): void {try{$fn();}catch(Throwable $e){if(!$e instanceof Problem&&!$e instanceof PDOException)throw $e;upgraded(true,$label);return;}throw new RuntimeException('FAIL accepted: '.$label);}
try{
    $db=test_database();$s=new Station($db);
    $u=$s->setup(['owner_name'=>'Owner','username'=>'owner','password'=>'Workspace-upgrade-2026','station_name'=>'Workspace upgrade','demo'=>false]);
    upgraded((int)$db->value('SELECT MAX(version) FROM schema_migrations')===10,'Fresh installation uses schema 10');
    $category=$s->saveCategory(['code'=>'LUB','name'=>'Lubricants'],$u);
    upgraded($category['code']==='LUB'&&$category['name']==='Lubricants','Category code and name persist');
    upgrade_reject(function()use($s,$u){$s->saveCategory(['code'=>'lub','name'=>'Duplicate'],$u);},'Category codes are unique ignoring case');
    $viewer=array_merge($u,['role'=>'viewer']);
    upgrade_reject(function()use($s,$viewer){$s->saveCategory(['code'=>'NO','name'=>'Blocked'],$viewer);},'Viewer cannot create categories');
    $product=$s->saveProduct(['sku'=>'OIL-01','name'=>'Engine oil','quantity'=>'5','cost'=>'100.25','price'=>'150.75','category_id'=>$category['id']],$u);
    upgraded($product['stock_milli']===5000&&$product['cost_cents']===10025&&$product['price_cents']===15075,'Product quantities and prices use exact integer storage');
    upgraded($product['category_id']===$category['id'],'Product references the saved category');
    upgrade_reject(function()use($s,$u){$s->saveProduct(['sku'=>'NO','name'=>'Invalid','quantity'=>'1','cost'=>'1','price'=>'2','category_id'=>'missing'],$u);},'Product rejects an unknown category');
    upgrade_reject(function()use($s,$u,$category){$s->saveProduct(['sku'=>'FRACTION','name'=>'Invalid','quantity'=>'1.5','cost'=>'1','price'=>'2','category_id'=>$category['id']],$u);},'Packaged product registration rejects fractional units');
    $employee=$s->saveEmployee(['code'=>'EMP-01','name'=>'Operator','address'=>'Valachchenai','shift_type'=>'night','phone'=>'0771234567'],$u);
    upgraded($employee['address']==='Valachchenai'&&$employee['shift_type']==='night'&&$employee['phone']==='0771234567','Employee address, Day/Night choice and contact persist');
    upgrade_reject(function()use($s,$u){$s->saveEmployee(['code'=>'BAD','name'=>'Invalid','shift_type'=>'afternoon'],$u);},'Employee shift accepts only Day or Night');
    $vendor=$s->saveVendor(['code'=>'V001','name'=>'Fuel supplier','current_balance'=>'1200.50','address'=>'Main Street','city'=>'Batticaloa'],$u);
    upgraded($vendor['balance_cents']===120050&&$vendor['code']==='V001'&&$vendor['city']==='Batticaloa','Vendor code, address, city and opening current balance persist');
    $statement=$s->ledgerStatement(['kind'=>'vendor','id'=>$vendor['id'],'from'=>'2000-01-01','to'=>'2099-12-31'],$u);
    upgraded($statement['closing_cents']===120050&&count($statement['rows'])===1,'Vendor opening balance is included in the statement');
    $pump=$s->savePump(['code'=>'P1001','name'=>'Pump 1','description'=>'Petrol','product_id'=>$product['id'],'start_meter'=>'100.125','end_meter'=>'120.250'],$u);
    upgraded($pump['start_meter_ml']===100125&&$pump['end_meter_ml']===120250&&$pump['description']==='Petrol','Pump registration meter range and description persist');
    upgrade_reject(function()use($s,$u){$s->savePump(['code'=>'BAD','name'=>'Bad','description'=>'Diesel','start_meter'=>'10','end_meter'=>'9'],$u);},'Pump rejects an end meter below its start meter');
    foreach([['D1001','Pump 2','Diesel'],['K1001','Pump 3','Kerosene'],['P1002','Pump 4','Petrol']] as $p)$s->savePump(['code'=>$p[0],'name'=>$p[1],'description'=>$p[2],'product_id'=>$product['id'],'start_meter'=>'0','end_meter'=>'0'],$u);
    upgraded((int)$db->value('SELECT COUNT(*) FROM pumps')===4,'Additional pumps can be registered without a fixed three-pump limit');
    $preview=$s->nextPurchaseNumber();
    $data=['request_key'=>uid(),'kind'=>'product','supplier_id'=>$vendor['id'],'company_bill_no'=>'COMP-001','purchase_date'=>'2026-10-25','product_id'=>$product['id'],'quantity'=>'2','cost'=>'110','paid_amount'=>'0','con_bill_no'=>'FORGED'];
    $purchase=$s->workOnce('purchase',$data,$u,function()use($s,$data,$u){return $s->purchase($data,$u);});
    upgraded($purchase['con_bill_no']===$preview&&$purchase['con_bill_no']!=='FORGED','Con Bill No is assigned by the server');
    upgraded($purchase['company_bill_no']==='COMP-001'&&$purchase['purchase_date']==='2026-10-25','Company Bill No and selected date persist');
    $again=$s->workOnce('purchase',$data,$u,function()use($s,$data,$u){return $s->purchase($data,$u);});
    upgraded($again['id']===$purchase['id']&&$s->find('products',$product['id'])['stock_milli']===7000,'Purchase retry returns the same document without receiving stock twice');
    $next=$s->nextPurchaseNumber();
    upgrade_reject(function()use($s,$data,$u){$s->purchase($data,$u);},'Duplicate Company Bill No for the same vendor is rejected');
    upgraded($s->nextPurchaseNumber()===$next,'Failed purchase does not consume a Con Bill number');
    upgrade_reject(function()use($s,$data,$u){$s->purchase(array_merge($data,['company_bill_no'=>'BAD-DATE','purchase_date'=>'2026-02-30']),$u);},'Purchasing rejects an impossible date');
    upgraded($s->find('products',$product['id'])['stock_milli']===7000,'Failed purchases leave product stock unchanged');
    $vendor=$s->find('supplier_balances',$vendor['id']);
    upgraded($vendor['balance_cents']===142050,'Purchase payable is added to the vendor opening balance');
    $statement=$s->ledgerStatement(['kind'=>'vendor','id'=>$vendor['id'],'from'=>'2000-01-01','to'=>'2099-12-31'],$u);
    upgraded($statement['closing_cents']===$vendor['balance_cents'],'Vendor statement and Current Balance agree after purchasing');
    $snapshot=$s->snapshot($u);
    upgraded(count($snapshot['categories'])===1&&$snapshot['products'][0]['category_name']==='Lubricants','Snapshot provides saved categories and joined product category names');
    upgraded($snapshot['suppliers'][0]['code']==='V001'&&$snapshot['product_purchases'][0]['con_bill_no']===$purchase['con_bill_no'],'Snapshot provides vendor codes and purchase document metadata');
    $edit=['request_key'=>uid(),'id'=>$purchase['id'],'kind'=>'product','purchase_date'=>'2026-10-25','company_bill_no'=>'COMP-001','supplier_id'=>$vendor['id'],'product_id'=>$product['id'],'quantity'=>'3','cost'=>'110','paid_amount'=>'0','notes'=>'Corrected quantity'];
    $updated=$s->workOnce('purchase_update',$edit,$u,fn()=>$s->updatePurchase($edit,$u));
    upgraded($updated['id']===$purchase['id']&&$s->find('products',$product['id'])['stock_milli']===8000,'Product purchase edit updates the existing record and reconciles stock once');
    upgraded((int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$vendor['id']])===153050,'Product purchase edit recalculates vendor payable');
    $void=['request_key'=>uid(),'id'=>$purchase['id'],'kind'=>'product'];
    $voided=$s->workOnce('purchase_void',$void,$u,fn()=>$s->voidPurchase($void,$u));
    upgraded($voided['voided']&&$s->find('products',$product['id'])['stock_milli']===5000,'Voiding a product purchase reverses stock without deleting the record');
    upgraded((int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$vendor['id']])===120050&&(int)$db->value('SELECT COUNT(*) FROM product_purchases WHERE id=? AND voided_at IS NOT NULL',[$purchase['id']])===1,'Voided purchase remains in history and is excluded from vendor balance');
    $voidStatement=$s->ledgerStatement(['kind'=>'vendor','id'=>$vendor['id'],'from'=>'2000-01-01','to'=>'2099-12-31'],$u);
    upgraded($voidStatement['closing_cents']===120050,'Vendor statement excludes voided purchasing records consistently with the balance view');
    upgrade_reject(fn()=>$s->voidPurchase($void,$u),'A voided purchase cannot be voided twice');

    $bank=$s->createBank(['code'=>'PURCHASE','name'=>'Purchasing account','bank_name'=>'Test bank','opening_balance'=>'10'],$u);
    $paid=$s->productPurchase(['supplier_id'=>$vendor['id'],'product_id'=>$product['id'],'invoice_no'=>'COMP-PAID','quantity'=>'1','cost'=>'100','paid_amount'=>'0.50','bank_account_id'=>$bank['id']],$u);
    $paidEdit=['request_key'=>uid(),'id'=>$paid['id'],'kind'=>'product','purchase_date'=>$db->value('SELECT purchase_date FROM purchase_invoices WHERE purchase_id=?',[$paid['id']]),'company_bill_no'=>'COMP-PAID','supplier_id'=>$vendor['id'],'product_id'=>$product['id'],'quantity'=>'1','cost'=>'100','paid_amount'=>'0.75','bank_account_id'=>$bank['id'],'notes'=>'Updated payment'];
    $s->workOnce('purchase_update',$paidEdit,$u,fn()=>$s->updatePurchase($paidEdit,$u));
    upgraded($s->bankBalance($bank['id'])===925&&(int)$db->value('SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id=?',[$bank['id']])===4,'Editing a paid purchase reverses and reposts bank payment without deleting ledger entries');
    $paidEditAgain=array_merge($paidEdit,['request_key'=>uid(),'paid_amount'=>'0.80']);
    $s->workOnce('purchase_update',$paidEditAgain,$u,fn()=>$s->updatePurchase($paidEditAgain,$u));
    upgraded($s->bankBalance($bank['id'])===920&&(int)$db->value('SELECT COUNT(*) FROM bank_transactions WHERE purchase_id=?',[$paid['id']])===5,'Repeated payment edits remain linked to one purchase and reconcile cumulatively');
    $bankBeforeVoid=$s->bankBalance($bank['id']);
    $paidVoid=['request_key'=>uid(),'id'=>$paid['id'],'kind'=>'product'];
    $s->workOnce('purchase_void',$paidVoid,$u,fn()=>$s->voidPurchase($paidVoid,$u));
    upgraded($s->bankBalance($bank['id'])===$bankBeforeVoid+80&&(int)$db->value('SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id=?',[$bank['id']])===7,'Voiding a paid purchase posts a bank reversal and retains all bank entries');
    upgraded((int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$vendor['id']])===120050,'Voiding a paid purchase reverses its payable');

    $matched=$s->productPurchase(['supplier_id'=>$vendor['id'],'product_id'=>$product['id'],'invoice_no'=>'COMP-MATCHED','quantity'=>'1','cost'=>'100','paid_amount'=>'0.25','bank_account_id'=>$bank['id']],$u);
    $db->run('UPDATE bank_transactions SET matched_at=?,matched_by=?,statement_ref=? WHERE purchase_id=?',[stamp(),$u['id'],'MATCHED-PURCHASE',$matched['id']]);
    $matchedEdit=['id'=>$matched['id'],'kind'=>'product','purchase_date'=>$db->value('SELECT purchase_date FROM purchase_invoices WHERE purchase_id=?',[$matched['id']]),'company_bill_no'=>'COMP-MATCHED','supplier_id'=>$vendor['id'],'product_id'=>$product['id'],'quantity'=>'1','cost'=>'100','paid_amount'=>'0.50','bank_account_id'=>$bank['id']];
    upgrade_reject(fn()=>$s->updatePurchase($matchedEdit,$u),'Matched purchase bank payment cannot be edited');

    $fuel=$s->master('fuel',['code'=>'F92','name'=>'Test petrol','price'=>'200','color'=>'#27cdb4'],$u);
    $tank=$s->master('tank',['code'=>'T-EDIT','name'=>'Edit tank','fuel_type_id'=>$fuel['id'],'capacity_liters'=>'100','opening_liters'=>'0','cost_per_liter'=>'0'],$u);
    $delivery=$s->delivery(['tank_id'=>$tank['id'],'supplier_id'=>$vendor['id'],'invoice_no'=>'FUEL-EDIT','liters'=>'10','cost_per_liter'=>'100','paid_amount'=>'0'],$u);
    $deliveryEdit=['request_key'=>uid(),'id'=>$delivery['id'],'kind'=>'fuel','purchase_date'=>date('Y-m-d'),'company_bill_no'=>'FUEL-EDIT','supplier_id'=>$vendor['id'],'tank_id'=>$tank['id'],'liters'=>'12','cost_per_liter'=>'100','paid_amount'=>'0','notes'=>'Corrected delivery'];
    $s->workOnce('purchase_update',$deliveryEdit,$u,fn()=>$s->updatePurchase($deliveryEdit,$u));
    upgraded($s->balance($tank['id'])===12000,'Fuel delivery edit reconciles the tank stock movement');
    $deliveryVoid=['request_key'=>uid(),'id'=>$delivery['id'],'kind'=>'fuel'];
    $s->workOnce('purchase_void',$deliveryVoid,$u,fn()=>$s->voidPurchase($deliveryVoid,$u));
    upgraded($s->balance($tank['id'])===0&&(int)$db->value('SELECT COUNT(*) FROM deliveries WHERE id=? AND voided_at IS NOT NULL',[$delivery['id']])===1,'Voiding a fuel delivery reverses tank stock and retains its history');

    $salePump=$s->master('pump',['code'=>'SALE-PUMP','name'=>'Sale pump','product_id'=>$product['id']],$u);
    $saleNozzle=$s->master('nozzle',['code'=>'SALE-NOZZLE','pump_id'=>$salePump['id'],'tank_id'=>$tank['id'],'meter_liters'=>'0'],$u);
    $shift=$s->openShift(['label'=>'Purchase safety','nozzle_ids'=>[$saleNozzle['id']]],$u);
    $unsafePurchase=$s->productPurchase(['supplier_id'=>$vendor['id'],'product_id'=>$product['id'],'invoice_no'=>'UNSAFE-EDIT','quantity'=>'1','cost'=>'100','paid_amount'=>'0'],$u);
    $s->sale(['idempotency_key'=>uid(),'shift_id'=>$shift['id'],'items'=>[['kind'=>'product','product_id'=>$product['id'],'quantity'=>'1']],'payments'=>[['method'=>'cash','amount'=>'150.75']]],$u);
    $unsafeEdit=['id'=>$unsafePurchase['id'],'kind'=>'product','purchase_date'=>$db->value('SELECT purchase_date FROM purchase_invoices WHERE purchase_id=?',[$unsafePurchase['id']]),'company_bill_no'=>'UNSAFE-EDIT','supplier_id'=>$vendor['id'],'product_id'=>$product['id'],'quantity'=>'2','cost'=>'100','paid_amount'=>'0'];
    upgrade_reject(fn()=>$s->updatePurchase($unsafeEdit,$u),'Purchase edit is rejected after its stock has been sold');
    upgrade_reject(fn()=>$s->voidPurchase(['id'=>$unsafePurchase['id'],'kind'=>'product'],$u),'Purchase void is rejected after its stock has been sold');

    upgrade_reject(function()use($s,$viewer,$data){$s->purchase(array_merge($data,['company_bill_no'=>'VIEWER']),$viewer);},'Viewer cannot create a purchase');
    $jobs=[];$start=microtime(true)+0.25;$configuration=test_last_config();
    for($i=0;$i<2;$i++){
        $arguments=[PHP_BINARY];if(php_ini_loaded_file()){$arguments[]='-c';$arguments[]=php_ini_loaded_file();}$arguments[]=__FILE__;$arguments[]='--purchase-worker';
        $process=proc_open($arguments,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Cannot start purchase worker.');
        $payload=['config'=>$configuration,'user'=>$u,'start'=>$start,'data'=>array_merge($data,['company_bill_no'=>'CONCURRENT-'.$i,'quantity'=>'1'])];
        fwrite($pipes[0],json_encode($payload,JSON_THROW_ON_ERROR));fclose($pipes[0]);$jobs[]=[$process,$pipes];
    }
    $documents=[];foreach($jobs as $job){[$process,$pipes]=$job;$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Purchase worker failed: '.$error);$documents[]=json_decode($output,true,16,JSON_THROW_ON_ERROR);}
    upgraded(count(array_unique(array_column($documents,'con_bill_no')))===2,'Concurrent purchases receive distinct system-generated Con Bill numbers');
    upgraded((int)$db->value('SELECT COUNT(*) FROM purchase_invoices')===7&&$s->find('products',$product['id'])['stock_milli']===8000,'Concurrent purchases each receive stock exactly once');
    // A real schema-3 database must gain fields without discarding owner or stock.
    $pdo=test_database_connection();$legacyConfig=test_last_config();
    $pdo->exec(file_get_contents(__DIR__.'/fixtures/schema-3.sql'));
    $pdo->exec("INSERT INTO users(id,username,name,password_hash,role,created_at) VALUES('legacy-owner','legacy','Legacy owner','preserved-hash','admin','2026-01-01T00:00:00Z'); INSERT INTO settings(`key`,value) VALUES('station_name','Preserved station'); INSERT INTO suppliers(id,name,created_at) VALUES('legacy-vendor','Existing supplier','2026-01-01T00:00:00Z'); INSERT INTO products(id,sku,name,price_cents,cost_cents,stock_milli) VALUES('legacy-product','OLD','Existing oil',15000,10000,8000)");
    $pdo->exec("INSERT INTO fuel_types(id,code,name) VALUES('legacy-fuel','P92','Petrol'); INSERT INTO tanks(id,code,name,fuel_type_id,capacity_ml) VALUES('legacy-tank','T1','Tank 1','legacy-fuel',1000000); INSERT INTO pumps(id,code,name) VALUES('legacy-pump','P1','Pump 1'); INSERT INTO nozzles(id,code,pump_id,tank_id,last_meter_ml) VALUES('legacy-nozzle','N1','legacy-pump','legacy-tank',10000); INSERT INTO shifts(id,label,user_id,opened_at,closed_at,opening_cash_cents,status) VALUES('legacy-shift','Old shift','legacy-owner','2026-01-01T00:00:00Z','2026-01-01T12:00:00Z',0,'closed'); INSERT INTO sales(id,idempotency_key,receipt_no,shift_id,total_cents,cost_cents,created_at,created_by) VALUES('legacy-sale','legacy-request','OLD-RECEIPT','legacy-shift',20000,10000,'2026-01-01T06:00:00Z','legacy-owner'); INSERT INTO sale_items(id,sale_id,kind,nozzle_id,description,quantity_milli,unit_price_cents,line_cents,cost_cents) VALUES('legacy-item','legacy-sale','fuel','legacy-nozzle','Petrol',1000,20000,20000,10000); INSERT INTO sale_payments(id,sale_id,method,amount_cents) VALUES('legacy-payment','legacy-sale','cash',20000); INSERT INTO deliveries(id,tank_id,supplier_id,invoice_no,quantity_ml,cost_cents_per_l,total_cents,paid_cents,received_at,created_by) VALUES('legacy-delivery','legacy-tank','legacy-vendor','OLD-FUEL',5000,10000,50000,10000,'2026-01-01T00:00:00Z','legacy-owner'); INSERT INTO product_purchases(id,supplier_id,product_id,invoice_no,quantity_milli,cost_cents,total_cents,received_at,created_by) VALUES('legacy-purchase','legacy-vendor','legacy-product','OLD-PRODUCT',2000,10000,20000,'2026-01-01T00:00:00Z','legacy-owner'); INSERT INTO purchase_invoices VALUES('legacy-vendor','OLD-FUEL','legacy-delivery','fuel'),('legacy-vendor','OLD-PRODUCT','legacy-purchase','product'); INSERT INTO supplier_payments(id,supplier_id,amount_cents,reference,created_at,created_by) VALUES('legacy-vendor-payment','legacy-vendor',5000,'OLD-PAY','2026-01-01T01:00:00Z','legacy-owner'); INSERT INTO audit_logs(id,user_id,action,entity_type,entity_id,details_json,created_at) VALUES('legacy-audit','legacy-owner','post','sale','legacy-sale','{}','2026-01-01T06:00:00Z')");
    $pdo->exec("INSERT INTO bank_accounts(id,code,name,bank_name,created_at) VALUES('legacy-bank','OLD-BANK','Old bank','Test bank','2026-01-01T00:00:00Z'); INSERT INTO bank_transactions(id,bank_account_id,amount_cents,kind,source_type,source_id,reference,occurred_at,created_by) VALUES('legacy-purchase-payment','legacy-bank',-10000,'purchase_payment','fuel_purchase','legacy-delivery','OLD-FUEL','2026-01-01T00:00:00Z','legacy-owner')");
    $backupDir=sys_get_temp_dir().'/efs-migration-'.uid();mkdir($backupDir,0700,true);$legacyConfig['backup_dir']=$backupDir;
    $migrated=new Db($legacyConfig);
    upgraded((int)$migrated->value('SELECT MAX(version) FROM schema_migrations')===10,'Schema 3 automatically migrates to schema 10');
    upgraded($migrated->setting('station_name')==='Preserved station'&&$migrated->value('SELECT password_hash FROM users')==='preserved-hash','Migration preserves existing station and owner credentials');
    upgraded((int)$migrated->value('SELECT stock_milli FROM products')===8000&&$migrated->value('SELECT category_id FROM products')!==null,'Migration preserves stock and categorises existing products');
    upgraded($migrated->value('SELECT code FROM suppliers')==='V-legacy-vendor','Migration assigns a unique code to an existing vendor');
    upgraded((int)$migrated->value('SELECT SUM(total_cents) FROM sales')===20000&&(int)$migrated->value('SELECT COUNT(*) FROM sale_payments')===1,'Migration preserves posted sales and payment totals');
    upgraded((int)$migrated->value('SELECT balance_cents FROM supplier_balances')===55000,'Migration preserves existing vendor payables');
    upgraded((int)$migrated->value('SELECT last_meter_ml FROM nozzles')===10000&&(int)$migrated->value('SELECT COUNT(*) FROM audit_logs')===1,'Migration preserves meter values and the audit trail');
    upgraded((int)$migrated->value('SELECT COUNT(*) FROM purchase_invoices WHERE con_bill_no IS NOT NULL AND purchase_date IS NOT NULL')===2,'Migration backfills document numbers and dates for both historical purchase types');
    upgraded((int)$migrated->value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_purchases' AND COLUMN_NAME='voided_at'")===1,'Migration adds reversible void metadata without replacing purchase rows');
    upgraded($migrated->value('SELECT purchase_id FROM bank_transactions WHERE id=?',['legacy-purchase-payment'])==='legacy-delivery','Migration links historical bank payments to their purchases');
    $backups=glob($backupDir.'/*.sql');upgraded(count($backups)===7,'Each schema upgrade creates a private SQL backup before changing schema');
    $oldBackup=test_database_connection();$originalBackup=glob($backupDir.'/EFS-before-workspace-4-*.sql')[0];$oldBackup->exec(file_get_contents($originalBackup));
    upgraded((int)$oldBackup->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()===3,'Pre-upgrade SQL backup restores the original schema');
    $again=new Db($legacyConfig);
    upgraded(count(glob($backupDir.'/*.sql'))===7&&(int)$again->value('SELECT COUNT(*) FROM categories')===1,'Reopening upgraded data does not repeat migration or create duplicate categories');
    echo PHP_EOL.$count.' Work Space upgrade checks passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
