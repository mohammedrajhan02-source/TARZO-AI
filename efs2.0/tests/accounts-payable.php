<?php
declare(strict_types=1);
require __DIR__.'/TestDb.php';require dirname(__DIR__).'/app/Station.php';
use Petrol\{Station,Db,Problem};use function Petrol\{uid,stamp};
if(($argv[1]??'')==='--worker'){
    $j=json_decode(stream_get_contents(STDIN),true);$s=new Station(new Db($j['config']));
    while(microtime(true)<$j['start'])usleep(1000);
    try{echo json_encode($s->workOnce('supplier_payment',$j['data'],$j['user'],fn()=>$s->supplierPayment($j['data'],$j['user'])));}
    catch(Problem $e){echo json_encode(['error'=>$e->getMessage()]);}exit;
}
$count=0;
function ap_ok(bool $ok,string $label): void {global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;echo 'PASS '.$label.PHP_EOL;}
function ap_no(callable $fn,string $label): void {try{$fn();}catch(Problem $e){ap_ok(true,$label);return;}throw new RuntimeException('FAIL accepted '.$label);}
try{
    $db=test_database();$s=new Station($db);$u=$s->setup(['owner_name'=>'Owner','username'=>'owner','password'=>'Ap-tests-2026','station_name'=>'AP test','demo'=>true]);
    ap_ok((int)$db->value('SELECT MAX(version) FROM schema_migrations')===10,'Schema upgrades to version 10');
    $vendor=$s->saveVendor(['code'=>'AP1','name'=>'AP supplier','current_balance'=>'1000'],$u);$id=$vendor['id'];
    $balance=fn()=>(int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$id]);
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Colombo')))->format('Y-m-d');
    $bank=$s->createBank(['code'=>'APBANK','name'=>'Operating','bank_name'=>'Example Bank','account_ref'=>'001234','opening_balance'=>'10000'],$u);
    $emptyBank=$s->createBank(['code'=>'EMPTY','name'=>'Empty','bank_name'=>'Other Bank','account_ref'=>'00999','opening_balance'=>'0'],$u);
    $snap=$s->snapshot($u);$shift=$s->openShift(['label'=>'AP cash','opening_cash'=>'500','nozzle_ids'=>[$snap['nozzles'][0]['id']]],$u);
    $add=['supplier_id'=>$id,'date'=>$today,'amount'=>'250.25','description'=>'Transport charge','request_key'=>uid()];
    $a=$s->workOnce('vendor_payable',$add,$u,fn()=>$s->addVendorPayable($add,$u));
    ap_ok($balance()===125025,'Manual payable adds exact cents to opening balance');
    $again=$s->workOnce('vendor_payable',$add,$u,fn()=>$s->addVendorPayable($add,$u));
    ap_ok($again['id']===$a['id']&&$balance()===125025,'Payable retry is idempotent');
    ap_no(fn()=>$s->workOnce('vendor_payable',array_merge($add,['amount'=>'251']),$u,fn()=>[]),'Reusing a saved key with edited data is rejected');
    foreach(['0','-1','1.005','1e2'] as $amount)ap_no(fn()=>$s->addVendorPayable(array_merge($add,['amount'=>$amount]),$u),'Invalid payable amount '.$amount.' rejected');
    ap_no(fn()=>$s->addVendorPayable(array_merge($add,['date'=>'2026-02-30']),$u),'Impossible date rejected');
    ap_no(fn()=>$s->addVendorPayable(array_merge($add,['date'=>'2099-01-01']),$u),'Future transaction rejected');
    ap_no(fn()=>$s->addVendorPayable(array_merge($add,['date'=>'2000-01-01']),$u),'Entry before vendor registration rejected');
    $cash=['supplier_id'=>$id,'date'=>$today,'amount'=>'100.25','method'=>'cash','shift_id'=>$shift['id'],'reference'=>'CASH-1','request_key'=>uid()];
    $s->workOnce('supplier_payment',$cash,$u,fn()=>$s->supplierPayment($cash,$u));$s->workOnce('supplier_payment',$cash,$u,fn()=>$s->supplierPayment($cash,$u));
    ap_ok($balance()===115000&&$s->shiftTotals($shift['id'])['expected_cash']===50000,'Cash payment decreases payable exactly once without using a shift drawer');
    ap_ok($s->bankBalance($bank['id'])===1000000,'Cash payment does not reduce bank');
    $cashVendor=$s->saveVendor(['code'=>'CASH-POLICY','name'=>'Cash policy test','current_balance'=>'1000'],$u);
    $s->supplierPayment(array_merge($cash,['supplier_id'=>$cashVendor['id'],'amount'=>'600','shift_id'=>'']),$u);
    ap_ok((int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$cashVendor['id']])===40000&&$s->shiftTotals($shift['id'])['expected_cash']===50000,'Independent vendor cash payment works without an open drawer or shift balance');
    $own=['supplier_id'=>$id,'date'=>$today,'amount'=>'200','method'=>'own_cheque','bank_account_id'=>$bank['id'],'cheque_no'=>'000127','cheque_date'=>'2027-01-01','cheque_branch'=>'Colombo','reference'=>'CH-1'];
    $s->supplierPayment($own,$u);
    ap_ok($balance()===95000&&$s->bankBalance($bank['id'])===980000,'Own cheque reduces vendor and bank book at issue');
    ap_ok($db->value("SELECT cheque_no FROM supplier_payments WHERE method='own_cheque'")==='000127','Leading zeroes in cheque number are preserved');
    ap_no(fn()=>$s->supplierPayment($own,$u),'Duplicate own cheque number rejected');
    ap_no(fn()=>$s->supplierPayment(array_merge($own,['cheque_no'=>'002','bank_account_id'=>$emptyBank['id']]),$u),'Insufficient bank funds rejected');
    ap_ok($balance()===95000&&(int)$db->value('SELECT COUNT(*) FROM supplier_payments WHERE supplier_id=?',[$id])===2,'Failed bank debit rolls back the payment');
    $s->supplierPayment(['supplier_id'=>$id,'amount'=>'50','bank_account_id'=>$bank['id'],'reference'=>'LEGACY-API'],$u);
    ap_ok($balance()===90000&&$s->bankBalance($bank['id'])===975000,'Legacy bank API remains compatible');
    $party=['bank_name'=>'Third Party Bank','branch'=>'Main','account_no'=>'0123','cheque_no'=>'00055','cheque_date'=>$today,'holder'=>'Customer A','source_reference'=>'Existing receipt R1','amount'=>'150','confirmed_in_hand'=>'1'];
    $cheque=$s->registerPartyCheque($party,$u);
    ap_ok($balance()===90000&&$s->bankBalance($bank['id'])===975000,'Registering in-hand cheque posts no payable, cash or bank money');
    ap_no(fn()=>$s->registerPartyCheque($party,$u),'Duplicate party cheque rejected');
    ap_no(fn()=>$s->registerPartyCheque(array_merge($party,['cheque_no'=>'NEW','confirmed_in_hand'=>'']),$u),'Party register requires source-accounting acknowledgement');
    $pay=['supplier_id'=>$id,'date'=>$today,'amount'=>'150','method'=>'party_cheque','party_cheque_id'=>$cheque['id'],'reference'=>'ENDORSE-1'];
    ap_no(fn()=>$s->supplierPayment(array_merge($pay,['amount'=>'100']),$u),'Partial party cheque transfer rejected');
    $s->supplierPayment($pay,$u);
    ap_ok($balance()===75000&&$s->bankBalance($bank['id'])===975000&&$s->shiftTotals($shift['id'])['expected_cash']===50000,'Party cheque reduces only vendor balance');
    ap_ok($s->find('party_cheques',$cheque['id'])['status']==='endorsed','Transferred cheque leaves available register');
    ap_no(fn()=>$s->supplierPayment($pay,$u),'Used party cheque cannot be used again');
    ap_no(fn()=>$s->supplierPayment(array_merge($cash,['amount'=>'751']),$u),'Vendor overpayment rejected');
    ap_no(fn()=>$s->supplierPayment(array_merge($cash,['method'=>'invented']),$u),'Unknown payment method rejected');
    foreach(['cashier','viewer'] as $role){$other=array_merge($u,['role'=>$role]);ap_no(fn()=>$s->addVendorPayable($add,$other),$role.' cannot add vendor debt');ap_no(fn()=>$s->supplierPayment($cash,$other),$role.' cannot pay vendors');ap_no(fn()=>$s->registerPartyCheque($party,$other),$role.' cannot register party cheques');ap_ok(!isset($s->workspaceSnapshot($other)['party_cheques']),$role.' cannot read cheque register');}
    $statement=$s->ledgerStatement(['kind'=>'vendor','id'=>$id,'from'=>$today,'to'=>$today],$u);
    ap_ok($statement['closing_cents']===$balance()&&$statement['debits_cents']===125025&&$statement['credits_cents']===50025,'Statement totals reconcile with current balance');
    ap_ok(count(array_filter($statement['rows'],fn($r)=>$r['description']==='Transport charge'))===1,'Statement includes manual payable description once');
    ap_ok(count($s->workspaceSnapshot($u)['party_cheques'])===0,'Used party cheque is not offered in snapshot');
    // Same-second payments must follow insertion order, never random UUID order.
    $db->run("UPDATE supplier_payments SET occurred_at=? WHERE supplier_id=?",[stamp(),$id]);
    $ordered=$s->ledgerStatement(['kind'=>'vendor','id'=>$id,'from'=>$today,'to'=>$today],$u);
    $payments=array_values(array_filter($ordered['rows'],fn($r)=>(int)$r['credit_cents']>0));
    ap_ok(array_column($payments,'reference')===['CASH-1','CH-1','LEGACY-API','ENDORSE-1'],'Same-second payments follow their recorded order');

    // Two real clients try to spend a balance that can fund only one payment.
    $race=$s->saveVendor(['code'=>'RACE','name'=>'Race supplier','current_balance'=>'100'],$u);
    $jobs=[];$start=microtime(true)+0.5;
    for($i=0;$i<2;$i++){$data=['supplier_id'=>$race['id'],'amount'=>'75','bank_account_id'=>$bank['id'],'reference'=>'RACE-'.$i,'request_key'=>uid()];$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['config'=>test_last_config(),'user'=>$u,'data'=>$data,'start'=>$start]));fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $results=[];foreach($jobs as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}
    ap_ok(count(array_filter($results,fn($r)=>isset($r['id'])))===1&&(int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$race['id']])===2500,'Concurrent payments cannot overpay one vendor');
    // Repeat the exact same key concurrently: one posting, two identical results.
    $data=['supplier_id'=>$race['id'],'amount'=>'10','bank_account_id'=>$bank['id'],'reference'=>'RETRY','request_key'=>uid()];$jobs=[];$start=microtime(true)+0.5;
    for($i=0;$i<2;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fwrite($pipes[0],json_encode(['config'=>test_last_config(),'user'=>$u,'data'=>$data,'start'=>$start]));fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $results=[];foreach($jobs as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}
    ap_ok($results[0]['id']===$results[1]['id']&&(int)$db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$race['id']])===1500,'Concurrent identical retry produces one payment');
    // Re-run from an interrupted v6 state, preserving all posted data.
    $db->run('DELETE FROM schema_migrations WHERE version>=6');$again=new Db(test_last_config());
    ap_ok((int)$again->value('SELECT MAX(version) FROM schema_migrations')===10&&$balance()===75000,'Partially applied schema upgrade can be safely resumed');
    echo "\n$count Accounts Payable checks passed.\n";
}catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
