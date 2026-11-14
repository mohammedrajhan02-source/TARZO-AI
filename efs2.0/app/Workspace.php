<?php
declare(strict_types=1);
namespace Petrol;
require_once __DIR__.'/WorkspaceRegistration.php';
require_once __DIR__.'/AccountsPayable.php';

/** Workspace registers and operational ledgers. All money is integer cents. */
trait Workspace {
    use WorkspaceRegistration, AccountsPayable;
    public function workOnce(string $action,array $d,array $u,callable $operation): array {
        return $this->db->tx(function()use($action,$d,$u,$operation){
            $key=required($d,'request_key',100);
            $hash=hash('sha256',json_encode($d,JSON_THROW_ON_ERROR));
            $old=$this->db->one('SELECT * FROM workspace_requests WHERE request_key=?',[$key]);
            if($old){
                if($old['user_id']!==$u['id']||$old['action']!==$action||$old['request_hash']!==$hash)throw new Problem('This request was already used with different details. Review the saved entry first.',409);
                return json_decode($old['result_json'],true,64,JSON_THROW_ON_ERROR);
            }
            $result=$operation();
            $this->db->insert('workspace_requests',['request_key'=>$key,'user_id'=>$u['id'],'action'=>$action,'request_hash'=>$hash,'result_json'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>stamp()]);
            return $result;
        });
    }

    public function ensureEmployee(string $userId): void {
        $u=$this->find('users',$userId);
        if($this->db->one('SELECT id FROM employees WHERE user_id=?',[$userId]))return;
        $this->db->insert('employees',['id'=>uid(),'code'=>'EMP-'.substr($userId,0,12),'name'=>$u['name'],'user_id'=>$userId,'active'=>$u['active'],'created_at'=>stamp()]);
    }

