<?php
declare(strict_types=1);
namespace Petrol;
require_once __DIR__.'/PurchasingBatch.php';
require_once __DIR__.'/PurchaseOrders.php';

trait WorkspaceRegistration {
    use PurchasingBatch, PurchaseOrders;

    public function saveCategory(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $id=trim((string)($d['id']??''));if($id)$this->find('categories',$id);
            $row=['code'=>strtoupper(required($d,'code',32)),'name'=>required($d,'name')];
            if($id)$this->db->run('UPDATE categories SET code=?,name=? WHERE id=?',[$row['code'],$row['name'],$id]);
            else{$id=uid();$this->db->insert('categories',array_merge(['id'=>$id,'created_at'=>stamp()],$row));}
            $this->db->audit($u['id'],'save','category',$id,$row);$this->db->event('category',$id,$row);
            return $this->find('categories',$id);
        });
    }
public function deleteCategory(array $d,array $u): array {

    require_role($u,['admin','manager']);

    return $this->db->tx(function()use($d,$u){

        $id=required($d,'id',64);

        $category=$this->db->one(
            'SELECT id,code,name
             FROM categories
             WHERE id=? FOR UPDATE',
            [$id]
        );

        if(!$category){
            throw new Problem('Category not found.',404);
        }

        $linked=(int)$this->db->value(
            'SELECT COUNT(*)
             FROM products
             WHERE category_id=?',
            [$id]
        );

        if($linked>0){
            throw new Problem(
                'Category is linked to '.$linked.
                ' product(s). Only completely empty categories can be deleted.',
                409
            );
        }

        $this->db->run(
            'DELETE FROM categories WHERE id=?',
            [$id]
        );

        $this->db->audit(
            $u['id'],
            'delete',
            'category',
            $id,
            [
                'code'=>$category['code'],
                'name'=>$category['name']
            ]
        );

        $this->db->event(
            'category_deleted',
            $id,
            ['id'=>$id]
        );

        return [
            'id'=>$id,
            'deleted'=>true
        ];

    });
}

    public function saveProduct(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $id=trim((string)($d['id']??''));$old=$id?$this->find('products',$id):null;
            $category=$this->find('categories',required($d,'category_id'));
            $quantity=scaled($d['quantity']??'0',3);
            $isPumpProduct=(int)$this->db->value('SELECT COUNT(*) FROM settings WHERE SUBSTRING(`key`,1,13)=? AND value=?',['pump_product_',$id])>0;
            if((!$isPumpProduct&&$quantity%1000!==0&&(!$old||$quantity!==(int)$old['stock_milli']))||$quantity>1000000000)throw new Problem($isPumpProduct?'Enter a valid product quantity.':'Enter a valid whole product quantity.');
            if($old&&$quantity!==(int)$old['stock_milli']&&$this->productHasTransactions($id))throw new Problem('Use Purchasing to add stock after transactions have been posted.');
            $row=['sku'=>strtoupper(required($d,'sku',40)),'name'=>required($d,'name'),'stock_milli'=>$quantity,'cost_cents'=>scaled($d['cost']??'0'),'price_cents'=>scaled($d['price']??'0'),'category_id'=>$category['id']];
            if($id)$this->db->run('UPDATE products SET sku=?,name=?,stock_milli=?,cost_cents=?,price_cents=?,category_id=? WHERE id=?',array_merge(array_values($row),[$id]));
            else{$id=uid();$this->db->insert('products',array_merge(['id'=>$id,'unit'=>'each'],$row));}
            $this->db->audit($u['id'],'save','product',$id,$row);$this->db->event('product',$id,$row);return $this->find('products',$id);
        });
    }
    
