<?php
declare(strict_types=1);
namespace Petrol;

function upgrade_purchasing_7(Db $db,array $config): void {
    $database=(string)$db->value('SELECT DATABASE()');
    $lock='efs-schema-'.substr(hash('sha256',$database),0,24);
    if((int)$db->value('SELECT GET_LOCK(?,15)',[$lock])!==1)throw new Problem('Another database upgrade is running. Try again.',503);
    try{
        if((int)$db->value('SELECT MAX(version) FROM schema_migrations')>=7)return;
        $folder=(string)($config['backup_dir']??dirname(__DIR__).'/var/backups');
        if(!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder))throw new Problem('Cannot create the pre-upgrade backup folder.',500);
        $db->backup($folder.'/EFS-before-purchasing-7-'.gmdate('Ymd-His').'-'.substr(uid(),0,8).'.sql');
        $pk=$db->all("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='purchase_invoices' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX",[$database]);
        if(count($pk)===2)$db->pdo->exec('ALTER TABLE purchase_invoices DROP PRIMARY KEY, ADD PRIMARY KEY(supplier_id,invoice_no,purchase_id)');
        foreach([['purchase_invoices','uq_purchase_con_bill','con_bill_no'],['product_purchases','uq_product_purchases_supplier_id_invoice_no','supplier_id,invoice_no']] as [$table,$index,$columns]){
            $exists=(int)$db->value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',[$database,$table,$index]);
            $replacement='ix_batch_'.$table;
            if(!(int)$db->value('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=?',[$database,$table,$replacement]))$db->pdo->exec("ALTER TABLE `$table` ADD INDEX `$replacement` ($columns)");
            if($exists)$db->pdo->exec("ALTER TABLE `$table` DROP INDEX `$index`");
        }
        $db->run('INSERT INTO schema_migrations(version,applied_at) VALUES(7,?) ON DUPLICATE KEY UPDATE applied_at=VALUES(applied_at)',[stamp()]);
    }finally{$db->value('SELECT RELEASE_LOCK(?)',[$lock]);}
}

trait PurchasingBatch {
    private ?array $purchaseBatchDocument=null;

    public function purchaseBatch(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $supplier=required($d,'supplier_id');
            $bill=required($d,'company_bill_no',80);
            $this->find('suppliers',$supplier);
            if($this->db->one('SELECT purchase_id FROM purchase_invoices WHERE supplier_id=? AND invoice_no=?',[$supplier,$bill]))throw new Problem('That purchasing number is already used for this vendor.',409);
            $items=$d['items']??null;
            if(!is_array($items)||!count($items))throw new Problem('Add at least one product.');
            $number=$this->nextPurchaseNumber();
            $year=(new \DateTimeImmutable('now',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->format('Y');
            $this->db->set('purchase_seq_'.$year,(string)((int)$this->db->setting('purchase_seq_'.$year,'0')+1));
            $this->purchaseBatchDocument=['number'=>$number];
            $rows=[];$total=0;$paid=0;
            try{
                foreach($items as $index=>$item){
                    if(!is_array($item))throw new Problem('Invalid product row.');
                    $row=$this->productPurchase([
                        'supplier_id'=>$supplier,'company_bill_no'=>$bill,
                        'purchase_date'=>required($d,'purchase_date',10),
                        'product_id'=>required($item,'product_id'),
                        'quantity'=>$item['quantity']??'', 'cost'=>$item['cost']??'',
                        'paid_amount'=>$item['paid_amount']??'0',
                        'bank_account_id'=>$item['bank_account_id']??'',
                        'notes'=>$item['notes']??''
                    ],$u);
                    $rows[]=$row['id'];$total+=$row['total_cents'];$paid+=$row['paid_cents'];
                }
            }finally{$this->purchaseBatchDocument=null;}
            return ['ids'=>$rows,'con_bill_no'=>$number,'company_bill_no'=>$bill,'total_cents'=>$total,'paid_cents'=>$paid];
        });
    }
}