    public function saveEmployee(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $id=trim((string)($d['id']??''));$old=$id?$this->find('employees',$id):null;
            $shift=(string)($d['shift_type']??($old['shift_type']??'day'));
            if(!in_array($shift,['day','night'],true))throw new Problem('Select Day or Night for Employee Shift.');
            $row=['code'=>strtoupper(required($d,'code',32)),'name'=>required($d,'name'),'phone'=>text_field($d,'phone',50),'designation'=>text_field($d+['designation'=>$old['designation']??'Attendant'],'designation',160),'address'=>text_field($d+['address'=>$old['address']??''],'address',1000),'shift_type'=>$shift];
            // Changing login access is reserved for Users Admin; this is the staff register.
            if($old){$this->db->run('UPDATE employees SET code=?,name=?,phone=?,designation=?,address=?,shift_type=? WHERE id=?',array_merge(array_values($row),[$id]));}
            else{$id=uid();$this->db->insert('employees',array_merge(['id'=>$id],$row,['created_at'=>stamp()]));}
            $this->db->audit($u['id'],$old?'update':'create','employee',$id,$row);$this->db->event('employee',$id,array_merge(['id'=>$id],$row));
            return $this->find('employees',$id);
        });
    }

    public function linkEmployee(array $d,array $u): array {
        require_role($u,['admin']);
        return $this->db->tx(function()use($d,$u){
            $e=$this->find('employees',required($d,'employee_id'));$userId=trim((string)($d['user_id']??''))?:null;
            if($userId)$this->find('users',$userId);
            if($e['user_id']&&$this->db->one('SELECT id FROM shifts WHERE user_id=? AND status=\'open\'',[$e['user_id']]))throw new Problem('Close the employee’s shift before changing their linked login.');
            if($userId&&$this->db->one('SELECT id FROM employees WHERE user_id=? AND id<>?',[$userId,$e['id']]))throw new Problem('That login is already linked to another employee.');
            $this->db->run('UPDATE employees SET user_id=? WHERE id=?',[$userId,$e['id']]);
            $this->db->audit($u['id'],'link_login','employee',$e['id'],['user_id'=>$userId]);$this->db->event('employee',$e['id'],$this->find('employees',$e['id']));return ['id'=>$e['id']];
        });
    }

    public function employeeStatus(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $e=$this->find('employees',required($d,'id'));$active=!empty($d['active'])?1:0;
            if(!$active&&$e['user_id']&&$this->db->one('SELECT id FROM shifts WHERE user_id=? AND status=\'open\'',[$e['user_id']]))throw new Problem('Close the active shift first.');
            $this->db->run('UPDATE employees SET active=? WHERE id=?',[$active,$e['id']]);
            $this->db->audit($u['id'],'status','employee',$e['id'],['active'=>$active]);$this->db->event('employee',$e['id'],$this->find('employees',$e['id']));return ['id'=>$e['id'],'active'=>$active];
        });
    }
    public function deleteEmployee(
    array $d,
    array $u
): array {

    require_role(
        $u,
        ['admin', 'manager']
    );

    return $this->db->tx(
        function() use ($d, $u) {

            $id = required(
                $d,
                'id',
                64
            );

            $employee = $this->db->one(
                'SELECT *
                 FROM employees
                 WHERE id=?
                 FOR UPDATE',
                [$id]
            );

            if (!$employee) {
                throw new Problem(
                    'Employee not found.',
                    404
                );
            }

            if (
                $employee['user_id'] &&
                $this->db->one(
                    'SELECT id
                     FROM shifts
                     WHERE user_id=?
                     AND status=\'open\'',
                    [$employee['user_id']]
                )
            ) {
                throw new Problem(
                    'Close the employee’s active shift before deleting the employee.',
                    409
                );
            }

            $this->db->run(
                'DELETE FROM employees
                 WHERE id=?',
                [$id]
            );

            $this->db->audit(
                $u['id'],
                'delete',
                'employee',
                $id,
                [
                    'code' => $employee['code'],
                    'name' => $employee['name'],
                    'user_id' => $employee['user_id']
                ]
            );

            $this->db->event(
                'employee_deleted',
                $id,
                ['id' => $id]
            );

            return [
                'id' => $id,
                'deleted' => true
            ];
        }
    );
}

    public function bankBalance(string $id): int {
        return (int)$this->db->value('SELECT COALESCE(SUM(amount_cents),0) FROM bank_transactions WHERE bank_account_id=?',[$id]);
    }

    public function userAccess(array $d,array $u): array {
        require_role($u,['admin']);return $this->db->tx(function()use($d,$u){
            $target=$this->find('users',required($d,'id'));$role=required($d,'role');
            if(!in_array($role,['admin','manager','cashier','viewer'],true))throw new Problem('Unknown role.');
            if($target['id']===$u['id']&&$role!=='admin')throw new Problem('Use a different owner account to change your own admin role.');
            if($role==='viewer'&&$this->db->one('SELECT id FROM shifts WHERE user_id=? AND status=\'open\'',[$target['id']]))throw new Problem('Close the operator’s active shift before making them a viewer.');
            $password=(string)($d['password']??'');
            if($password!=='')$password=account_password($d);
            $this->db->run('UPDATE users SET role=? WHERE id=?',[$role,$target['id']]);
            if($password!=='')$this->db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$target['id']]);
            $this->ensureEmployee($target['id']);
            $this->db->audit($u['id'],'access_update','user',$target['id'],['role'=>$role,'password_reset'=>$password!=='']);
            return ['id'=>$target['id'],'role'=>$role];
        });
    }

    public function bankPost(string $account,int $amount,string $kind,string $source,string $sourceId,string $reference,string $note,array $u,?string $occurredAt=null,?string $purchaseId=null): array {
        $a=$this->find('bank_accounts',$account);
        if(!$a['active'])throw new Problem('Select an active bank account.');
        if($amount===0)throw new Problem('Bank amount must be nonzero.');
        if(!$a['allow_overdraft']&&$this->bankBalance($account)+$amount<0)throw new Problem('Insufficient recorded bank balance. Record the opening balance or missing deposits first.');
        $row=['id'=>uid(),'bank_account_id'=>$account,'amount_cents'=>$amount,'kind'=>$kind,'source_type'=>$source,'source_id'=>$sourceId,'purchase_id'=>$purchaseId,'reference'=>$reference,'note'=>$note,'occurred_at'=>$occurredAt??stamp(),'created_by'=>$u['id']];
        $this->db->insert('bank_transactions',$row);$this->db->event('bank_transaction',$row['id'],$row);return $row;
    }

    public function createBank(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $opening=scaled($d['opening_balance']??'0',2,true);$overdraft=!empty($d['allow_overdraft'])?1:0;
            if($opening<0&&!$overdraft)throw new Problem('A negative opening balance requires overdraft permission.');
            $row=['id'=>uid(),'code'=>strtoupper(required($d,'code',32)),'name'=>required($d,'name'),'bank_name'=>required($d,'bank_name'),'account_ref'=>text_field($d,'account_ref',40),'allow_overdraft'=>$overdraft,'created_at'=>stamp()];
            $this->db->insert('bank_accounts',$row);
            if($opening)$this->bankPost($row['id'],$opening,'opening','bank_opening',$row['id'],'Opening balance','Balance at registration; not revenue.',$u);
            $this->db->audit($u['id'],'create','bank_account',$row['id'],$row);$this->db->event('bank_account',$row['id'],$row);return $row;
        });
    }

    public function bankMove(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $account=required($d,'bank_account_id');$amount=scaled($d['amount']??'');if($amount<=0)throw new Problem('Amount must be positive.');
            $kind=required($d,'kind');$ref=required($d,'reference');$note=required($d,'note');$id=uid();
            if($kind==='transfer'){
                $to=required($d,'to_account_id');if($to===$account)throw new Problem('Choose two different accounts.');
                $this->bankPost($account,-$amount,'transfer_out','bank_transfer',$id,$ref,$note,$u);
                $this->bankPost($to,$amount,'transfer_in','bank_transfer',$id,$ref,$note,$u);
            }elseif(in_array($kind,['shift_deposit','shift_withdrawal'],true)){
                $shift=$this->requireShift(required($d,'shift_id'),$u);$deposit=$kind==='shift_deposit';
                if($deposit&&$amount>$this->shiftTotals($shift['id'])['expected_cash'])throw new Problem('Deposit exceeds the shift’s expected drawer cash.');
                $this->bankPost($account,$deposit?$amount:-$amount,$kind,'bank_cash_move',$id,$ref,$note,$u);
                $this->db->insert('cash_movements',['id'=>uid(),'shift_id'=>$shift['id'],'kind'=>$deposit?'drop':'paid_in','amount_cents'=>$deposit?-$amount:$amount,'reason'=>$note,'reference_id'=>$id,'created_at'=>stamp(),'created_by'=>$u['id']]);
            }elseif(in_array($kind,['other_credit','other_debit'],true)){
                $this->bankPost($account,$kind==='other_credit'?$amount:-$amount,$kind,'bank_manual',$id,$ref,$note,$u);
            }else throw new Problem('Unknown bank movement.');
            $result=['id'=>$id,'kind'=>$kind,'amount_cents'=>$amount];$this->db->audit($u['id'],'post','bank_movement',$id,array_merge($result,['reference'=>$ref,'note'=>$note]));$this->db->event('bank_movement',$id,$result);return $result;
        });
    }

    public function bankMatch(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){
            $t=$this->find('bank_transactions',required($d,'id'));$ref=required($d,'statement_ref');
            if($t['matched_at'])throw new Problem('This bank entry is already matched.');
            $this->db->run('UPDATE bank_transactions SET matched_at=?,matched_by=?,statement_ref=? WHERE id=?',[stamp(),$u['id'],$ref,$t['id']]);
            $this->db->audit($u['id'],'match_statement','bank_transaction',$t['id'],['statement_ref'=>$ref]);$this->db->event('bank_match',$t['id'],['statement_ref'=>$ref,'matched_at'=>stamp()]);return ['id'=>$t['id']];
        });
    }

    public function productPurchase(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){
            $p=$this->find('products',required($d,'product_id'));if(!$p['active'])throw new Problem('Product is inactive.');
            $supplier=$this->find('suppliers',required($d,'supplier_id'));$qty=scaled($d['quantity']??'',3);$cost=scaled($d['cost']??'');
            if($qty<=0||($qty%1000!==0&&empty($this->purchaseBatchDocument['order']))||$qty>1000000000)throw new Problem('Enter a valid positive quantity.');
            $total=line_total($qty,$cost);$paid=scaled($d['paid_amount']??'0');if($total<=0||$paid>$total)throw new Problem('Check invoice cost and payment amount.');
            $id=uid();$document=$this->registerPurchaseDocument($d,$id,'product');$invoice=$document['company_bill_no'];$bank=$paid?required($d,'bank_account_id'):null;
            $row=['id'=>$id,'supplier_id'=>$supplier['id'],'product_id'=>$p['id'],'invoice_no'=>$invoice,'quantity_milli'=>$qty,'cost_cents'=>$cost,'total_cents'=>$total,'paid_cents'=>$paid,'bank_account_id'=>$bank,'notes'=>text_field($d,'notes',1000),'received_at'=>$document['received_at'],'created_by'=>$u['id']];
            $this->db->insert('product_purchases',$row);$stock=$p['stock_milli']+$qty;
            if($stock>1000000000)throw new Problem('Product stock exceeds supported quantity.');
            $average=intdiv($p['stock_milli']*$p['cost_cents']+$qty*$cost+intdiv($stock,2),$stock);
            $this->db->run('UPDATE products SET stock_milli=?,cost_cents=? WHERE id=?',[$stock,$average,$p['id']]);
            if($paid)$this->bankPost($bank,-$paid,'purchase_payment','product_purchase',$id,$invoice,$supplier['name'],$u,$row['received_at'],$id);
            $result=array_merge($row,$document);$this->db->audit($u['id'],'receive','product_purchase',$id,$result);$this->db->event('product_purchase',$id,$result);return $result;
        });
    }

    public function pumpReceivables(): array {
        return $this->db->all("SELECT st.*,s.label,u.name user_name,
            (SELECT GROUP_CONCAT(p.code, ', ') FROM pumps p WHERE p.id IN (SELECT n.pump_id FROM shift_nozzles sn JOIN nozzles n ON n.id=sn.nozzle_id WHERE sn.shift_id=st.shift_id)) pump_codes,
            -st.variance_cents shortage_cents,COALESCE((SELECT SUM(r.amount_cents) FROM pump_receipts r WHERE r.settlement_id=st.id),0) recovered_cents,
            -st.variance_cents-COALESCE((SELECT SUM(r.amount_cents) FROM pump_receipts r WHERE r.settlement_id=st.id),0) outstanding_cents
            FROM settlements st JOIN shifts s ON s.id=st.shift_id JOIN users u ON u.id=s.user_id WHERE st.variance_cents<0 ORDER BY st.created_at DESC,st._seq DESC");
    }

    public function pumpReceipt(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){
            $s=$this->find('settlements',required($d,'settlement_id'));$due=max(0,-(int)$s['variance_cents'])-(int)$this->db->value('SELECT COALESCE(SUM(amount_cents),0) FROM pump_receipts WHERE settlement_id=?',[$s['id']]);
            $amount=scaled($d['amount']??'');if($amount<=0||$amount>$due)throw new Problem('Collection must be positive and no more than the outstanding shortage.');
            $method=required($d,'method');if(!in_array($method,['cash','bank'],true))throw new Problem('Unknown receipt method.');
            $shift=$method==='cash'?$this->requireShift(required($d,'shift_id'),$u):null;
            $bank=$method==='bank'?required($d,'bank_account_id'):null;
            $row=['id'=>uid(),'settlement_id'=>$s['id'],'amount_cents'=>$amount,'method'=>$method,'shift_id'=>$shift['id']??null,'bank_account_id'=>$bank,'reference'=>required($d,'reference'),'created_at'=>stamp(),'created_by'=>$u['id']];
            $this->db->insert('pump_receipts',$row);
            if($shift)$this->db->insert('cash_movements',['id'=>uid(),'shift_id'=>$shift['id'],'kind'=>'paid_in','amount_cents'=>$amount,'reason'=>'Shift shortage recovery · '.$row['reference'],'reference_id'=>$row['id'],'created_at'=>stamp(),'created_by'=>$u['id']]);
            else $this->bankPost($bank,$amount,'pump_receipt','pump_receipt',$row['id'],$row['reference'],'Shift shortage recovery',$u);
            $this->db->audit($u['id'],'recover_shortage','settlement',$s['id'],$row);$this->db->event('pump_receipt',$row['id'],$row);return $row;
        });
    }

    public function workspaceSnapshot(array $u): array {
        $db=$this->db;$manager=in_array($u['role'],['admin','manager'],true);
        $result=['categories'=>$db->all('SELECT * FROM categories ORDER BY code'),'bank_options'=>$db->all('SELECT id,code,name FROM bank_accounts WHERE active=1 ORDER BY name')];
        if($manager){
            $result['purchase_defaults']=['con_bill_no'=>$this->nextPurchaseNumber(),'po_number'=>$this->nextPoNumber(),'date'=>(new \DateTimeImmutable('now',new \DateTimeZone($db->setting('timezone','Asia/Colombo'))))->format('Y-m-d')];
            $result['employees']=$db->all('SELECT e.*,u.username,u.role,u.active login_active FROM employees e LEFT JOIN users u ON u.id=e.user_id ORDER BY e.name');
            $result['banks']=$db->all('SELECT * FROM bank_balances ORDER BY name');
            $result['bank_entries']=$db->all('SELECT t.*,b.name account_name FROM bank_transactions t JOIN bank_accounts b ON b.id=t.bank_account_id ORDER BY t.occurred_at DESC,t._seq DESC LIMIT 100');
            $result['product_purchases']=$db->all('SELECT pp.*,p.name product_name,s.name supplier_name,s.code supplier_code,i.con_bill_no,i.purchase_date,i.invoice_no company_bill_no FROM product_purchases pp JOIN products p ON p.id=pp.product_id JOIN suppliers s ON s.id=pp.supplier_id LEFT JOIN purchase_invoices i ON i.purchase_id=pp.id ORDER BY pp.received_at DESC,pp._seq DESC LIMIT 100');
            $result['supplier_payments']=$db->all('SELECT p.*,s.name supplier_name,b.name bank_name FROM supplier_payments p JOIN suppliers s ON s.id=p.supplier_id LEFT JOIN bank_accounts b ON b.id=p.bank_account_id ORDER BY p.created_at DESC,p._seq DESC LIMIT 100');
            $result['party_cheques']=$db->all("SELECT * FROM party_cheques WHERE status='available' ORDER BY cheque_date,cheque_no");
            $result['pump_receivables']=$this->pumpReceivables();
            $result['pump_receipts']=$db->all('SELECT r.*,s.label,u.name user_name FROM pump_receipts r JOIN settlements st ON st.id=r.settlement_id JOIN shifts s ON s.id=st.shift_id JOIN users u ON u.id=s.user_id ORDER BY r.created_at DESC,r._seq DESC LIMIT 100');
        }
        $result['customer_receipts']=$db->all("SELECT l.*,c.name customer_name FROM customer_ledger l JOIN customers c ON c.id=l.customer_id WHERE l.kind='receipt' ORDER BY l.occurred_at DESC,l._seq DESC LIMIT 100");
        $result['credit_aging']=$db->all("SELECT c.id,COUNT(l.id) unpaid_count,COALESCE(SUM(l.remaining),0) outstanding_cents,MIN(l.occurred_at) oldest_at FROM customers c LEFT JOIN
          (SELECT l.*,l.debit_cents-COALESCE((SELECT SUM(a.amount_cents) FROM customer_allocations a WHERE a.invoice_entry_id=l.id),0) remaining FROM customer_ledger l WHERE l.debit_cents>0) l ON l.customer_id=c.id AND l.remaining>0 GROUP BY c.id");
        return $result;
    }

    public function statementRange(array $d): array {
        $tz=new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'));
        $today=(new \DateTimeImmutable('now',$tz))->format('Y-m-d');$from=(string)($d['from']??substr($today,0,8).'01');$to=(string)($d['to']??$today);
        foreach([$from,$to]as $day){$p=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,$tz);if(!$p||$p->format('Y-m-d')!==$day)throw new Problem('Choose valid statement dates.');}
        if($from>$to)throw new Problem('The end date must be on or after the start date.');
        $start=(new \DateTimeImmutable($from.' 00:00:00',$tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $end=(new \DateTimeImmutable($to.' 00:00:00',$tz))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        return ['from'=>$from,'to'=>$to,'start'=>$start,'end'=>$end];
    }

    public function ledgerStatement(array $d,array $u): array {
        $kind=required($d,'kind');$id=required($d,'id');$range=$this->statementRange($d);$db=$this->db;
        if($kind==='vendor'){
            require_role($u,['admin','manager']);$account=$this->find('suppliers',$id);
            $rows=$db->all("SELECT * FROM (
                SELECT id,created_at occurred_at,'Opening balance' reference,'Vendor opening balance' description,GREATEST(opening_balance_cents,0) debit_cents,GREATEST(-opening_balance_cents,0) credit_cents,-1 sort,0 entry_seq FROM suppliers WHERE id=? AND opening_balance_cents<>0
                UNION ALL SELECT id,received_at,invoice_no,'Fuel purchase',total_cents,0,0,0 FROM deliveries WHERE supplier_id=? AND voided_at IS NULL
                UNION ALL SELECT id,received_at,invoice_no,'Paid at fuel purchase',0,paid_cents,1,0 FROM deliveries WHERE supplier_id=? AND paid_cents>0 AND voided_at IS NULL
                UNION ALL SELECT id,received_at,invoice_no,'Product purchase',total_cents,0,0,_seq FROM product_purchases WHERE supplier_id=? AND voided_at IS NULL
                UNION ALL SELECT id,received_at,invoice_no,'Paid at product purchase',0,paid_cents,1,_seq FROM product_purchases WHERE supplier_id=? AND paid_cents>0 AND voided_at IS NULL
                UNION ALL SELECT id,occurred_at,'Payable entry',description,amount_cents,0,0,_seq FROM vendor_payables WHERE supplier_id=?
                UNION ALL SELECT id,COALESCE(occurred_at,created_at),reference,CONCAT('Vendor payment · ',REPLACE(method,'_',' '),IF(cheque_no IS NULL,'',CONCAT(' · Cheque ',cheque_no))),0,amount_cents,2,_seq FROM supplier_payments WHERE supplier_id=?
            ) AS vendor_entries WHERE occurred_at<? ORDER BY occurred_at,sort,entry_seq,id",[$id,$id,$id,$id,$id,$id,$id,$range['end']]);
        }elseif($kind==='customer'){
            $account=$this->find('customers',$id);
            $rows=$db->all('SELECT id,occurred_at,reference,kind description,debit_cents,credit_cents FROM customer_ledger WHERE customer_id=? AND occurred_at<? ORDER BY occurred_at,_seq',[$id,$range['end']]);
        }elseif($kind==='bank'){
            require_role($u,['admin','manager']);$account=$this->find('bank_accounts',$id);
            $rows=$db->all('SELECT id,occurred_at,reference,kind description,GREATEST(amount_cents,0) debit_cents,GREATEST(-amount_cents,0) credit_cents,matched_at,statement_ref FROM bank_transactions WHERE bank_account_id=? AND occurred_at<? ORDER BY occurred_at,_seq',[$id,$range['end']]);
        }elseif($kind==='pump'){
            $account=$this->find('pumps',$id);
            $rows=$db->all("SELECT mp.*,n.code nozzle_code,s.label shift_label,u.name operator_name,s.status shift_status,
              COALESCE((SELECT SUM(f.quantity_ml) FROM fuel_tests f WHERE f.meter_period_id=mp.id),0) test_ml,
              COALESCE((SELECT SUM(i.quantity_milli) FROM sale_items i WHERE i.meter_period_id=mp.id),0) billed_ml,
              COALESCE((SELECT SUM(i.line_cents) FROM sale_items i WHERE i.meter_period_id=mp.id),0) billed_cents
              FROM meter_periods mp JOIN shift_nozzles sn ON sn.id=mp.shift_nozzle_id JOIN nozzles n ON n.id=sn.nozzle_id JOIN shifts s ON s.id=sn.shift_id JOIN users u ON u.id=s.user_id
              WHERE n.pump_id=? AND COALESCE(mp.ended_at,mp.started_at)>=? AND COALESCE(mp.ended_at,mp.started_at)<? ORDER BY mp.started_at,mp._seq",[$id,$range['start'],$range['end']]);
            foreach($rows as &$row){$row['net_ml']=$row['closing_ml']===null?null:$row['closing_ml']-$row['opening_ml']-$row['test_ml'];$row['meter_value_cents']=$row['net_ml']===null?null:line_total((int)$row['net_ml'],(int)$row['rate_cents']);}unset($row);
            return ['kind'=>$kind,'account'=>$account,'from'=>$range['from'],'to'=>$range['to'],'rows'=>$rows];
        }else throw new Problem('Unknown statement type.');
        $opening=0;$balance=0;$debits=0;$credits=0;$visible=[];
        foreach($rows as $r){$delta=(int)$r['debit_cents']-(int)$r['credit_cents'];$balance+=$delta;if($r['occurred_at']<$range['start']){$opening+=$delta;continue;}$r['balance_cents']=$balance;$visible[]=$r;$debits+=(int)$r['debit_cents'];$credits+=(int)$r['credit_cents'];}
        return ['kind'=>$kind,'account'=>$account,'from'=>$range['from'],'to'=>$range['to'],'opening_cents'=>$opening,'debits_cents'=>$debits,'credits_cents'=>$credits,'closing_cents'=>$balance,'rows'=>$visible];
    }
}