public function deleteProduct(array $d,array $u): array {

    require_role($u,['admin','manager']);

    return $this->db->tx(function()use($d,$u){

        $id=required($d,'id',64);

        $product=$this->db->one(
            'SELECT * FROM products WHERE id=? FOR UPDATE',
            [$id]
        );

        if(!$product){
            throw new Problem('Product not found.',404);
        }

        if((int)$product['stock_milli']!==0){
            throw new Problem(
                'Product has stock. Reduce stock to zero before deletion.',
                409
            );
        }

        if($this->productHasTransactions($id)){
            throw new Problem(
                'Product has sales or purchasing history. It cannot be permanently deleted.',
                409
            );
        }

        $usedByPump=(int)$this->db->value(
            'SELECT COUNT(*) FROM settings
             WHERE SUBSTRING(`key`,1,13)=?
             AND `value`=?',
            ['pump_product_',$id]
        );

        if($usedByPump>0){
            throw new Problem(
                'Product is linked to a pump. It cannot be deleted.',
                409
            );
        }

        $this->db->run(
            'DELETE FROM products WHERE id=?',
            [$id]
        );

        $this->db->audit(
            $u['id'],'delete','product',$id,
            ['sku'=>$product['sku'],'name'=>$product['name']]
        );

        $this->db->event(
            'product_deleted',$id,['id'=>$id]
        );

        return ['id'=>$id,'deleted'=>true];

    });
}

    public function productHasTransactions(string $id): bool {
        return (int)$this->db->value('SELECT (SELECT COUNT(*) FROM sale_items WHERE product_id=?)+(SELECT COUNT(*) FROM product_purchases WHERE product_id=?)',[$id,$id])>0;
    }
  public function deleteVendor(array $d, array $u): array {
    require_role($u, ['admin', 'manager']);

    return $this->db->tx(function() use ($d, $u) {
        $id = required($d, 'id', 64);

        $vendor = $this->db->one(
            'SELECT * FROM suppliers WHERE id=? FOR UPDATE',
            [$id]
        );

        if (!$vendor) {
            throw new Problem('Vendor not found.', 404);
        }

        $balance = (int)$this->db->value(
            'SELECT balance_cents
             FROM supplier_balances
             WHERE id=?',
            [$id]
        );

        if (
            $balance !== 0 ||
            (int)$vendor['opening_balance_cents'] !== 0
        ) {
            throw new Problem(
                'This vendor has a current or opening balance. '
                .'It cannot be permanently deleted.',
                409
            );
        }

        // Fixed internal table names, not user-supplied values.
        $linkedTables = [
            'deliveries',
            'product_purchases',
            'purchase_invoices',
            'supplier_payments',
            'vendor_payables',
            'party_cheques'
        ];

        foreach ($linkedTables as $table) {
            $linked = (int)$this->db->value(
                "SELECT COUNT(*) FROM `$table` WHERE supplier_id=?",
                [$id]
            );

            if ($linked > 0) {
                throw new Problem(
                    'This vendor has purchasing, payment, payable '
                    .'or cheque history. It cannot be permanently deleted.',
                    409
                );
            }
        }

        $this->db->run(
            'DELETE FROM suppliers WHERE id=?',
            [$id]
        );

        $this->db->audit(
            $u['id'],
            'delete',
            'vendor',
            $id,
            [
                'code' => $vendor['code'],
                'name' => $vendor['name']
            ]
        );

        $this->db->event(
            'vendor_deleted',
            $id,
            ['id' => $id]
        );

        return [
            'id' => $id,
            'deleted' => true
        ];
    });
}  
public function saveVendor(array $d, array $u): array {

    require_role($u, ['admin', 'manager']);

    return $this->db->tx(function() use ($d, $u) {

        $id = trim((string)($d['id'] ?? ''));

        $old = $id
            ? $this->find('supplier_balances', $id)
            : null;

        $opening = scaled(
            $d['current_balance'] ?? '0',
            2,
            true
        );

        if ($old && $opening !== (int)$old['balance_cents']) {
            throw new Problem(
                'Current Balance is calculated from the opening balance, purchases and payments. Use the relevant entry screen to change it.'
            );
        }

        $row = [
            'code' => strtoupper(required($d, 'code', 64)),
            'name' => required($d, 'name'),
            'address' => substr(
                trim((string)($d['address'] ?? '')),
                0,
                1000
            ),
            'city' => substr(
                trim((string)($d['city'] ?? '')),
                0,
                160
            )
        ];

        $phone = substr(
            trim((string)($d['phone'] ?? '')),
            0,
            80
        );

        if ($id) {

            $this->db->run(
                'UPDATE suppliers
                 SET code=?, name=?, address=?, city=?, phone=?
                 WHERE id=?',
                [
                    $row['code'],
                    $row['name'],
                    $row['address'],
                    $row['city'],
                    $phone,
                    $id
                ]
            );

        } else {

            $id = uid();

            $this->db->insert(
                'suppliers',
                array_merge(
                    [
                        'id' => $id,
                        'created_at' => stamp(),
                        'phone' => $phone,
                        'opening_balance_cents' => $opening
                    ],
                    $row
                )
            );
        }

        $this->db->audit(
            $u['id'],
            'save',
            'vendor',
            $id,
            $row
        );

        $this->db->event(
            'vendor',
            $id,
            $row
        );

        return $this->find('supplier_balances', $id);

    });
}

    public function savePump(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $id=trim((string)($d['id']??''));$old=$id?$this->find('pumps',$id):null;
            $start=scaled($d['start_meter']??'0',3);$end=scaled($d['end_meter']??'0',3);
            if($end<$start)throw new Problem('End meter must be equal to or greater than Start meter.');
            $history=$old&&(int)$this->db->value('SELECT COUNT(*) FROM shift_nozzles sn JOIN nozzles n ON n.id=sn.nozzle_id WHERE n.pump_id=?',[$id])>0;
            $history=$history||($old&&(int)$this->db->value('SELECT COUNT(*) FROM pump_meter_sales WHERE pump_id=?',[$id])>0);
            if($history&&($start!==(int)$old['start_meter_ml']||$end!==(int)$old['end_meter_ml']))throw new Problem('This pump has shift history. Record live meter readings in Shifts; keep its registration readings unchanged.');
            $hasProductField=array_key_exists('product_id',$d);
            $currentProductId=$id?trim((string)$this->db->setting('pump_product_'.$id,'')):'';
            $productId=$hasProductField?trim((string)$d['product_id']):$currentProductId;
            if($currentProductId!==''&&$hasProductField&&$productId!==$currentProductId)throw new Problem('This pump’s product mapping is locked and cannot be changed.',409);
            if(!$old&&$productId==='')throw new Problem('Select an active product before saving a new pump.');
            if($productId!==''&&$productId!==$currentProductId){
                $product=$this->find('products',$productId);
                if(!(int)$product['active'])throw new Problem('Select an active product for this pump.');
            }
            if(trim((string)($d['description']??''))===''&&$productId!=='')$d['description']=$this->find('products',$productId)['name'];
            $row=['code'=>strtoupper(required($d,'code',24)),'name'=>required($d,'name'),'description'=>required($d,'description',400),'start_meter_ml'=>$start,'end_meter_ml'=>$end];
            if($id)$this->db->run('UPDATE pumps SET code=?,name=?,description=?,start_meter_ml=?,end_meter_ml=? WHERE id=?',array_merge(array_values($row),[$id]));
            else{$id=uid();$this->db->insert('pumps',array_merge(['id'=>$id],$row));}
            if($currentProductId===''&&$productId!=='')$this->db->set('pump_product_'.$id,$productId);
            $nozzles=$this->db->all('SELECT * FROM nozzles WHERE pump_id=?',[$id]);
            $tank=trim((string)($d['tank_id']??''));
            if(!$nozzles&&$tank!==''){
                $this->find('tanks',$tank);
                $this->db->insert('nozzles',['id'=>uid(),'code'=>$row['code'].'-N1','pump_id'=>$id,'tank_id'=>$tank,'last_meter_ml'=>$end]);
            }elseif(!$history&&count($nozzles)===1&&$old&&$end!==(int)$old['end_meter_ml'])$this->db->run('UPDATE nozzles SET last_meter_ml=? WHERE id=?',[$end,$nozzles[0]['id']]);
            $this->db->audit($u['id'],'save','pump',$id,$row);$this->db->event('pump',$id,$row);return $this->find('pumps',$id);
        });
    }
    
