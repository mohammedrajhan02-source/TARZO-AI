<?php
declare(strict_types=1);
namespace Petrol;

function upgrade_purchase_orders_10(Db $db,array $config): void {
    $lock='efs-schema-'.substr(hash('sha256',(string)$db->value('SELECT DATABASE()')),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another upgrade is running. Try again.',503);
    try{
        if((int)$db->value('SELECT MAX(version) FROM schema_migrations')>=10)return;
        $folder=$config['backup_dir']??dirname(__DIR__).'/var/backups';
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create the upgrade backup folder.',500);
        $db->backup($folder.'/EFS-before-purchase-orders-10-'.gmdate('Ymd-His').'-'.substr(uid(),0,8).'.sql');
        if(!$db->one("SHOW COLUMNS FROM product_purchases LIKE 'sale_price_cents'"))
            $db->pdo->exec('ALTER TABLE product_purchases ADD sale_price_cents BIGINT NULL');
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(10,?)',[stamp()]);
    }finally{$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}

trait PurchaseOrders {
    public function nextPoNumber(): string {
        return 'PO-'.str_pad((string)((int)$this->db->setting('po_sequence','0')+1),4,'0',STR_PAD_LEFT);
    }

    private function purchaseOrderJoin(): string {
        return " FROM purchase_invoices i JOIN suppliers s ON s.id=i.supplier_id
            LEFT JOIN product_purchases p ON i.kind='product' AND p.id=i.purchase_id
            LEFT JOIN deliveries d ON i.kind='fuel' AND d.id=i.purchase_id ";
    }

    public function purchaseOrderReport(array $filter,array $user): array {
        require_role($user,['admin','manager']);
        $conditions=[];$params=[];
        foreach(['from'=>'>=','to'=>'<='] as $field=>$op){
            if(empty($filter[$field]))continue;
            [$day]=$this->purchaseDocumentDate(['purchase_date'=>$filter[$field]]);
            $conditions[]='purchase_date '.$op.' ?';$params[]=$day;
        }
        if(!empty($filter['from'])&&!empty($filter['to'])&&$filter['from']>$filter['to'])throw new Problem('Date From must be on or before Date To.');
        if(!empty($filter['vendor'])){$conditions[]='supplier_id=?';$params[]=required($filter,'vendor',64);}
        if(!empty($filter['po'])){$conditions[]='(LOCATE(UPPER(?),UPPER(con_bill_no))>0 OR LOCATE(UPPER(?),UPPER(invoice_no))>0)';$q=required($filter,'po',80);array_push($params,$q,$q);}
        $offset=max(0,(int)($filter['offset']??0));$print=($filter['print']??'')==='1';$size=$print?5000:50;
        // Voided lines can retain an older header. Apply filters to the active order as a whole.
        $active='COALESCE(p.voided_at,d.voided_at) IS NULL';
        $header=static function(string $column)use($active): string {
            return "COALESCE(MIN(CASE WHEN $active THEN $column END),MIN($column))";
        };
        $summary='SELECT i.con_bill_no,'.$header('i.purchase_date').' purchase_date,'.$header('i.supplier_id').' supplier_id,'
             .$header('s.name').' vendor_name,'.$header('i.invoice_no').' invoice_no,'
             ."SUM(CASE WHEN $active THEN COALESCE(p.total_cents,d.total_cents,0) ELSE 0 END) total_cents,
               SUM(CASE WHEN $active THEN 1 ELSE 0 END) active_lines,
               MAX(COALESCE(p.received_at,d.received_at)) received_at"
             .$this->purchaseOrderJoin().' GROUP BY i.con_bill_no';
        $sql='SELECT * FROM ('.$summary.') grouped_po'.($conditions?' WHERE '.implode(' AND ',$conditions):'').
             ' ORDER BY purchase_date DESC,received_at DESC,con_bill_no DESC LIMIT '.($size+1).' OFFSET '.$offset;
        $rows=$this->db->all($sql,$params);$more=count($rows)>$size;
        if($print&&$more)throw new Problem('Narrow the filters to print up to 5,000 orders.');
        if($more)array_pop($rows);
        return ['rows'=>$rows,'offset'=>$offset,'more'=>$more];
    }

    public function purchaseOrderDetail(array $d,array $user): array {
        require_role($user,['admin','manager']);
        $number=required($d,'po',80);
        $rows=$this->db->all("SELECT i.purchase_id id,i.kind,i.invoice_no,i.purchase_date,i.supplier_id,s.name vendor_name,
              pr.id product_id,d.tank_id,COALESCE(pr.sku,t.code) sku,COALESCE(pr.name,t.name) item_name,
              COALESCE(p.quantity_milli,d.quantity_ml) quantity_milli,COALESCE(p.cost_cents,d.cost_cents_per_l) cost_cents,
              p.sale_price_cents,COALESCE(p.total_cents,d.total_cents) total_cents,COALESCE(p.paid_cents,d.paid_cents) paid_cents,
              COALESCE(p.bank_account_id,d.bank_account_id) bank_account_id,COALESCE(p.notes,d.notes) notes,COALESCE(p.voided_at,d.voided_at) voided_at"
              .$this->purchaseOrderJoin()." LEFT JOIN products pr ON pr.id=p.product_id LEFT JOIN tanks t ON t.id=d.tank_id
              WHERE i.con_bill_no=? ORDER BY COALESCE(p._seq,0),i.purchase_id",[$number]);
        if(!$rows)throw new Problem('Purchase order not found.',404);
        return ['po'=>$number,'rows'=>$rows,'version'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
    }

    public function savePurchaseOrder(array $data,array $user): array {
        require_role($user,['admin','manager']);
        return $this->db->tx(function()use($data,$user){
            $editing=!empty($data['po']);
            $number=$editing?required($data,'po',80):$this->nextPoNumber();
            $old=[];
            if($editing){
                $order=$this->purchaseOrderDetail(['po'=>$number],$user);
                if(!hash_equals($order['version'],required($data,'version',64)))throw new Problem('This PO changed. Reopen it before editing.',409);
                foreach($order['rows'] as $row)if(!$row['voided_at'])$old[$row['id']]=$row;
                if(!$old)throw new Problem('This order was voided.',409);
            }else{
                $this->db->set('po_sequence',(string)((int)$this->db->setting('po_sequence','0')+1));
            }
            if(($data['void_all']??false)===true){
                if(!$editing)throw new Problem('Select a saved PO to void.');
                foreach($old as $id=>$row)$this->voidPurchase(['id'=>$id,'kind'=>$row['kind'],'reason'=>'Void order '.$number],$user);
                return ['po'=>$number];
            }
            $own=text_field($data,'company_bill_no',80);
            $header=['purchase_date'=>required($data,'purchase_date',10),'supplier_id'=>required($data,'supplier_id',64),
                     'company_bill_no'=>$own===''?$number:$own];
            $this->purchaseDocumentDate($header);$this->find('suppliers',$header['supplier_id']);
            if($this->db->one('SELECT purchase_id FROM purchase_invoices WHERE supplier_id=? AND invoice_no=? AND con_bill_no<>?',[$header['supplier_id'],$header['company_bill_no'],$number]))
                throw new Problem('This vendor already has that invoice number.',409);
            $items=$data['items']??null;
            if(!is_array($items)||!count($items)||count($items)>200)throw new Problem('Add between 1 and 200 items.');
            $seen=[];$this->purchaseBatchDocument=['number'=>$number,'order'=>true];
            try{
                foreach($items as $item){
                    if(!is_array($item))throw new Problem('Invalid item.');
                    $id=text_field($item,'id',64);$saved=$old[$id]??null;
                    if($id!==''&&(!$saved||isset($seen[$id])))throw new Problem('Invalid purchase line.',409);
                    $line=$header+['notes'=>$item['notes']??'','paid_amount'=>$item['paid_amount']??'0','bank_account_id'=>$item['bank_account_id']??''];
                    if($saved&&$saved['kind']==='fuel'){
                        $this->updatePurchase($line+['id'=>$id,'kind'=>'fuel','tank_id'=>$saved['tank_id'],'liters'=>$item['quantity']??'','cost_per_liter'=>$item['cost']??''],$user);
                    }else{
                        $line+=['product_id'=>required($item,'product_id'),'quantity'=>$item['quantity']??'','cost'=>$item['cost']??''];
                        $price=($item['sale_price']??'')===''?null:scaled($item['sale_price']);
                        if($price!==null&&$price>10000000)throw new Problem('Sale price exceeds the supported range.');
                        if(!$saved&&$price===null)throw new Problem('Enter a sale price.');
                        if($saved)$this->updatePurchase($line+['id'=>$id,'kind'=>'product'],$user);
                        else $id=$this->productPurchase($line,$user)['id'];
                        $this->db->run('UPDATE product_purchases SET sale_price_cents=? WHERE id=?',[$price,$id]);
                    }
                    $seen[$id]=true;
                }
                foreach($old as $id=>$row)if(!isset($seen[$id]))$this->voidPurchase(['id'=>$id,'kind'=>$row['kind'],'reason'=>'Item removed from '.$number],$user);
            }finally{$this->purchaseBatchDocument=null;}
            return ['po'=>$number];
        });
    }
}
