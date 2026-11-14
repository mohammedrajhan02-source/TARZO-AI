<?php
declare(strict_types=1);
require __DIR__.'/TestDb.php';require dirname(__DIR__).'/app/Station.php';
use Petrol\{Station,Problem};use function Petrol\uid;
$count=0;
function day_check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException('FAIL '.$label);$count++;echo 'PASS '.$label.PHP_EOL;}
function day_deny(callable $fn,string $label):void{try{$fn();}catch(Problem $e){day_check(true,$label);return;}throw new RuntimeException('FAIL accepted '.$label);}
try{
$db=test_database();$s=new Station($db);$u=$s->setup(['owner_name'=>'Owner','username'=>'owner','password'=>'Daily-sales-test-2026','station_name'=>'Daily sales tests']);$cashier=array_merge($u,['role'=>'cashier']);$viewer=array_merge($u,['role'=>'viewer']);
$cat=$s->saveCategory(['code'=>'FUEL','name'=>'Fuel'],$u);$p=$s->saveProduct(['sku'=>'PET','name'=>'Petrol','quantity'=>'1000','cost'=>'250','price'=>'300','category_id'=>$cat['id']],$u);
$f=$s->master('fuel',['code'=>'PET','name'=>'Petrol','price'=>'300'],$u);$t=$s->master('tank',['code'=>'T1','name'=>'Tank 1','fuel_type_id'=>$f['id'],'capacity_liters'=>'1000','opening_liters'=>'500'],$u);
$pump=$s->savePump(['code'=>'P1','name'=>'Pump 1','product_id'=>$p['id'],'tank_id'=>$t['id'],'start_meter'=>'100','end_meter'=>'100'],$u);$emp=$s->saveEmployee(['code'=>'E1','name'=>'Employee','shift_type'=>'day'],$u);
$today=$s->meterToday();$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');$tomorrow=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$entry=function($day,$start,$close,$which=null)use($pump,$emp,$p,$s){return ['sale_date'=>$day,'pump_id'=>$which??$pump['id'],'employee_id'=>$emp['id'],'shift_type'=>'day','starting_meter'=>$start,'closing_meter'=>$close,'product_id'=>$p['id'],'price_cents'=>$s->find('products',$p['id'])['price_cents']];};
$save=function($day,$entries,$user=null,$key=null)use($s,$u){$user=$user??$u;$d=['sale_date'=>$day,'entries'=>$entries,'request_key'=>$key??uid()];return $s->workOnce('pump_day_save',$d,$user,fn()=>$s->saveMeterDay($d,$user));};
$meta=$s->meterSalesMeta($u)['pumps'][0];day_check($meta['available_stock_ml']===500000&&$meta['product_stock_ml']===1000000,'Available stock uses the lower product/tank balance');
day_check($s->meterControlNo($today)==='DS-'.str_replace('-','',$today),'Daily control number is deterministic and date-specific');
day_deny(fn()=>$save('2026-02-30',[$entry('2026-02-30','100','101')]),'Invalid calendar dates rejected');
day_deny(fn()=>$save($tomorrow,[$entry($tomorrow,'100','101')]),'Future sales rejected');
day_deny(fn()=>$save($yesterday,[$entry($yesterday,'100','101')],$cashier),'Cashier cannot backdate a sale');
day_deny(fn()=>$save($today,[$entry($today,'100','101')],$viewer),'Viewer cannot save daily sales');
day_deny(fn()=>$save($yesterday,[$entry($today,'100','101')]),'Mixed-date batch rejected');
day_deny(fn()=>$save($yesterday,[$entry($yesterday,'100','101'),$entry($yesterday,'101','100')]),'Invalid later line rejects the entire day batch');
day_check((int)$db->value('SELECT COUNT(*) FROM sales')===0&&$s->balance($t['id'])===500000,'Failed day batch rolls back sales and stock');
$key=uid();$entries=[$entry($yesterday,'100','105'),$entry($yesterday,'105','110')];$a=$save($yesterday,$entries,null,$key);
day_check(count($a['ids'])===2&&$a['control_no']===$s->meterControlNo($yesterday),'Manager can save a previous date with one control number');
day_check($save($yesterday,$entries,null,$key)===$a&&(int)$db->value('SELECT COUNT(*) FROM sales')===2,'Day Save retry does not duplicate committed lines');
$summary=$s->meterDailySummary([],$u);day_check(count($summary['rows'])===1&&$summary['rows'][0]['amount_cents']===300000&&$summary['rows'][0]['profit_cents']===50000,'Multiple sales aggregate to one daily total and exact profit');
$booked=new DateTimeImmutable($s->find('sales',$a['ids'][0])['created_at']);day_check($booked->setTimezone(new DateTimeZone('Asia/Colombo'))->format('Y-m-d')===$yesterday,'Backdated sale is booked on its selected business date');
$detail=$s->meterDay(['sale_date'=>$yesterday],$u);day_check(count($detail['rows'])===2&&$detail['rows'][0]['pump_name']==='Pump 1'&&$detail['rows'][0]['product_name']==='Petrol','Overview includes pump name, fuel and every line');
day_check($detail['totals']['amount_cents']===$summary['rows'][0]['amount_cents'],'Overview total matches daily summary');
$db->run('UPDATE products SET price_cents=40000,cost_cents=26000 WHERE id=?',[$p['id']]);$b=$save($today,[$entry($today,'110','120')]);
day_check($s->meterDailySummary([],$u)['totals']['count']===2,'A second date creates a second daily row');
day_deny(fn()=>$save($yesterday,[$entry($yesterday,'120','121')]),'Cannot append old readings after a later-day sale exists');
$edit=$s->meterEdit(['id'=>$a['ids'][1]],$u);$d=['id'=>$a['ids'][1],'version'=>$edit['version'],'next_id'=>$b['ids'][0],'closing_meter'=>'111','employee_id'=>$emp['id'],'shift_type'=>'day','request_key'=>uid()];$s->workOnce('pump_meter_update',$d,$u,fn()=>$s->updateMeterSale($d,$u));
$old=$s->meterDay(['sale_date'=>$yesterday],$u);$new=$s->meterDay(['sale_date'=>$today],$u);
day_check($old['totals']['amount_cents']===330000&&$new['totals']['amount_cents']===360000,'Historical edit updates both affected daily totals');
day_check($old['totals']['profit_cents']===55000&&$new['totals']['profit_cents']===126000,'Historical edit keeps each sale’s original cost and selling price');
day_check($s->balance($t['id'])===480000,'Cross-day boundary correction preserves combined tank consumption');
$filter=$s->meterDailySummary(['from'=>$yesterday,'to'=>$yesterday],$u);day_check(count($filter['rows'])===1&&$filter['totals']['amount_cents']===330000,'Inclusive date filter and totals cover only the selected day');
day_deny(fn()=>$s->meterDailySummary(['from'=>$today,'to'=>$yesterday],$u),'Reversed date filter rejected');
day_deny(fn()=>$s->meterDailySummary(['from'=>'2026-13-01'],$u),'Invalid report filter rejected');
$public=$s->meterDailySummary([],$cashier);day_check(!isset($public['rows'][0]['profit_cents'])&&!isset($public['totals']['profit_cents']),'Cashier report does not expose profit');
$public=$s->meterDay(['sale_date'=>$yesterday],$cashier);day_check(!$public['rows'][0]['can_edit']&&!isset($public['rows'][0]['cost_cents'])&&!isset($public['totals']['profit_cents']),'Cashier historical overview remains read-only without costs');
day_check($s->meterDay(['sale_date'=>$today],$cashier)['rows'][0]['can_edit'],'Cashier can edit their own current-day sale');
$p2=$s->savePump(['code'=>'P2','name'=>'Pump 2','product_id'=>$p['id'],'start_meter'=>'0','end_meter'=>'0'],$u);
for($i=0;$i<53;$i++){$day=(new DateTimeImmutable($today))->modify('-'.(70-$i).' days')->format('Y-m-d');$save($day,[$entry($day,(string)$i,(string)($i+1),$p2['id'])]);}
$first=$s->meterDailySummary([],$u);$last=$s->meterDailySummary(['offset'=>50],$u);$export=$s->meterDailySummary(['export'=>'1'],$u);
day_check(count($first['rows'])===50&&count($last['rows'])===5,'Daily report pagination returns all 55 days');
day_check(count($export['rows'])===55&&$export['totals']===$first['totals'],'Print/PDF export includes every filtered day, not only the visible page');
day_check($s->meterDailySummary(['offset'=>99999],$u)['offset']===50,'Report offset clamps to the last available page');
day_check($s->meterDailySummary(['from'=>$tomorrow],$u)['totals']['count']===0,'Empty date range returns zero totals');
day_check((int)$db->value('SELECT MAX(version) FROM schema_migrations')===10,'New daily workflow preserves schema 10 compatibility');
echo "\n$count daily sales checks passed.\n";
}catch(Throwable $e){fwrite(STDERR,(string)$e.PHP_EOL);exit(1);}
