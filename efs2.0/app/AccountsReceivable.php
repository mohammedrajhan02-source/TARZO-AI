<?php
declare(strict_types=1);
namespace Petrol;

/** Additive upgrade; all source sales remain authoritative. */
function upgrade_receivables_11(Db $db,array $config): void {
    $lock='efs-schema-'.substr(hash('sha256',(string)$db->value('SELECT DATABASE()')),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another database upgrade is running.',503);
    try {
        $version=(int)$db->value('SELECT MAX(version) FROM schema_migrations');
        if($version>=11)return;
        if($version!==10)throw new Problem('Receivables upgrade requires schema 10.',500);
        $folder=(string)($config['backup_dir']??dirname(__DIR__).'/var/backups');
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create pre-upgrade backup folder.',500);
        $db->backup($folder.'/EFS-before-receivables-11-'.gmdate('Ymd-His').'-'.substr(uid(),0,8).'.sql');
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS ar_allocations (
          id VARCHAR(64) PRIMARY KEY,sale_date CHAR(10) NOT NULL,pump_id VARCHAR(64) NOT NULL,
          version INT NOT NULL,total_sales_cents BIGINT NOT NULL,cash_cents BIGINT NOT NULL,
          card_cents BIGINT NOT NULL,credit_cents BIGINT NOT NULL,total_cents BIGINT NOT NULL,
          difference_cents BIGINT NOT NULL,created_by VARCHAR(64) NOT NULL,updated_at VARCHAR(32) NOT NULL,
          UNIQUE KEY ar_day_pump(sale_date,pump_id),FOREIGN KEY(pump_id) REFERENCES pumps(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS ar_invoices (
          id VARCHAR(64) PRIMARY KEY,invoice_no VARCHAR(80) COLLATE utf8mb4_general_ci NOT NULL UNIQUE,
          sale_date CHAR(10) NOT NULL,customer_id VARCHAR(64) NOT NULL,version INT NOT NULL,
          total_cents BIGINT NOT NULL,ledger_id VARCHAR(64) NOT NULL UNIQUE,
          created_by VARCHAR(64) NOT NULL,updated_at VARCHAR(32) NOT NULL,
          FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(ledger_id) REFERENCES customer_ledger(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS ar_invoice_lines (
          id VARCHAR(64) PRIMARY KEY,invoice_id VARCHAR(64) NOT NULL,line_no INT NOT NULL,
          allocation_id VARCHAR(64) NOT NULL,pump_id VARCHAR(64) NOT NULL,product_id VARCHAR(64) NOT NULL,
          pump_code VARCHAR(64) NOT NULL,product_name VARCHAR(400) NOT NULL,
          liters_ml BIGINT NOT NULL,price_cents BIGINT NOT NULL,amount_cents BIGINT NOT NULL,
          FOREIGN KEY(invoice_id) REFERENCES ar_invoices(id),FOREIGN KEY(allocation_id) REFERENCES ar_allocations(id),
          FOREIGN KEY(pump_id) REFERENCES pumps(id),FOREIGN KEY(product_id) REFERENCES products(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS ar_payment_links (
          payment_id VARCHAR(64) PRIMARY KEY,allocation_id VARCHAR(64) NOT NULL,
          FOREIGN KEY(payment_id) REFERENCES sale_payments(id),FOREIGN KEY(allocation_id) REFERENCES ar_allocations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(11,?) ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at)',[stamp()]);
    } finally {$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}

trait AccountsReceivable {
    private function arWriteDate(string $day,array $u): string {
        require_role($u,['admin','manager','cashier']);
        return $this->meterWriteDate($day,$u);
    }
    private function arSource(string $day,string $pump): array {
        return $this->db->all('SELECT id,sale_id,pump_id,pump_code,product_id,item_code,item_description,price_cents,liters_ml,amount_cents,collected_cents FROM pump_meter_sales WHERE sale_date=? AND pump_id=? ORDER BY id',[$day,$pump]);
    }
    private function arUsed(string $allocation,string $except=''): int {
        return (int)$this->db->value('SELECT COALESCE(SUM(amount_cents),0) FROM ar_invoice_lines WHERE allocation_id=? AND invoice_id<>?',[$allocation,$except]);
    }
    public function arDay(array $d,array $u): array {
        $day=$this->meterDate(required($d,'sale_date',10));$pump=(string)($d['pump_id']??'');
        return $this->db->tx(function()use($day,$pump,$u){
            $pumps=$this->db->all('SELECT p.id,p.code,p.name,SUM(m.amount_cents) total_cents FROM pump_meter_sales m JOIN pumps p ON p.id=m.pump_id WHERE m.sale_date=? GROUP BY p.id,p.code,p.name ORDER BY p.code',[$day]);
            $products=$this->db->all('SELECT m.pump_id,m.product_id,p.name product_name,m.price_cents,SUM(m.liters_ml) liters_ml,SUM(m.amount_cents) amount_cents FROM pump_meter_sales m JOIN products p ON p.id=m.product_id WHERE m.sale_date=? GROUP BY m.pump_id,m.product_id,p.name,m.price_cents ORDER BY m.pump_id,p.name,m.price_cents',[$day]);
            foreach($pumps as &$p){$a=$this->db->one('SELECT * FROM ar_allocations WHERE sale_date=? AND pump_id=?',[$day,$p['id']]);$p['credit_remaining_cents']=$a?(int)$a['credit_cents']-$this->arUsed($a['id']):0;foreach($products as $pr)if($pr['pump_id']===$p['id']){$p+=array_intersect_key($pr,array_flip(['product_id','product_name','price_cents']));break;}}unset($p);
            $source=$pump!==''?$this->arSource($day,$pump):[];
            $total=array_sum(array_column($source,'amount_cents'));
            $allocation=$this->db->one('SELECT * FROM ar_allocations WHERE sale_date=? AND pump_id=?',[$day,$pump]);
            $used=$allocation?$this->arUsed($allocation['id']):0;
            return ['sale_date'=>$day,'pump_id'=>$pump,'total_cents'=>$total,'source_version'=>hash('sha256',json_encode($source,JSON_THROW_ON_ERROR)),
              'allocation'=>$allocation,'credit_used_cents'=>$used,'credit_remaining_cents'=>$allocation?(int)$allocation['credit_cents']-$used:0,
              'pumps'=>$pumps,'products'=>$products,'invoices'=>$this->arInvoices(['sale_date'=>$day],$u)['rows']];
        });
    }
    public function arSaveAllocation(array $d,array $u): array {
        $day=$this->arWriteDate(required($d,'sale_date',10),$u);$pump=required($d,'pump_id');
        return $this->db->tx(function()use($d,$u,$day,$pump){
            $state=$this->arDay(['sale_date'=>$day,'pump_id'=>$pump],$u);$old=$state['allocation'];$source=$this->arSource($day,$pump);
            if(!$source)throw new Problem('No pump sales recorded for this date.');
            if(!hash_equals($state['source_version'],required($d,'source_version',64))||(int)($d['version']??-1)!==(int)($old['version']??0))throw new Problem('Sales or allocation changed. Reload before saving.',409);
            if(array_sum(array_column($source,'collected_cents'))>0)throw new Problem('This pump/date already has legacy cash collections; keep using the legacy collection screen.');
            if($old&&$u['role']==='cashier'&&$old['created_by']!==$u['id'])throw new Problem('Only the original cashier or a manager may change this allocation.',403);
            foreach($source as $sale){
                if((int)$this->db->value('SELECT COUNT(*) FROM sale_payments p WHERE p.sale_id=? AND NOT EXISTS (SELECT 1 FROM ar_payment_links l WHERE l.payment_id=p.id AND l.allocation_id=?)',[$sale['sale_id'],$old['id']??''])>0)throw new Problem('This pump sale already has other payments. Reconcile them before allocating.',409);
            }
            $cash=scaled($d['cash']??'');$card=scaled($d['card']??'');$credit=scaled($d['credit']??'');$sum=$cash+$card+$credit;
            if($sum>1000000000)throw new Problem('Allocation exceeds the supported amount.');
            if($credit>$state['total_cents'])throw new Problem('Credit cannot exceed recorded pump sales.');
            if($card+$credit>$state['total_cents'])throw new Problem('Card plus credit cannot exceed recorded sales. Excess cash is shown as a reconciliation difference.');
            if($old&&$credit<$this->arUsed($old['id']))throw new Problem('Credit cannot be reduced below saved invoices.');
            $id=$old['id']??uid();$row=['id'=>$id,'sale_date'=>$day,'pump_id'=>$pump,'version'=>(int)($old['version']??0)+1,'total_sales_cents'=>$state['total_cents'],'cash_cents'=>$cash,'card_cents'=>$card,'credit_cents'=>$credit,'total_cents'=>$sum,'difference_cents'=>$state['total_cents']-$sum,'created_by'=>$old['created_by']??$u['id'],'updated_at'=>stamp()];
            if($old){$this->db->run('UPDATE ar_allocations SET version=?,total_sales_cents=?,cash_cents=?,card_cents=?,credit_cents=?,total_cents=?,difference_cents=?,updated_at=? WHERE id=?',[$row['version'],$row['total_sales_cents'],$cash,$card,$credit,$sum,$row['difference_cents'],$row['updated_at'],$id]);}
            else $this->db->insert('ar_allocations',$row);
            // Reclassify payments on original sales; no new sale or stock movement.
            $links=$this->db->all('SELECT payment_id FROM ar_payment_links WHERE allocation_id=?',[$id]);
            $this->db->run('DELETE FROM ar_payment_links WHERE allocation_id=?',[$id]);
            foreach($links as $link)$this->db->run('DELETE FROM sale_payments WHERE id=?',[$link['payment_id']]);
            $remaining=['credit'=>$credit,'card'=>$card,'cash'=>$cash];
            foreach($source as $s){$capacity=(int)$s['amount_cents'];foreach($remaining as $method=>&$amount){$posted=min($capacity,$amount);if($posted>0){$pid=uid();$this->db->insert('sale_payments',['id'=>$pid,'sale_id'=>$s['sale_id'],'method'=>$method,'amount_cents'=>$posted,'reference'=>'AR allocation '.$id]);$this->db->insert('ar_payment_links',['payment_id'=>$pid,'allocation_id'=>$id]);$capacity-=$posted;$amount-=$posted;}}unset($amount);}
            // Excess allocation is retained as a reconciliation difference, never fake revenue.
            $this->db->audit($u['id'],'save_allocation','ar_allocation',$id,$row);$this->db->event('ar_allocation',$id,$row);
            return $this->arDay(['sale_date'=>$day,'pump_id'=>$pump],$u);
        });
    }
    public function arInvoices(array $d,array $u): array {
        $day=$this->meterDate(required($d,'sale_date',10));
        $rows=$this->db->all('SELECT i.*,c.name customer_name,c.code customer_code,c.phone FROM ar_invoices i JOIN customers c ON c.id=i.customer_id WHERE i.sale_date=? ORDER BY i.updated_at DESC,i.id',[$day]);
        foreach($rows as &$row)$row['can_edit']=$this->arCanEdit($row,$u);unset($row);
        return ['rows'=>$rows];
    }
    private function arCanEdit(array $row,array $u): bool {
        return in_array($u['role'],['admin','manager','cashier'],true)&&($u['role']!=='cashier'||($row['sale_date']===$this->meterToday()&&$row['created_by']===$u['id']))&&!(int)$this->db->value('SELECT COUNT(*) FROM customer_allocations WHERE invoice_entry_id=?',[$row['ledger_id']]);
    }
    public function arInvoice(array $d,array $u): array {
        $row=$this->db->one('SELECT i.*,c.name customer_name,c.code customer_code,c.phone FROM ar_invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?',[required($d,'id')])??missing('Credit invoice not found.',404);
        $row['lines']=$this->db->all('SELECT * FROM ar_invoice_lines WHERE invoice_id=? ORDER BY line_no',[$row['id']]);$row['can_edit']=$this->arCanEdit($row,$u);return $row;
    }
    public function arSaveInvoice(array $d,array $u): array {
        $day=$this->arWriteDate(required($d,'sale_date',10),$u);
        return $this->db->tx(function()use($d,$u,$day){
            $id=trim((string)($d['id']??''));$old=$id!==''?$this->arInvoice(['id'=>$id],$u):null;
            if($old&&(!$old['can_edit']||(int)($d['version']??-1)!==(int)$old['version']))throw new Problem('Invoice changed or has received payments; it cannot be edited.',409);
            if($old&&($old['sale_date']!==$day||$old['customer_id']!==required($d,'customer_id')))throw new Problem('Keep the original invoice customer and date.');
            $customer=$this->find('customers',required($d,'customer_id'));$number=required($d,'invoice_no',80);
            if($this->db->one('SELECT id FROM ar_invoices WHERE invoice_no=? AND id<>?',[$number,$id]))throw new Problem('Invoice number already exists.',409);
            $items=$d['lines']??null;if(!is_array($items)||count($items)<1||count($items)>100)throw new Problem('Add between 1 and 100 invoice products.');
            $id=$old['id']??uid();$lines=[];$total=0;$byAllocation=[];$byProduct=[];
            foreach(array_values($items) as $n=>$item){
                if(!is_array($item))throw new Problem('Invalid invoice line.');
                $pump=required($item,'pump_id');$product=required($item,'product_id');$qty=scaled($item['liters']??'',3);$price=scaled($item['price']??'');
                if($qty<=0||$price<=0)throw new Problem('Litres and price must be positive.');
                $a=$this->db->one('SELECT * FROM ar_allocations WHERE sale_date=? AND pump_id=?',[$day,$pump]);if(!$a||(int)$a['credit_cents']<=0)throw new Problem('Save a positive credit allocation for every selected pump first.');
                $source=$this->db->one('SELECT SUM(liters_ml) liters_ml,SUM(amount_cents) amount_cents,MAX(pump_code) pump_code,MAX(item_code) item_code FROM pump_meter_sales WHERE sale_date=? AND pump_id=? AND product_id=? AND price_cents=?',[$day,$pump,$product,$price]);
                if(!$source||!(int)$source['liters_ml'])throw new Problem('Select a product and selling price recorded in this pump/date sales.');
                $amount=line_total($qty,$price);if($amount<=0)throw new Problem('Line amount must be positive.');
                $key=$pump.'|'.$product.'|'.$price;$byProduct[$key]=($byProduct[$key]??0)+$qty;
                $used=(int)$this->db->value('SELECT COALESCE(SUM(l.liters_ml),0) FROM ar_invoice_lines l JOIN ar_invoices i ON i.id=l.invoice_id WHERE i.sale_date=? AND l.pump_id=? AND l.product_id=? AND l.price_cents=? AND i.id<>?',[$day,$pump,$product,$price,$id]);
                if($used+$byProduct[$key]>(int)$source['liters_ml'])throw new Problem('Credit invoice litres exceed the recorded pump sales.');
                $byAllocation[$a['id']]=($byAllocation[$a['id']]??0)+$amount;
                if($this->arUsed($a['id'],$id)+$byAllocation[$a['id']]>(int)$a['credit_cents'])throw new Problem('Invoice exceeds the remaining credit allocation.');
                $pr=$this->find('products',$product);$lines[]=['id'=>uid(),'invoice_id'=>$id,'line_no'=>$n+1,'allocation_id'=>$a['id'],'pump_id'=>$pump,'product_id'=>$product,'pump_code'=>$source['pump_code'],'product_name'=>$pr['name'],'liters_ml'=>$qty,'price_cents'=>$price,'amount_cents'=>$amount];$total+=$amount;
                if($total>1000000000)throw new Problem('Invoice exceeds the supported amount.');
            }
            // Temporarily remove the uncollected old debit inside this transaction for credit checks.
            if($old)$this->db->run('UPDATE customer_ledger SET debit_cents=0 WHERE id=?',[$old['ledger_id']]);
            $this->validateCredit($customer['id'],$total);
            $ledger=$old['ledger_id']??uid();$at=$this->meterBookedAt($day);
            if($old)$this->db->run('UPDATE customer_ledger SET debit_cents=?,reference=? WHERE id=?',[$total,$number,$ledger]);
            else $this->db->insert('customer_ledger',['id'=>$ledger,'customer_id'=>$customer['id'],'kind'=>'invoice','debit_cents'=>$total,'credit_cents'=>0,'sale_id'=>null,'reference'=>$number,'occurred_at'=>$at,'created_by'=>$u['id']]);
            $row=['id'=>$id,'invoice_no'=>$number,'sale_date'=>$day,'customer_id'=>$customer['id'],'version'=>(int)($old['version']??0)+1,'total_cents'=>$total,'ledger_id'=>$ledger,'created_by'=>$old['created_by']??$u['id'],'updated_at'=>stamp()];
            if($old){$this->db->run('DELETE FROM ar_invoice_lines WHERE invoice_id=?',[$id]);$this->db->run('UPDATE ar_invoices SET invoice_no=?,version=?,total_cents=?,updated_at=? WHERE id=?',[$number,$row['version'],$total,$row['updated_at'],$id]);}else $this->db->insert('ar_invoices',$row);
            foreach($lines as $line)$this->db->insert('ar_invoice_lines',$line);
            $this->db->audit($u['id'],$old?'edit_credit_invoice':'save_credit_invoice','ar_invoice',$id,$row);$this->db->event('ar_invoice',$id,$row+['lines'=>$lines]);return $this->arInvoice(['id'=>$id],$u);
        });
    }
    private function arRequireUnallocated(string $day,string $pump): void {
        if($this->db->one('SELECT id FROM ar_allocations WHERE sale_date=? AND pump_id=?',[$day,$pump]))throw new Problem('This pump/date has a saved receivables allocation. Its source readings and legacy collections are locked.',409);
    }
}
