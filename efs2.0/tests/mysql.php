<?php
declare(strict_types=1);
require __DIR__.'/TestDb.php';
require dirname(__DIR__).'/app/Station.php';
use Petrol\{Db,Station,Problem};
use function Petrol\{uid,stamp};
if(($argv[1]??'')==='--worker'){
    $job=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $db=new Db($job['config']);$station=new Station($db);
    while(microtime(true)<$job['start'])usleep(1000);
    try{$station->sale($job['sale'],$job['user']);echo json_encode(['ok'=>true]);}
    catch(Problem $e){echo json_encode(['ok'=>false,'problem'=>$e->getMessage()]);}
    exit;
}
$count=0;
function assert_mysql(bool $condition,string $label): void {
    global $count;if(!$condition)throw new RuntimeException('FAIL: '.$label);$count++;echo 'PASS '.$label.PHP_EOL;
}
function refuse_mysql(callable $operation,string $label): void {
    try{$operation();}catch(Throwable $e){if(!$e instanceof Problem&&!$e instanceof PDOException)throw $e;assert_mysql(true,$label);return;}
    throw new RuntimeException('FAIL accepted: '.$label);
}
try{
    $db=test_database();$station=new Station($db);
    $owner=$station->setup(['owner_name'=>'Owner','username'=>'owner','password'=>'Mysql-check-2026','station_name'=>'Empty station','demo'=>false]);
    foreach(['fuel_types','tanks','pumps','products','sales','customers'] as $table)assert_mysql((int)$db->value('SELECT COUNT(*) FROM `'.$table.'`')===0,'Empty station contains no '.$table.' sample records');
    assert_mysql(password_verify('Mysql-check-2026',$station->find('users',$owner['id'])['password_hash']),'Owner credentials are hashed');
    $db->set('test_code','001');assert_mysql($db->setting('test_code')==='001','Numeric-looking settings remain strings');
    $db->set('test_code','002');assert_mysql($db->setting('test_code')==='002','MySQL setting upsert updates its existing row');
    $db->set('unicode','வாழைச்சேனை ⛽');assert_mysql($db->setting('unicode')==='வாழைச்சேனை ⛽','utf8mb4 round-trips Tamil and symbols');
    assert_mysql($db->value('SELECT username FROM users WHERE username=?',['OWNER'])==='owner','Username lookups ignore case');
    refuse_mysql(function()use($db){$db->insert('users',['id'=>uid(),'username'=>'OWNER','name'=>'Other','password_hash'=>'unused','role'=>'viewer','created_at'=>stamp()]);},'Case variants cannot duplicate a login');
    $db=test_database();$station=new Station($db);$config=test_last_config();
    $owner=$station->setup(['owner_name'=>'Owner','username'=>'owner','password'=>'Mysql-check-2026','station_name'=>'Race test','demo'=>true]);
    $snapshot=$station->snapshot($owner);$nozzle=$snapshot['nozzles'][0];$tank=$station->find('tanks',$nozzle['tank_id']);
    $fuel=$tank['fuel_type_id'];$now=stamp();
    foreach([['ffffffffffffffffffffffffffffffff',11100],['00000000000000000000000000000000',22200]] as $entry)$db->insert('fuel_prices',['id'=>$entry[0],'fuel_type_id'=>$fuel,'price_cents'=>$entry[1],'effective_at'=>$now,'created_at'=>$now,'created_by'=>$owner['id']]);
    assert_mysql($station->price($fuel)===22200,'Same-second fuel prices follow insertion order, independent of random IDs');
    $shift=$station->openShift(['label'=>'Race shift','opening_cash'=>'0','nozzle_ids'=>[$nozzle['id']]],$owner);
    refuse_mysql(function()use($station,$owner,$snapshot){$station->openShift(['label'=>'Duplicate','nozzle_ids'=>[$snapshot['nozzles'][1]['id']]],$owner);},'Generated unique index permits only one open shift per operator');
    $period=$station->shiftTotals($shift['id'])['periods'][0];
    refuse_mysql(function()use($db,$period){$db->insert('meter_periods',['id'=>uid(),'shift_nozzle_id'=>$period['shift_nozzle_id'],'opening_ml'=>0,'rate_cents'=>100,'started_at'=>stamp()]);},'Generated unique index permits only one open meter period');
    refuse_mysql(function()use($db){$db->insert('nozzles',['id'=>uid(),'code'=>'BAD-FK','pump_id'=>'missing','tank_id'=>'missing']);},'InnoDB foreign keys reject orphan nozzle records');
    $station->adjustStock(['tank_id'=>$tank['id'],'liters'=>number_format((1000-$station->balance($tank['id']))/1000,3,'.',''),'reason'=>'Concurrency test: leave exactly 1 L'],$owner);
    $start=microtime(true)+0.5;$workers=[];
    for($i=0;$i<2;$i++){
        $sale=['idempotency_key'=>uid(),'shift_id'=>$shift['id'],'items'=>[['kind'=>'fuel','nozzle_id'=>$nozzle['id'],'quantity'=>'1']],'payments'=>[['method'=>'cash','amount'=>'222.00']]];
        $command=[PHP_BINARY];$ini=php_ini_loaded_file();if($ini){$command[]='-c';$command[]=$ini;}$command[]=__FILE__;$command[]='--worker';
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Cannot start concurrency worker.');
        fwrite($pipes[0],json_encode(['config'=>$config,'user'=>$owner,'sale'=>$sale,'start'=>$start],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $workers[]=[$process,$pipes];
    }
    $results=[];
    foreach($workers as $worker){[$process,$pipes]=$worker;$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0)throw new RuntimeException('Concurrency worker failed: '.$error.$output);$results[]=json_decode($output,true,16,JSON_THROW_ON_ERROR);}
    assert_mysql(count(array_filter($results,function($r){return $r['ok'];}))===1,'Two concurrent 1 L sales accept exactly one when stock is 1 L');
    assert_mysql(count(array_filter($results,function($r){return isset($r['problem'])&&strpos($r['problem'],'Insufficient')!==false;}))===1,'Concurrent loser receives the stock validation error');
    assert_mysql($station->balance($tank['id'])===0,'Concurrent sales cannot make tank stock negative');
    assert_mysql((int)$db->value('SELECT COUNT(*) FROM sales')===1,'Concurrent rejection leaves no partial sale');
    echo PHP_EOL.$count.' MySQL-specific checks passed.'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
