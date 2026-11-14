<?php
declare(strict_types=1);
namespace Petrol;

trait PumpDaySales {
    private function meterDate(string $day): string {
        if(!calendar_date($day)||$day<'2000-01-01')throw new Problem('Select a valid sale date from 2000 onwards.');
        return $day;
    }
    private function meterWriteDate(string $day,array $u): string {
        $this->meterDate($day);$today=$this->meterToday();
        if($day>$today)throw new Problem('Future-dated sales are not allowed.');
        if($day!==$today)require_role($u,['admin','manager']);
        return $day;
    }
    public function meterControlNo(string $day): string {return 'DS-'.str_replace('-','',$this->meterDate($day));}
    private function meterBookedAt(string $day): string {
        if($day===$this->meterToday())return stamp();
        return (new \DateTimeImmutable($day.' 12:00:00',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    private function meterPumpStock(array $pump): array {
        $product=$this->db->one('SELECT stock_milli,active FROM products WHERE id=?',[$pump['product_id']]);
        $nozzles=$this->db->all('SELECT id,tank_id,active FROM nozzles WHERE pump_id=?',[$pump['id']]);
        $stock=$product&&(int)$product['active']?(int)$product['stock_milli']:0;
        $tank=count($nozzles)===1?$nozzles[0]['tank_id']:null;
        $tankStock=$tank?$this->balance($tank):null;
        $available=$tank?min($stock,$tankStock):$stock;
        if(count($nozzles)>1||($tank&&!(int)$nozzles[0]['active']))$available=0;
        return ['product_stock_ml'=>$stock,'tank_id'=>$tank,'tank_stock_ml'=>$tankStock,'available_stock_ml'=>$available];
    }
    public function saveMeterDay(array $d,array $u): array {
        require_role($u,['admin','manager','cashier']);
        $day=$this->meterWriteDate(required($d,'sale_date',10),$u);$entries=$d['entries']??null;
        if(!is_array($entries)||count($entries)<1||count($entries)>200)throw new Problem('Save between 1 and 200 pending pump sales at a time.');
        return $this->db->tx(function()use($d,$u,$day,$entries){
            $key=required($d,'request_key',64);$ids=[];
            foreach(array_values($entries) as $i=>$entry){
                if(!is_array($entry)||($entry['sale_date']??'')!==$day)throw new Problem('Every pending line must belong to the selected sale date.');
                $entry['request_key']=$key.':'.$i;$entry['group_id']=$key;
                $row=$this->saveMeterSale($entry,$u,true);$ids[]=$row['id'];
            }
            return ['sale_date'=>$day,'control_no'=>$this->meterControlNo($day),'ids'=>$ids];
        });
    }
    public function meterDay(array $d,array $u): array {
        $day=$this->meterDate(required($d,'sale_date',10));
        return $this->db->tx(function()use($day,$u){
            $rows=$this->db->all('SELECT m.*,p.name pump_name,pr.name product_name FROM pump_meter_sales m JOIN pumps p ON p.id=m.pump_id JOIN products pr ON pr.id=m.product_id WHERE m.sale_date=? ORDER BY m.created_at,m.pump_id,m.starting_ml,m.id',[$day]);
            $totals=['count'=>count($rows),'liters_ml'=>0,'amount_cents'=>0,'cost_cents'=>0,'profit_cents'=>0];
            foreach($rows as &$row){
                foreach(['liters_ml','amount_cents','cost_cents','profit_cents'] as $key)$totals[$key]+=(int)$row[$key];
                $row['can_edit']=in_array($u['role'],['admin','manager'],true)||($u['role']==='cashier'&&$row['created_by']===$u['id']&&$day===$this->meterToday());
                if(!in_array($u['role'],['admin','manager'],true))unset($row['cost_cents'],$row['cost_rate_cents'],$row['profit_cents']);
            }unset($row);
            if(!in_array($u['role'],['admin','manager'],true))unset($totals['cost_cents'],$totals['profit_cents']);
            return ['sale_date'=>$day,'control_no'=>$this->meterControlNo($day),'rows'=>$rows,'totals'=>$totals];
        });
    }
    public function meterDailySummary(array $d,array $u): array {
        $from=trim((string)($d['from']??''));$to=trim((string)($d['to']??''));
        if($from!=='')$this->meterDate($from);if($to!=='')$this->meterDate($to);
        if($from!==''&&$to!==''&&$from>$to)throw new Problem('The From date must be on or before the To date.');
        $conditions=[];$params=[];
        if($from!==''){$conditions[]='sale_date>=?';$params[]=$from;}
        if($to!==''){$conditions[]='sale_date<=?';$params[]=$to;}
        $where=$conditions?' WHERE '.implode(' AND ',$conditions):'';
        return $this->db->tx(function()use($d,$u,$from,$to,$where,$params){
            $totals=$this->db->one('SELECT COUNT(DISTINCT sale_date) count,COALESCE(SUM(amount_cents),0) amount_cents,COALESCE(SUM(profit_cents),0) profit_cents FROM pump_meter_sales'.$where,$params);
            $export=($d['export']??'')==='1';$limit=$export?5000:50;
            if($export&&(int)$totals['count']>$limit)throw new Problem('Choose a smaller date range to export up to 5,000 days.');
            $offset=$export?0:max(0,min((int)($d['offset']??0),max(0,((int)ceil((int)$totals['count']/$limit)-1)*$limit)));
            $rows=$this->db->all('SELECT sale_date,COUNT(*) line_count,SUM(liters_ml) liters_ml,SUM(amount_cents) amount_cents,SUM(profit_cents) profit_cents FROM pump_meter_sales'.$where." GROUP BY sale_date ORDER BY sale_date DESC LIMIT $limit OFFSET $offset",$params);
            foreach($rows as &$row){$row['control_no']=$this->meterControlNo($row['sale_date']);if(!in_array($u['role'],['admin','manager'],true))unset($row['profit_cents']);}unset($row);
            if(!in_array($u['role'],['admin','manager'],true))unset($totals['profit_cents']);
            return compact('rows','totals','offset','limit','from','to');
        });
    }
}
