<?php
declare(strict_types=1);
require __DIR__.'/TestDb.php';
require dirname(__DIR__).'/app/Station.php';
use Petrol\{Station,Problem};
use function Petrol\uid;
function poCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function poDenies(callable $fn,string $message):void {try{$fn();}catch(Problem $e){poCheck(true,$message);return;}throw new RuntimeException($message);}
$db=test_database();$s=new Station($db);
$u=$s->setup(['owner_name'=>'Owner','username'=>'owner','password'=>'Purchase-checks-2026','station_name'=>'Purchase tests']);
$c=$s->saveCategory(['code'=>'F','name'=>'Fuel'],$u);
$v=$s->saveVendor(['code'=>'V','name'=>'Vendor'],$u);
$bank=$s->createBank(['code'=>'B','name'=>'Current','bank_name'=>'Bank','opening_balance'=>'1000000'],$u);
$items=[];
foreach([['Petrol','2000','400','450'],['Diesel','1000','200','350'],['Kerosene Oil','250','275','300']] as $i=>[$name,$q,$cost,$sale]){
    $p=$s->saveProduct(['sku'=>(string)(10001+$i),'category_id'=>$c['id'],'name'=>$name,'quantity'=>'0','cost'=>$cost,'price'=>$sale],$u);
    $items[]=['product_id'=>$p['id'],'quantity'=>$q,'cost'=>$cost,'sale_price'=>$sale,'paid_amount'=>'0'];
}
$items[0]+=['bank_account_id'=>$bank['id']];$items[0]['paid_amount']='100';
$d=['request_key'=>uid(),'purchase_date'=>'2026-11-03','supplier_id'=>$v['id'],'items'=>$items];
$post=function($d)use($s,$u){return $s->workOnce('purchase_order_save',$d,$u,fn()=>$s->savePurchaseOrder($d,$u));};
$number=$post($d)['po'];poCheck($post($d)['po']===$number&&$number==='PO-0001','Retry returns the same server-assigned PO');
$report=$s->purchaseOrderReport(['po'=>'po-0001','from'=>'2026-11-03','to'=>'2026-11-03','vendor'=>$v['id']],$u);
poCheck(count($report['rows'])===1&&$report['rows'][0]['total_cents']===106875000,'Group total is Rs. 1,068,750 for the three specified item values');
poCheck(count($s->purchaseOrderReport(['from'=>'2026-11-04'],$u)['rows'])===0&&count($s->purchaseOrderReport(['vendor'=>'other'],$u)['rows'])===0,'Date and vendor filters exclude nonmatching orders');
poCheck($s->bankBalance($bank['id'])===99990000&&(int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$v['id']])===106865000,'Bank and vendor balances reflect the single payment');
$doc=fn()=>$s->purchaseOrderDetail(['po'=>$number],$u);
$order=$doc();
$edit=$d;$edit['request_key']=uid();$edit['po']=$number;$edit['version']=$order['version'];
foreach($order['rows'] as $i=>$r)$edit['items'][$i]['id']=$r['id'];
$bad=$edit;$bad['items'][0]['quantity']='1900';$bad['items'][2]['cost']='0';
poDenies(fn()=>$post($bad),'A bad later item rejects the entire edit');
poCheck($doc()['version']===$order['version']&&$s->find('products',$items[0]['product_id'])['stock_milli']===2000000,'Rejected edit rolls back stock and all order rows');
$edit['items'][1]['quantity']='1000.50';unset($edit['items'][2]);$edit['company_bill_no']='OWN-001';
$post($edit);poCheck($doc()['rows'][2]['voided_at']!==null&&$s->find('products',$items[2]['product_id'])['stock_milli']===0,'Removing a saved line reverses its stock');
$stale=$edit;$stale['request_key']=uid();poDenies(fn()=>$post($stale),'A stale order cannot overwrite an intervening edit');
$void=['po'=>$number,'version'=>$doc()['version'],'request_key'=>uid(),'void_all'=>true];$post($void);
poCheck($s->purchaseOrderReport(['po'=>$number],$u)['rows'][0]['total_cents']===0&&$s->bankBalance($bank['id'])===100000000,'Void reverses remaining stock and bank payment and excludes void value from report');
poDenies(fn()=>$s->purchaseOrderReport([],array_merge($u,['role'=>'cashier'])),'Purchase registers retain manager-only access');