public function deletePump(array $d,array $u): array {

    require_role($u,['admin','manager']);

    return $this->db->tx(function()use($d,$u){

        $id=required($d,'id',64);

        $pump=$this->find('pumps',$id);

        $connections=(int)$this->db->value(
            'SELECT COUNT(*) FROM nozzles WHERE pump_id=?',
            [$id]
        );

        if($connections>0){
            throw new Problem(
                'This pump has nozzle connections. It cannot be permanently deleted while linked to fuel or shift records.',
                409
            );
        }

        $this->db->run(
            'DELETE FROM pumps WHERE id=?',
            [$id]
        );
        $this->db->run(
            'DELETE FROM settings WHERE `key`=?',
            ['pump_product_'.$id]
        );

        $this->db->audit(
            $u['id'],
            'delete',
            'pump',
            $id,
            ['code'=>$pump['code'],'name'=>$pump['name']]
        );

        $this->db->event(
            'pump_deleted',
            $id,
            ['id'=>$id,'code'=>$pump['code']]
        );

        return ['id'=>$id,'deleted'=>true];
    });
}

    public function nextPurchaseNumber(): string {
        $year=(new \DateTimeImmutable('now',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->format('Y');
        $number=(int)$this->db->setting('purchase_seq_'.$year,'0')+1;
        return 'CON-'.$year.'-'.str_pad((string)$number,6,'0',STR_PAD_LEFT);
    }
    private function registerPurchaseDocument(array $d,string $id,string $kind): array {
        $bill=required($d,array_key_exists('company_bill_no',$d)?'company_bill_no':'invoice_no',80);
        $now=new \DateTimeImmutable('now',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo')));
        $day=trim((string)($d['purchase_date']??$now->format('Y-m-d')));
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,$now->getTimezone());
        if(!$date||$date->format('Y-m-d')!==$day)throw new Problem('Enter a valid purchasing date.');
        $received=array_key_exists('purchase_date',$d)?$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'):stamp();
        $group=$this->purchaseBatchDocument;
        if(!$group&&$this->db->one('SELECT purchase_id FROM purchase_invoices WHERE supplier_id=? AND invoice_no=?',[required($d,'supplier_id'),$bill]))throw new Problem('That purchasing number is already used for this vendor.',409);
        if($group){$number=$group['number'];}
        else{
            $number=$this->nextPurchaseNumber();$year=$now->format('Y');
            $this->db->set('purchase_seq_'.$year,(string)((int)$this->db->setting('purchase_seq_'.$year,'0')+1));
        }
        $this->db->insert('purchase_invoices',['supplier_id'=>required($d,'supplier_id'),'invoice_no'=>$bill,'purchase_id'=>$id,'kind'=>$kind,'con_bill_no'=>$number,'purchase_date'=>$day]);
        return ['con_bill_no'=>$number,'company_bill_no'=>$bill,'purchase_date'=>$day,'received_at'=>$received];
    }

    private function purchaseDocumentDate(array $d): array {
        $now=new \DateTimeImmutable('now',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo')));
        $day=required($d,'purchase_date',10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,$now->getTimezone());
        if(!$date||$date->format('Y-m-d')!==$day)throw new Problem('Enter a valid purchasing date.');
        return [$day,$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
    }

    private function purchaseRows(string $id,string $kind): array {
        if(!in_array($kind,['fuel','product'],true))throw new Problem('Unknown purchasing record type.');
        $invoice=$this->db->one('SELECT * FROM purchase_invoices WHERE purchase_id=? AND kind=? FOR UPDATE',[$id,$kind]);
        if(!$invoice)throw new Problem('Purchasing record not found.',404);
        $table=$kind==='fuel'?'deliveries':'product_purchases';
        $row=$this->db->one('SELECT * FROM `'.$table.'` WHERE id=? FOR UPDATE',[$id]);
        if(!$row)throw new Problem('Purchasing record not found.',404);
        if(!empty($row['voided_at']))throw new Problem('This purchasing record has already been voided.',409);
        return [$table,$row,$invoice];
    }

    private function ensurePurchaseInventoryUnconsumed(string $kind,array $itemIds,string $from): void {
        foreach(array_unique(array_filter($itemIds)) as $itemId){
            $used=$kind==='product'
                ? (int)$this->db->value(
                    'SELECT COUNT(*) FROM sale_items i JOIN sales s ON s.id=i.sale_id
                     WHERE i.product_id=? AND s.created_at>=?',
                    [$itemId,$from]
                )
                : (int)$this->db->value(
                    'SELECT COUNT(*) FROM stock_movements
                     WHERE tank_id=? AND quantity_ml<0 AND occurred_at>=?',
                    [$itemId,$from]
                );
            if($used>0)throw new Problem('This purchase cannot change because later sales or stock withdrawals have already used the inventory.');
        }
    }

    private function reviseProductPurchaseStock(?array $remove,?array $add): void {
        $ids=array_values(array_unique(array_filter([
            $remove['product_id']??null,
            $add['product_id']??null
        ])));
        foreach($ids as $id){
            $product=$this->db->one('SELECT * FROM products WHERE id=? FOR UPDATE',[$id]);
            if(!$product)throw new Problem('The product for this purchase no longer exists.',409);
            $removeQty=($remove&&$remove['product_id']===$id)?(int)$remove['quantity_milli']:0;
            $removeCost=($remove&&$remove['product_id']===$id)?(int)$remove['cost_cents']:0;
            $addQty=($add&&$add['product_id']===$id)?(int)$add['quantity_milli']:0;
            $addCost=($add&&$add['product_id']===$id)?(int)$add['cost_cents']:0;
            $stock=(int)$product['stock_milli'];
            if($stock<$removeQty)throw new Problem('This purchase cannot be changed because the product no longer has enough stock.');
            $remainingStock=$stock-$removeQty;
            $remainingValue=$stock*(int)$product['cost_cents']-$removeQty*$removeCost;
            if($remainingValue<0)throw new Problem('This purchase cannot be changed because its inventory cost can no longer be reconciled safely.');
            $newStock=$remainingStock+$addQty;
            if($newStock>1000000000)throw new Problem('Product stock exceeds supported quantity.');
            $newValue=$remainingValue+$addQty*$addCost;
            $newCost=$newStock>0?intdiv($newValue+intdiv($newStock,2),$newStock):0;
            $this->db->run('UPDATE products SET stock_milli=?,cost_cents=? WHERE id=?',[$newStock,$newCost,$id]);
        }
    }

    private function reviseFuelPurchaseStock(?array $remove,?array $add,string $purchaseId,array $u): void {
        $ids=array_values(array_unique(array_filter([
            $remove['tank_id']??null,
            $add['tank_id']??null
        ])));
        foreach($ids as $id){
            $tank=$this->db->one('SELECT * FROM tanks WHERE id=? FOR UPDATE',[$id]);
            if(!$tank)throw new Problem('The tank for this purchase no longer exists.',409);
            $removeQty=($remove&&$remove['tank_id']===$id)?(int)$remove['quantity_ml']:0;
            $removeCost=($remove&&$remove['tank_id']===$id)?(int)$remove['cost_cents_per_l']:0;
            $addQty=($add&&$add['tank_id']===$id)?(int)$add['quantity_ml']:0;
            $addCost=($add&&$add['tank_id']===$id)?(int)$add['cost_cents_per_l']:0;
            $stock=$this->balance($id);
            if($stock<$removeQty)throw new Problem('This purchase cannot be changed because the tank no longer has enough fuel.');
            $remainingStock=$stock-$removeQty;
            $remainingValue=$stock*(int)$tank['cost_cents_per_l']-$removeQty*$removeCost;
            if($remainingValue<0)throw new Problem('This purchase cannot be changed because its fuel cost can no longer be reconciled safely.');
            $newStock=$remainingStock+$addQty;
            if($newStock>(int)$tank['capacity_ml'])throw new Problem('The edited delivery exceeds tank capacity.');
            $newValue=$remainingValue+$addQty*$addCost;
            $newCost=$newStock>0?intdiv($newValue+intdiv($newStock,2),$newStock):0;
            $this->db->run('UPDATE tanks SET cost_cents_per_l=? WHERE id=?',[$newCost,$id]);
            $delta=$addQty-$removeQty;
            if($delta!==0)$this->stock($id,$delta,'adjustment',$purchaseId,'Purchasing inventory correction',$u['id']);
        }
    }

    private function reversePurchasePayment(array $purchase,array $u,string $reason): void {
        $paid=(int)$purchase['paid_cents'];
        if($paid===0||empty($purchase['bank_account_id']))return;
        $matched=(int)$this->db->value(
            'SELECT COUNT(*) FROM bank_transactions
             WHERE purchase_id=? AND matched_at IS NOT NULL',
            [$purchase['id']]
        );
        if($matched>0)throw new Problem('This purchase cannot be changed because its bank payment has been matched to a statement.',409);
        $this->find('bank_accounts',$purchase['bank_account_id']);
        $row=[
            'id'=>uid(),
            'bank_account_id'=>$purchase['bank_account_id'],
            'amount_cents'=>$paid,
            'kind'=>'purchase_payment_reversal',
            'source_type'=>'purchase_adjustment',
            'source_id'=>uid(),
            'purchase_id'=>$purchase['id'],
            'reference'=>$purchase['invoice_no'],
            'note'=>$reason,
            'occurred_at'=>stamp(),
            'created_by'=>$u['id']
        ];
        $this->db->insert('bank_transactions',$row);
        $this->db->event('bank_transaction',$row['id'],$row);
    }

    private function ensurePurchaseBalances(array $old,?array $new): void {
        $oldBalance=(int)$this->db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$old['supplier_id']]);
        $oldOpen=(int)$old['total_cents']-(int)$old['paid_cents'];
        $remainingOld=$oldBalance-$oldOpen;
        if($new&&$new['supplier_id']===$old['supplier_id']){
            $newOpen=(int)$new['total_cents']-(int)$new['paid_cents'];
            if($newOpen===$oldOpen)return;
            if($remainingOld+$newOpen<0)throw new Problem('This change would make the vendor balance negative. Reconcile existing vendor payments first.');
            return;
        }
        if($remainingOld<0)throw new Problem('This change would make the previous vendor balance negative. Reconcile existing vendor payments first.');
        if($new){
            $newBalance=(int)$this->db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$new['supplier_id']]);
            if($newBalance+(int)$new['total_cents']-(int)$new['paid_cents']<0)throw new Problem('This change would make the selected vendor balance negative. Reconcile existing vendor payments first.');
        }
    }

    public function updatePurchase(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $id=required($d,'id',64);
            $kind=required($d,'kind',16);
            [$table,$old,$invoice]=$this->purchaseRows($id,$kind);
            $supplier=$this->find('suppliers',required($d,'supplier_id'));
            [$day,$received]=$this->purchaseDocumentDate($d);
            $dateChanged=$day!==($invoice['purchase_date']??'');
            if(!$dateChanged)$received=$old['received_at'];
            $companyBill=required($d,'company_bill_no',80);
            $grouped=(int)$this->db->value('SELECT COUNT(*) FROM purchase_invoices WHERE con_bill_no=?',[$invoice['con_bill_no']])>1;
            if($grouped&&empty($this->purchaseBatchDocument['order'])&&($supplier['id']!==$old['supplier_id']||$companyBill!==$invoice['invoice_no']||$day!==$invoice['purchase_date']))throw new Problem('For a multi-product bill, keep the existing vendor, bill number and date when editing a product.');

            if((int)$this->db->value(
                'SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id=? AND invoice_no=? AND purchase_id<>? AND (con_bill_no IS NULL OR con_bill_no<>?)',
                [$supplier['id'],$companyBill,$id,$invoice['con_bill_no']]
            )>0)throw new Problem('That purchasing number is already used for this vendor.',409);

            if($kind==='product'){
                $product=$this->find('products',required($d,'product_id'));
                if(!$product['active']&&$product['id']!==$old['product_id'])throw new Problem('Select an active product.');
                $quantity=scaled($d['quantity']??'',3);
                $cost=scaled($d['cost']??'');
                if($quantity<=0||($quantity%1000!==0&&empty($this->purchaseBatchDocument['order'])&&$quantity!==(int)$old['quantity_milli'])||$quantity>1000000000)throw new Problem('Enter positive whole product units.');
                $total=line_total($quantity,$cost);
                if($total<=0)throw new Problem('Check invoice cost.');
                $next=['product_id'=>$product['id'],'quantity_milli'=>$quantity,'cost_cents'=>$cost];
                $stockChanged=$product['id']!==$old['product_id']||(int)$old['quantity_milli']!==$quantity||(int)$old['cost_cents']!==$cost;
                if($stockChanged||$dateChanged){
                    $cutoff=min($old['received_at'],$received);
                    $this->ensurePurchaseInventoryUnconsumed('product',[$old['product_id'],$product['id']],$cutoff);
                }
                if($stockChanged){
                    $this->reviseProductPurchaseStock(
                        ['product_id'=>$old['product_id'],'quantity_milli'=>(int)$old['quantity_milli'],'cost_cents'=>(int)$old['cost_cents']],
                        $next
                    );
                }
            }else{
                $tank=$this->find('tanks',required($d,'tank_id'));
                $quantity=scaled($d['liters']??'',3);
                $cost=scaled($d['cost_per_liter']??'');
                if($quantity<=0||$quantity>1000000000)throw new Problem('Enter a valid positive fuel quantity.');
                $total=line_total($quantity,$cost);
                if($total<=0)throw new Problem('Check delivery cost.');
                $next=['tank_id'=>$tank['id'],'quantity_ml'=>$quantity,'cost_cents_per_l'=>$cost];
                $stockChanged=$tank['id']!==$old['tank_id']||(int)$old['quantity_ml']!==$quantity||(int)$old['cost_cents_per_l']!==$cost;
                if($stockChanged||$dateChanged){
                    $cutoff=min($old['received_at'],$received);
                    $this->ensurePurchaseInventoryUnconsumed('fuel',[$old['tank_id'],$tank['id']],$cutoff);
                }
                if($stockChanged){
                    $this->reviseFuelPurchaseStock(
                        ['tank_id'=>$old['tank_id'],'quantity_ml'=>(int)$old['quantity_ml'],'cost_cents_per_l'=>(int)$old['cost_cents_per_l']],
                        $next,
                        $id,
                        $u
                    );
                }
            }

            $paid=scaled($d['paid_amount']??'0');
            if($paid<0||$paid>$total)throw new Problem('Paid amount exceeds the purchasing invoice.');
            $bankId=$paid?trim((string)($d['bank_account_id']??'')):null;
            if($paid&&$bankId===''){
                if($paid===(int)$old['paid_cents'])$bankId=$old['bank_account_id'];
                if(!$bankId)throw new Problem('Select a bank account for the paid amount.');
            }
            $this->ensurePurchaseBalances(
                $old,
                ['supplier_id'=>$supplier['id'],'total_cents'=>$total,'paid_cents'=>$paid]
            );
            $paymentChanged=$paid!==(int)$old['paid_cents']||$bankId!==$old['bank_account_id'];
            if($paymentChanged){
                $this->reversePurchasePayment($old,$u,'Reversed by purchasing edit.');
                if($paid){
                    $this->bankPost($bankId,-$paid,'purchase_payment','purchase_edit',uid(),$companyBill,$supplier['name'],$u,$received,$id);
                }
            }
            $notes=text_field($d,'notes',1000);
            if($kind==='product'){
                $this->db->run(
                    'UPDATE product_purchases SET supplier_id=?,product_id=?,invoice_no=?,quantity_milli=?,cost_cents=?,total_cents=?,paid_cents=?,bank_account_id=?,notes=?,received_at=? WHERE id=?',
                    [$supplier['id'],$next['product_id'],$companyBill,$next['quantity_milli'],$next['cost_cents'],$total,$paid,$bankId,$notes,$received,$id]
                );
            }else{
                $this->db->run(
                    'UPDATE deliveries SET supplier_id=?,tank_id=?,invoice_no=?,quantity_ml=?,cost_cents_per_l=?,total_cents=?,paid_cents=?,bank_account_id=?,notes=?,received_at=? WHERE id=?',
                    [$supplier['id'],$next['tank_id'],$companyBill,$next['quantity_ml'],$next['cost_cents_per_l'],$total,$paid,$bankId,$notes,$received,$id]
                );
            }
            $this->db->run(
                'UPDATE purchase_invoices SET supplier_id=?,invoice_no=?,purchase_date=? WHERE purchase_id=?',
                [$supplier['id'],$companyBill,$day,$id]
            );
            $result=['id'=>$id,'kind'=>$kind,'company_bill_no'=>$companyBill,'purchase_date'=>$day,'total_cents'=>$total,'paid_cents'=>$paid];
            $this->db->audit($u['id'],'update','purchase',$id,['before'=>$old,'after'=>$result]);
            $this->db->event('purchase_updated',$id,$result);
            return $result;
        });
    }

    public function voidPurchase(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $id=required($d,'id',64);
            $kind=required($d,'kind',16);
            [$table,$purchase]=$this->purchaseRows($id,$kind);
            $this->ensurePurchaseBalances($purchase,null);
            if($kind==='product'){
                $this->ensurePurchaseInventoryUnconsumed('product',[$purchase['product_id']],$purchase['received_at']);
                $this->reviseProductPurchaseStock(
                    ['product_id'=>$purchase['product_id'],'quantity_milli'=>(int)$purchase['quantity_milli'],'cost_cents'=>(int)$purchase['cost_cents']],
                    null
                );
            }else{
                $this->ensurePurchaseInventoryUnconsumed('fuel',[$purchase['tank_id']],$purchase['received_at']);
                $this->reviseFuelPurchaseStock(
                    ['tank_id'=>$purchase['tank_id'],'quantity_ml'=>(int)$purchase['quantity_ml'],'cost_cents_per_l'=>(int)$purchase['cost_cents_per_l']],
                    null,
                    $id,
                    $u
                );
            }
            $reason=text_field($d+['reason'=>'Voided by administrator.'],'reason',1000);
            $this->reversePurchasePayment($purchase,$u,'Reversed by purchasing void.');
            $this->db->run('UPDATE `'.$table.'` SET voided_at=?,voided_by=?,void_reason=? WHERE id=?',[stamp(),$u['id'],$reason,$id]);
            $result=['id'=>$id,'kind'=>$kind,'voided'=>true];
            $this->db->audit($u['id'],'void','purchase',$id,['purchase'=>$purchase,'reason'=>$reason]);
            $this->db->event('purchase_voided',$id,$result);
            return $result;
        });
    }

    public function purchase(array $d,array $u): array {
        require_role($u,['admin','manager']);
        $kind=required($d,'kind');
        if($kind==='product')return $this->productPurchase($d,$u);
        if($kind==='fuel')return $this->delivery($d,$u);
        throw new Problem('Select a product or fuel purchase.');
    }
}
