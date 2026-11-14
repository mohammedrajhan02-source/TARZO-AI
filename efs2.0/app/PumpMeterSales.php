<?php
declare(strict_types=1);
namespace Petrol;
require_once __DIR__.'/PumpDaySales.php';

function upgrade_pump_meter_8(Db $db,array $config): void {
    $name=(string)$db->value('SELECT DATABASE()');$lock='efs-schema-'.substr(hash('sha256',$name),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another upgrade is running. Try again.',503);
    try{
        if((int)$db->value('SELECT MAX(version) FROM schema_migrations')>=8)return;
        $folder=(string)($config['backup_dir']??dirname(__DIR__).'/var/backups');
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create the upgrade backup folder.',500);
        $db->backup($folder.'/EFS-before-meter-sales-8-'.gmdate('Ymd-His').'-'.substr(uid(),0,8).'.sql');
        $db->pdo->exec('ALTER TABLE sales MODIFY shift_id VARCHAR(64) NULL');
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS pump_meter_sales (
            id VARCHAR(64) PRIMARY KEY,sale_id VARCHAR(64) NOT NULL UNIQUE,
            sale_date CHAR(10) NOT NULL,employee_id VARCHAR(64) NOT NULL,
            employee_name VARCHAR(160) NOT NULL,shift_type VARCHAR(8) NOT NULL,
            pump_id VARCHAR(64) NOT NULL,pump_code VARCHAR(24) NOT NULL,
            product_id VARCHAR(64) NOT NULL,item_code VARCHAR(64) NOT NULL,item_description VARCHAR(400) NOT NULL,
            starting_ml BIGINT NOT NULL,closing_ml BIGINT NOT NULL,liters_ml BIGINT NOT NULL,
            price_cents BIGINT NOT NULL,cost_rate_cents BIGINT NOT NULL,
            amount_cents BIGINT NOT NULL,cost_cents BIGINT NOT NULL,profit_cents BIGINT NOT NULL,
            collected_cents BIGINT NOT NULL DEFAULT 0,created_at VARCHAR(32) NOT NULL,created_by VARCHAR(64) NOT NULL,
            INDEX ix_meter_day(sale_date,created_at),INDEX ix_meter_pump(pump_id,closing_ml),
            FOREIGN KEY(sale_id) REFERENCES sales(id),FOREIGN KEY(employee_id) REFERENCES employees(id),
            FOREIGN KEY(pump_id) REFERENCES pumps(id),FOREIGN KEY(product_id) REFERENCES products(id),
            CHECK(closing_ml>starting_ml),CHECK(collected_cents>=0 AND collected_cents<=amount_cents)
        ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS pump_meter_collections (
            id VARCHAR(64) PRIMARY KEY,meter_sale_id VARCHAR(64) NOT NULL,amount_cents BIGINT NOT NULL,
            reference VARCHAR(160) NOT NULL,created_at VARCHAR(32) NOT NULL,created_by VARCHAR(64) NOT NULL,
            FOREIGN KEY(meter_sale_id) REFERENCES pump_meter_sales(id),CHECK(amount_cents>0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(8,?) ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at)',[stamp()]);
    }finally{$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function upgrade_pump_meter_9(Db $db,array $config): void {
    $name=(string)$db->value('SELECT DATABASE()');$lock='efs-schema-'.substr(hash('sha256',$name),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another upgrade is running. Try again.',503);
    try{
        if((int)$db->value('SELECT MAX(version) FROM schema_migrations')>=9)return;
        $folder=(string)($config['backup_dir']??dirname(__DIR__).'/var/backups');
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create the upgrade backup folder.',500);
        $db->backup($folder.'/EFS-before-meter-groups-9-'.gmdate('Ymd-His').'-'.substr(uid(),0,8).'.sql');
        if(!$db->one("SHOW COLUMNS FROM pump_meter_sales LIKE 'group_id'"))$db->pdo->exec('ALTER TABLE pump_meter_sales ADD COLUMN group_id VARCHAR(64) NULL AFTER sale_id');
        if(!$db->one("SHOW INDEX FROM pump_meter_sales WHERE Key_name='ix_meter_group'"))$db->pdo->exec('CREATE INDEX ix_meter_group ON pump_meter_sales (group_id, sale_date)');
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(9,?) ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at)',[stamp()]);
    }finally{$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}

trait PumpMeterSales {
    use PumpDaySales;
    public function meterToday(): string {
        return (new \DateTimeImmutable('now',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->format('Y-m-d');
    }
    private function meterOpening(array $pump): int {
        $last=$this->db->value('SELECT MAX(closing_ml) FROM pump_meter_sales WHERE pump_id=?',[$pump['id']]);
        $baseline=($last!==null&&$last!==false)?(int)$last:(int)$pump['start_meter_ml'];
        // Respect a previously closed legacy nozzle, so its sales are never posted again.
        $nozzle=(int)$this->db->value('SELECT COALESCE(MAX(last_meter_ml),0) FROM nozzles WHERE pump_id=?',[$pump['id']]);
        return max($baseline,$nozzle);
    }
    public function meterSalesMeta(array $u): array {
        $pumps=$this->db->all('SELECT * FROM pumps WHERE active=1 ORDER BY code');
        foreach($pumps as &$p){$p['product_id']=(string)$this->db->setting('pump_product_'.$p['id'],'');$p['meter_opening_ml']=$this->meterOpening($p);$p=array_merge($p,$this->meterPumpStock($p));}unset($p);
        return ['today'=>$this->meterToday(),'pumps'=>$pumps,'employees'=>$this->db->all('SELECT id,code,name,shift_type FROM employees WHERE active=1 ORDER BY name')];
    }
    public function saveMeterBatch(array $d,array $u): array {
        require_role($u,['admin','manager','cashier']);
        $entries=$d['entries']??null;
        if(!is_array($entries)||count($entries)<1||count($entries)>20)throw new Problem('Add between 1 and 20 pump sales.');
        return $this->db->tx(function()use($d,$entries,$u){
            $group=required($d,'request_key',64);$rows=[];$total=0;
            foreach(array_values($entries) as $i=>$entry){
                if(!is_array($entry))throw new Problem('Invalid pump sale entry.');
                $entry['request_key']=$group.':'.$i;$entry['group_id']=$group;
                $row=$this->saveMeterSale($entry,$u);$rows[]=$row;$total+=$row['amount_cents'];
            }
            return ['rows'=>$rows,'amount_cents'=>$total,'group_id'=>$group];
        });
    }
    public function saveMeterSale(array $d,array $u,bool $dated=false): array {
        require_role($u,['admin','manager','cashier']);
        return $this->db->tx(function()use($d,$u,$dated){
            $today=$this->meterToday();$saleDate=required($d,'sale_date',10);
            if($dated)$this->meterWriteDate($saleDate,$u);elseif($saleDate!==$today)throw new Problem('The station date changed. Refresh before saving.',409);
            $pump=$this->find('pumps',required($d,'pump_id'));if(!(int)$pump['active'])throw new Problem('Pump is inactive.');
            $this->arRequireUnallocated($saleDate,$pump['id']);
            $latest=(string)$this->db->value('SELECT MAX(sale_date) FROM pump_meter_sales WHERE pump_id=?',[$pump['id']]);
            if($latest>$saleDate)throw new Problem('Later meter sales already exist for this pump. Use Overview to correct that day instead of appending an older reading.',409);
            if($dated&&$saleDate!==$today){$end=(new \DateTimeImmutable($saleDate.' 23:59:59',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');if((int)$this->db->value('SELECT COUNT(*) FROM shift_nozzles sn JOIN nozzles n ON n.id=sn.nozzle_id JOIN shifts s ON s.id=sn.shift_id WHERE n.pump_id=? AND (s.opened_at>? OR s.closed_at>?)',[$pump['id'],$end,$end]))throw new Problem('A later billing shift already uses this pump.');}
            $employee=$this->find('employees',required($d,'employee_id'));$shift=required($d,'shift_type',8);
            if(!(int)$employee['active']||$employee['shift_type']!==$shift)throw new Problem('Select an active employee belonging to this shift.');
            $groupId=trim((string)($d['group_id']??''));if($groupId==='')$groupId=uid();
            $start=$this->meterOpening($pump);if(scaled($d['starting_meter']??'',3)!==$start)throw new Problem('Starting meter changed. Refresh and enter the new closing reading.',409);
            $close=scaled($d['closing_meter']??'',3);$qty=$close-$start;if($qty<=0)throw new Problem('Closing meter must be greater than starting meter.');
            $nozzles=$this->db->all('SELECT * FROM nozzles WHERE pump_id=?',[$pump['id']]);
            if(count($nozzles)>1)throw new Problem('This pump has multiple nozzles. Use separate pumps/meters for this entry screen.');
            if((int)$this->db->value("SELECT COUNT(*) FROM shift_nozzles sn JOIN nozzles n ON n.id=sn.nozzle_id JOIN shifts s ON s.id=sn.shift_id WHERE n.pump_id=? AND s.status='open'",[$pump['id']]))throw new Problem('Close the existing billing shift for this pump before entering a new meter sale.');
            $productId=(string)$this->db->setting('pump_product_'.$pump['id'],'');if($productId==='')throw new Problem('Link a product to this pump in Work Space → Pumps.');
            $p=$this->find('products',$productId);$price=(int)$p['price_cents'];$costRate=(int)$p['cost_cents'];
            if((string)($d['product_id']??'')!==$productId||(int)($d['price_cents']??-1)!==$price)throw new Problem('Pump product or selling price changed. Refresh before saving.',409);
            if($price<=0)throw new Problem('Set a positive selling price in Products.');
            $this->deductPumpProduct($pump['id'],$qty);
            $nozzle=$nozzles[0]??null;
            if($nozzle&&!(int)$nozzle['active'])throw new Problem('The nozzle connected to this pump is inactive.');
            if($nozzle&&$this->balance($nozzle['tank_id'])<$qty)throw new Problem('Insufficient tank stock.');
            $amount=line_total($qty,$price);$cost=line_total($qty,$costRate);if($amount<=0)throw new Problem('Sales amount must be greater than zero.');
            $id=uid();$at=stamp();$key=required($d,'request_key',100);
            $this->db->insert('sales',['id'=>$id,'idempotency_key'=>'pm:'.$key,'receipt_no'=>'PM-'.gmdate('ymd').'-'.strtoupper(substr($id,0,10)),'shift_id'=>null,'source'=>'meter_settlement','total_cents'=>$amount,'cost_cents'=>$cost,'created_at'=>$this->meterBookedAt($saleDate),'created_by'=>$u['id']]);
            $this->db->insert('sale_items',['id'=>uid(),'sale_id'=>$id,'kind'=>'fuel','nozzle_id'=>$nozzle['id']??null,'product_id'=>$productId,'description'=>$pump['description'],'quantity_milli'=>$qty,'unit_price_cents'=>$price,'line_cents'=>$amount,'cost_cents'=>$cost]);
            $row=['id'=>$id,'sale_id'=>$id,'group_id'=>$groupId,'sale_date'=>$saleDate,'employee_id'=>$employee['id'],'employee_name'=>$employee['name'],'shift_type'=>$shift,'pump_id'=>$pump['id'],'pump_code'=>$pump['code'],'product_id'=>$productId,'item_code'=>$p['sku'],'item_description'=>$pump['description'],'starting_ml'=>$start,'closing_ml'=>$close,'liters_ml'=>$qty,'price_cents'=>$price,'cost_rate_cents'=>$costRate,'amount_cents'=>$amount,'cost_cents'=>$cost,'profit_cents'=>$amount-$cost,'collected_cents'=>0,'created_at'=>$at,'created_by'=>$u['id']];
            $this->db->insert('pump_meter_sales',$row);
            $this->db->run('UPDATE pumps SET start_meter_ml=?,end_meter_ml=? WHERE id=?',[$close,$close,$pump['id']]);
            if($nozzle){$this->db->run('UPDATE nozzles SET last_meter_ml=? WHERE id=?',[$close,$nozzle['id']]);$this->stock($nozzle['tank_id'],-$qty,'sale',$id,'Pump meter sale',$u['id']);}
            $this->db->audit($u['id'],'post_meter_sale','sale',$id,$row);$this->db->event('pump_meter_sale',$id,$row);return ['id'=>$id,'closing_ml'=>$close,'amount_cents'=>$amount];
        });
    }
    private function requireMeterEditor(array $row,array $u): void {
        require_role($u,['admin','manager','cashier']);
        $this->meterWriteDate($row['sale_date'],$u);
        if($u['role']==='cashier'&&$row['created_by']!==$u['id'])throw new Problem('Ask a manager to edit another operator’s sale.',403);
    }
    private function meterEditContext(string $id,array $u): array {
        $row=$this->find('pump_meter_sales',$id);$this->requireMeterEditor($row,$u);
        // Meter order is deterministic even when several sales share a timestamp.
        $next=$this->db->one('SELECT * FROM pump_meter_sales WHERE pump_id=? AND starting_ml>=? AND id<>? ORDER BY starting_ml,closing_ml,id LIMIT 1',[$row['pump_id'],$row['closing_ml'],$id]);
        $pump=$this->find('pumps',$row['pump_id']);
        $nozzles=$this->db->all('SELECT * FROM nozzles WHERE pump_id=? ORDER BY id',[$row['pump_id']]);
        $version=hash('sha256',json_encode([$row,$next,$pump,$nozzles],JSON_THROW_ON_ERROR));
        return compact('row','next','pump','nozzles','version');
    }
    public function meterEdit(array $d,array $u): array {
        return $this->db->tx(function()use($d,$u){
            $c=$this->meterEditContext(required($d,'id'),$u);
            if(!in_array($u['role'],['admin','manager'],true)){
                foreach(['row','next'] as $key)if($c[$key])unset($c[$key]['cost_rate_cents'],$c[$key]['cost_cents'],$c[$key]['profit_cents']);
            }
            return ['row'=>$c['row'],'next'=>$c['next'],'version'=>$c['version']];
        });
    }
    public function updateMeterSale(array $d,array $u): array {
        return $this->db->tx(function()use($d,$u){
            $c=$this->meterEditContext(required($d,'id'),$u);$old=$c['row'];$next=$c['next'];$pump=$c['pump'];
            $this->arRequireUnallocated($old['sale_date'],$old['pump_id']);
            if(!hash_equals($c['version'],required($d,'version',64)))throw new Problem('This sale or its meter sequence changed. Cancel editing and click Edit again to load the current values.',409);
            // Identity, opening meter and original rates cannot be rewritten by a client.
            foreach(['pump_id','product_id','sale_date'] as $key)if(isset($d[$key])&&(string)$d[$key]!==$old[$key])throw new Problem('The original pump, product and date must be retained.');
            if(isset($d['starting_meter'])&&scaled($d['starting_meter'],3)!==(int)$old['starting_ml'])throw new Problem('The original opening meter must be retained.');
            if(isset($d['price_cents'])&&(int)$d['price_cents']!==(int)$old['price_cents'])throw new Problem('The original selling price must be retained.');
            $employee=$this->find('employees',required($d,'employee_id'));$shift=required($d,'shift_type',8);
            $reassigned=$employee['id']!==$old['employee_id']||$shift!==$old['shift_type'];
            if($reassigned&&(!(int)$employee['active']||$employee['shift_type']!==$shift))throw new Problem('Select an active employee belonging to this shift.');
            if($reassigned&&(int)$old['collected_cents']>0)throw new Problem('Cash has already been collected. Keep the original employee and shift.');
            $close=scaled($d['closing_meter']??'',3);$delta=$close-(int)$old['closing_ml'];
            $changed=$old;$changed['closing_ml']=$close;$changed['employee_id']=$employee['id'];$changed['employee_name']=$reassigned?$employee['name']:$old['employee_name'];$changed['shift_type']=$shift;
            $changes=[[$old,$changed]];
            if($delta!==0){
                if(!(int)$pump['active']||count($c['nozzles'])>1)throw new Problem('This pump’s meter configuration does not support a reading correction.');
                if($next){
                    $this->arRequireUnallocated($next['sale_date'],$next['pump_id']);
                    $this->requireMeterEditor($next,$u);
                    if((int)$next['starting_ml']!==(int)$old['closing_ml']||$next['product_id']!==$old['product_id'])throw new Problem('A meter gap or product change prevents this correction.');
                    if(($d['next_id']??'')!==$next['id'])throw new Problem('Review the following sale before changing this meter boundary.',409);
                    $adjusted=$next;$adjusted['starting_ml']=$close;$changes[]=[$next,$adjusted];
                }elseif((int)$pump['start_meter_ml']!==(int)$old['closing_ml']||(int)$pump['end_meter_ml']!==(int)$old['closing_ml'])throw new Problem('The live pump reading has moved. Reload before editing.',409);
                // A legacy billing shift owns its meter boundary, including a zero-volume shift.
                $legacy=$this->db->value('SELECT COUNT(*) FROM shift_nozzles sn JOIN nozzles n ON n.id=sn.nozzle_id WHERE n.pump_id=? AND sn.opening_ml>=? AND sn.opening_ml<=?',[$old['pump_id'],min($close,(int)$old['closing_ml']),max($close,(int)$old['closing_ml'])]);
                if((int)$legacy>0)throw new Problem('A billing shift uses this meter boundary. Its reading cannot be changed here.');
                if(!$next&&$c['nozzles']&&(int)$c['nozzles'][0]['last_meter_ml']!==(int)$old['closing_ml'])throw new Problem('The connected nozzle reading has moved. Reload before editing.',409);
            }
            $productDeltas=[];$tankDeltas=[];$movements=[];
            foreach($changes as &$pair){
                [$before,$after]=$pair;$qty=(int)$after['closing_ml']-(int)$after['starting_ml'];
                if($qty<=0)throw new Problem('Closing meter must be above the opening meter and below the following sale’s closing meter.');
                $after['liters_ml']=$qty;$after['amount_cents']=line_total($qty,(int)$before['price_cents']);$after['cost_cents']=line_total($qty,(int)$before['cost_rate_cents']);$after['profit_cents']=$after['amount_cents']-$after['cost_cents'];
                if($after['amount_cents']<=0||$after['amount_cents']<(int)$before['collected_cents'])throw new Problem('The corrected amount must be positive and cannot be less than cash already collected.');
                $items=$this->db->all('SELECT * FROM sale_items WHERE sale_id=?',[$before['sale_id']]);
                if(count($items)!==1||$items[0]['product_id']!==$before['product_id'])throw new Problem('This sale’s accounting lines need review before editing.',409);
                $difference=$qty-(int)$before['liters_ml'];$productId=$before['product_id'];
                $productDeltas[$productId]=($productDeltas[$productId]??0)+$difference;
                if($difference!==0){
                    $originalStock=$this->db->all("SELECT DISTINCT tank_id FROM stock_movements WHERE kind='sale' AND reference_id=?",[$before['sale_id']]);
                    if(count($originalStock)>1||($items[0]['nozzle_id']&&count($originalStock)!==1))throw new Problem('This sale’s tank history needs review before editing.',409);
                    if($originalStock){$tankId=$originalStock[0]['tank_id'];$tankDeltas[$tankId]=($tankDeltas[$tankId]??0)+$difference;$movements[]=[$tankId,-$difference,$before['sale_id']];}
                    // A nozzle attached after the sale must not silently change its tank ledger.
                    if(!$next&&(($items[0]['nozzle_id']??'')!==($c['nozzles'][0]['id']??'')))throw new Problem('The pump’s nozzle configuration changed since this sale.',409);
                    if(!$next&&$originalStock&&$originalStock[0]['tank_id']!==($c['nozzles'][0]['tank_id']??''))throw new Problem('The pump’s tank connection changed since this sale.',409);
                }
                $pair=[$before,$after];
            }unset($pair);
            foreach($productDeltas as $id=>$quantity){
                $product=$this->find('products',$id);$remaining=(int)$product['stock_milli']-$quantity;
                if($remaining<0)throw new Problem('Insufficient product stock for the corrected reading.');
                if($remaining>1000000000)throw new Problem('The correction exceeds the product stock limit.');
                if($quantity!==0)$this->db->run('UPDATE products SET stock_milli=? WHERE id=?',[$remaining,$id]);
            }
            foreach($tankDeltas as $id=>$quantity){$tank=$this->find('tanks',$id);$remaining=$this->balance($id)-$quantity;if($remaining<0||$remaining>(int)$tank['capacity_ml'])throw new Problem('The correction would put tank stock outside its capacity.');}
            foreach($movements as [$tank,$quantity,$sale])$this->stock($tank,$quantity,'adjustment',$sale,'Pump meter sale correction',$u['id']);
            foreach($changes as [$before,$after]){
                $this->db->run('UPDATE pump_meter_sales SET employee_id=?,employee_name=?,shift_type=?,starting_ml=?,closing_ml=?,liters_ml=?,amount_cents=?,cost_cents=?,profit_cents=? WHERE id=?',[$after['employee_id'],$after['employee_name'],$after['shift_type'],$after['starting_ml'],$after['closing_ml'],$after['liters_ml'],$after['amount_cents'],$after['cost_cents'],$after['profit_cents'],$after['id']]);
                $this->db->run('UPDATE sales SET total_cents=?,cost_cents=? WHERE id=?',[$after['amount_cents'],$after['cost_cents'],$after['sale_id']]);
                $this->db->run('UPDATE sale_items SET quantity_milli=?,line_cents=?,cost_cents=? WHERE sale_id=?',[$after['liters_ml'],$after['amount_cents'],$after['cost_cents'],$after['sale_id']]);
                $this->db->audit($u['id'],'edit_meter_sale','sale',$after['id'],['before'=>$before,'after'=>$after,'requested_sale_id'=>$old['id']]);
                $this->db->event('pump_meter_sale',$after['id'],$after);
            }
            if($delta!==0&&!$next){
                $this->db->run('UPDATE pumps SET start_meter_ml=?,end_meter_ml=? WHERE id=?',[$close,$close,$old['pump_id']]);
                if($c['nozzles'])$this->db->run('UPDATE nozzles SET last_meter_ml=? WHERE id=?',[$close,$c['nozzles'][0]['id']]);
            }
            return ['id'=>$old['id'],'updated_ids'=>array_map(fn($p)=>$p[1]['id'],$changes),'amount_cents'=>$changes[0][1]['amount_cents']];
        });
    }
    public function meterReport(array $d,array $u): array {
        $scope=(string)($d['scope']??'today');if(!in_array($scope,['today','history','due'],true))throw new Problem('Invalid report.');
        if($scope==='due')require_role($u,['admin','manager']);
        $day=$this->meterToday();$where=$scope==='today'?'sale_date=?':($scope==='history'?'sale_date<?':'amount_cents>collected_cents');$params=$scope==='due'?[]:[$day];
        if($scope==='due')$where.=' AND NOT EXISTS (SELECT 1 FROM ar_allocations a WHERE a.sale_date=pump_meter_sales.sale_date AND a.pump_id=pump_meter_sales.pump_id)';
        $offset=max(0,(int)($d['offset']??0));$limit=$scope==='today'?null:50;
        $totals=$this->db->one("SELECT COUNT(*) count,COALESCE(SUM(amount_cents),0) amount_cents,COALESCE(SUM(cost_cents),0) cost_cents,COALESCE(SUM(profit_cents),0) profit_cents,COALESCE(SUM(amount_cents-collected_cents),0) due_cents FROM pump_meter_sales WHERE $where",$params);
        if($limit)$offset=min($offset,max(0,(int)(ceil((int)$totals['count']/$limit)-1)*$limit));
        $rows=$this->db->all("SELECT * FROM pump_meter_sales WHERE $where ORDER BY created_at DESC,id DESC".($limit?" LIMIT $limit OFFSET $offset":''),$params);
        if(!in_array($u['role'],['admin','manager'],true)){unset($totals['cost_cents'],$totals['profit_cents']);foreach($rows as &$row)unset($row['cost_rate_cents'],$row['cost_cents'],$row['profit_cents']);unset($row);}
        return ['today'=>$day,'rows'=>$rows,'totals'=>$totals,'offset'=>$offset,'limit'=>$limit];
    }
    public function collectMeterCash(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $row=$this->find('pump_meter_sales',required($d,'id'));$amount=scaled($d['amount']??'');
            $this->arRequireUnallocated($row['sale_date'],$row['pump_id']);
            if($amount<=0||$amount>(int)$row['amount_cents']-(int)$row['collected_cents'])throw new Problem('Collection must be positive and no greater than the outstanding amount.');
            $receipt=['id'=>uid(),'meter_sale_id'=>$row['id'],'amount_cents'=>$amount,'reference'=>required($d,'reference',160),'created_at'=>stamp(),'created_by'=>$u['id']];
            $this->db->insert('pump_meter_collections',$receipt);
            $this->db->run('UPDATE pump_meter_sales SET collected_cents=collected_cents+? WHERE id=?',[$amount,$row['id']]);
            $this->db->insert('sale_payments',['id'=>$receipt['id'],'sale_id'=>$row['sale_id'],'method'=>'cash','amount_cents'=>$amount,'reference'=>$receipt['reference']]);
            $this->db->audit($u['id'],'collect_meter_cash','sale',$row['id'],$receipt);$this->db->event('pump_meter_collection',$receipt['id'],$receipt);return $receipt;
        });
    }
}

