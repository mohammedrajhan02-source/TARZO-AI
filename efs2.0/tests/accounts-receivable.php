<?php
declare(strict_types=1);
// CLI only. TestDb creates and removes its own efs_check_* databases.
require __DIR__.'/TestDb.php';
require dirname(__DIR__).'/app/Station.php';
use Petrol\{Station,Problem};
use function Petrol\uid;
function arCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function arDeny(callable $fn,string $label):void {try{$fn();}catch(Problem $e){arCheck(true,$label);return;}throw new RuntimeException('FAIL accepted '.$label);}
try {
    $db=test_database();$s=new Station($db);
    $u=$s->setup(['owner_name'=>'Owner','username'=>'owner','password'=>'Receivables-test-2026','station_name'=>'Isolated AR tests']);
    arCheck((int)$db->value('SELECT MAX(version) FROM schema_migrations')===11,'Fresh database migrates to schema 11');
    $category=$s->saveCategory(['code'=>'ARF','name'=>'AR Fuel'],$u);
    $product=$s->saveProduct(['sku'=>'AR92','name'=>'Petrol','quantity'=>'1000','cost'=>'250','price'=>'400','category_id'=>$category['id']],$u);
    $pump=$s->savePump(['code'=>'AR1','name'=>'Pump 1','product_id'=>$product['id'],'start_meter'=>'0','end_meter'=>'0'],$u);
    $employee=$s->saveEmployee(['code'=>'ARD','name'=>'Day operator','shift_type'=>'day'],$u);
    $customer=$s->master('customer',['code'=>'ARC','name'=>'Transport','credit_limit'=>'200000','credit_days'=>30,'max_unpaid'=>50],$u);
    $day=$s->meterToday();
    $meter=['request_key'=>uid(),'sale_date'=>$day,'pump_id'=>$pump['id'],'employee_id'=>$employee['id'],'shift_type'=>'day','starting_meter'=>'0','closing_meter'=>'225','product_id'=>$product['id'],'price_cents'=>40000];
    $sale=$s->saveMeterSale($meter,$u);
    $beforeStock=$s->find('products',$product['id'])['stock_milli'];
    $beforeRevenue=(int)$db->value('SELECT SUM(total_cents) FROM sales');
    $load=fn()=>$s->arDay(['sale_date'=>$day,'pump_id'=>$pump['id']],$u);
    $a=$load();arCheck((int)$a['total_cents']===9000000,'Pump source supplies 90,000');
    $payload=['request_key'=>uid(),'sale_date'=>$day,'pump_id'=>$pump['id'],'source_version'=>$a['source_version'],'version'=>0,'cash'=>'60000','card'=>'20000','credit'=>'10000'];
    $allocate=function($d,$user=null)use($s,$u){$user=$user??$u;return $s->workOnce('ar_allocation_save',$d,$user,fn()=>$s->arSaveAllocation($d,$user));};
    $saved=$allocate($payload);
    arCheck((int)$saved['allocation']['difference_cents']===0,'Saved allocation balances');
    arCheck($allocate($payload)===$saved&&(int)$db->value('SELECT COUNT(*) FROM ar_allocations')===1,'Allocation retry is idempotent');
    arCheck((int)$db->value('SELECT SUM(amount_cents) FROM sale_payments WHERE sale_id=?',[$sale['id']])===9000000,'Payments classify original sale');
    $stale=$payload;$stale['request_key']=uid();arDeny(fn()=>$allocate($stale),'Stale allocation cannot overwrite saved version');
    $bad=$payload;$bad['request_key']=uid();$bad['version']=1;$bad['card']='85000';arDeny(fn()=>$allocate($bad),'Noncash above source is rejected');
    $bad['card']='20000';$bad['cash']='-1';arDeny(fn()=>$allocate($bad),'Negative allocation is rejected');
    $viewerPayload=$payload;$viewerPayload['request_key']=uid();
    arDeny(fn()=>$allocate($viewerPayload,array_merge($u,['role'=>'viewer'])),'Viewer cannot write allocations');
    arDeny(fn()=>$s->collectMeterCash(['id'=>$sale['id'],'amount'=>'1','reference'=>'NO'],$u),'Legacy collection cannot double-collect allocated sales');
    $meter['request_key']=uid();$meter['starting_meter']='225';$meter['closing_meter']='226';arDeny(fn()=>$s->saveMeterSale($meter,$u),'Source is locked after allocation');
    $invoice=['request_key'=>uid(),'sale_date'=>$day,'customer_id'=>$customer['id'],'invoice_no'=>'CR-001','lines'=>[
        ['pump_id'=>$pump['id'],'product_id'=>$product['id'],'liters'=>'10','price'=>'400'],
        ['pump_id'=>$pump['id'],'product_id'=>$product['id'],'liters'=>'15','price'=>'400']
    ]];
    $post=function($d)use($s,$u){return $s->workOnce('ar_invoice_save',$d,$u,fn()=>$s->arSaveInvoice($d,$u));};
    $bill=$post($invoice);
    arCheck(count($bill['lines'])===2&&(int)$bill['total_cents']===1000000,'Two products saved as one 10,000 bill');
    arCheck($post($invoice)===$bill&&(int)$db->value('SELECT COUNT(*) FROM ar_invoices')===1,'Invoice retry creates no duplicate debt');
    arCheck((int)$db->value('SELECT balance_cents FROM customer_balances WHERE id=?',[$customer['id']])===1000000,'Customer debt is 10,000');
    arCheck($s->find('products',$product['id'])['stock_milli']===$beforeStock&&(int)$db->value('SELECT SUM(total_cents) FROM sales')===$beforeRevenue,'Invoice does not repeat revenue or stock movement');
    arCheck((int)$load()['credit_remaining_cents']===0,'Credit remaining is separate and exhausted');
    $bad=$invoice;$bad['request_key']=uid();$bad['invoice_no']='CR-002';arDeny(fn()=>$post($bad),'Further invoice cannot exceed saved credit');
    $bad['invoice_no']='cr-001';arDeny(fn()=>$post($bad),'Invoice number uniqueness is case insensitive');
    $bad=$payload;$bad['request_key']=uid();$bad['version']=1;$bad['credit']='9000';arDeny(fn()=>$allocate($bad),'Allocation cannot fall below invoiced credit');
    $edit=$invoice;$edit['request_key']=uid();$edit['id']=$bill['id'];$edit['version']=1;$edit['lines'][1]['liters']='10';
    $edited=$post($edit);arCheck((int)$edited['total_cents']===800000&&(int)$load()['credit_remaining_cents']===200000,'Unpaid edit updates one debit and releases credit capacity');
    $edit['request_key']=uid();arDeny(fn()=>$post($edit),'Stale invoice edit rejected');
    $bank=$s->createBank(['code'=>'ARB','name'=>'AR bank','bank_name'=>'Bank','opening_balance'=>'0'],$u);
    $s->customerReceipt(['customer_id'=>$customer['id'],'amount'=>'1000','method'=>'bank','bank_account_id'=>$bank['id'],'reference'=>'CR-PAY'],$u);
    arCheck((int)$db->value('SELECT balance_cents FROM customer_balances WHERE id=?',[$customer['id']])===700000,'Existing collection workflow reduces new invoice debt');
    $edit['version']=2;$edit['request_key']=uid();arDeny(fn()=>$post($edit),'Paid invoice cannot be rewritten');
    arCheck((int)$db->value('SELECT SUM(total_cents) FROM sales')===$beforeRevenue,'Receipt never duplicates sales revenue');
    echo "Accounts Receivable integration checks passed.\n";
} catch(Throwable $e) {fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
