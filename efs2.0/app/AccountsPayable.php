<?php
declare(strict_types=1);
namespace Petrol;

/** Vendor liabilities and payments share the station's serialized transaction. */
trait AccountsPayable {
    private function apDay(string $day): string {
        $tz=new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'));
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$day,$tz);
        if(!$parsed||$parsed->format('Y-m-d')!==$day)throw new Problem('Enter a valid date.');
        return $day;
    }
    private function apToday(): string {
        return (new \DateTimeImmutable('now',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->format('Y-m-d');
    }
    private function apTimestamp(array $d,array $vendor): string {
        $day=$this->apDay((string)($d['date']??$this->apToday()));
        $tz=new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'));
        if($day>$this->apToday())throw new Problem('The transaction date cannot be in the future.');
        $opened=(new \DateTimeImmutable($vendor['created_at']))->setTimezone($tz)->format('Y-m-d');
        if($day<$opened)throw new Problem('The transaction date cannot be before the vendor was registered.');
        return $day===$this->apToday()?stamp():(new \DateTimeImmutable($day.' 23:59:59',$tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
    public function addVendorPayable(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $vendor=$this->find('suppliers',required($d,'supplier_id'));
            $amount=scaled($d['amount']??'');
            if($amount<=0)throw new Problem('Payable amount must be greater than zero.');
            $row=['id'=>uid(),'supplier_id'=>$vendor['id'],'description'=>required($d,'description',400),'amount_cents'=>$amount,'occurred_at'=>$this->apTimestamp($d,$vendor),'created_at'=>stamp(),'created_by'=>$u['id']];
            $this->db->insert('vendor_payables',$row);
            $this->db->audit($u['id'],'add_payable','supplier',$vendor['id'],$row);
            $this->db->event('vendor_payable',$row['id'],$row);
            return $row;
        });
    }
    public function registerPartyCheque(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            if(($d['confirmed_in_hand']??'')!=='1')throw new Problem('Confirm that this is an existing cheque in hand, already accounted for at its source.');
            $amount=scaled($d['amount']??'');
            if($amount<=0)throw new Problem('Cheque amount must be greater than zero.');
            $row=['id'=>uid(),'bank_name'=>strtoupper(required($d,'bank_name',160)),'branch'=>strtoupper(required($d,'branch',120)),'account_no'=>strtoupper(required($d,'account_no',64)),'cheque_no'=>strtoupper(required($d,'cheque_no',64)),'cheque_date'=>$this->apDay(required($d,'cheque_date',10)),'holder'=>required($d,'holder',160),'source_reference'=>required($d,'source_reference',160),'amount_cents'=>$amount,'status'=>'available','created_at'=>stamp(),'created_by'=>$u['id']];
            if($this->db->one('SELECT id FROM party_cheques WHERE bank_name=? AND branch=? AND account_no=? AND cheque_no=?',[$row['bank_name'],$row['branch'],$row['account_no'],$row['cheque_no']]))throw new Problem('This party cheque is already registered.');
            $this->db->insert('party_cheques',$row);
            $this->db->audit($u['id'],'register_in_hand','party_cheque',$row['id'],$row);
            $this->db->event('party_cheque',$row['id'],$row);
            return $row;
        });
    }
    public function vendorPayment(array $d,array $u): array {
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($d,$u){
            $vendor=$this->find('suppliers',required($d,'supplier_id'));
            // Preserve the pre-upgrade bank payment API for existing callers.
            $method=(string)($d['method']??'bank');
            if(!in_array($method,['cash','bank','own_cheque','party_cheque'],true))throw new Problem('Select a valid payment type.');
            $amount=scaled($d['amount']??'');
            $balance=(int)$this->db->value('SELECT balance_cents FROM supplier_balances WHERE id=?',[$vendor['id']]);
            if($amount<=0||$amount>$balance)throw new Problem('Payment must be positive and no more than the current vendor balance.');
            $at=$this->apTimestamp($d,$vendor);
            $day=(string)($d['date']??$this->apToday());
            $available=$this->ledgerStatement(['kind'=>'vendor','id'=>$vendor['id'],'from'=>$day,'to'=>$day],$u)['closing_cents'];
            if($amount>$available)throw new Problem('Payment exceeds the vendor balance on the selected date.');
            $row=['id'=>uid(),'supplier_id'=>$vendor['id'],'bank_account_id'=>null,'shift_id'=>null,'method'=>$method,'amount_cents'=>$amount,'reference'=>required($d,'reference',160),'occurred_at'=>$at,'created_at'=>stamp(),'created_by'=>$u['id'],'cheque_no'=>null,'cheque_date'=>null,'cheque_bank'=>'','cheque_branch'=>'','cheque_account'=>'','cheque_holder'=>'','party_cheque_id'=>null];
            // Vendor cash payments are independent of shift drawers.
            if($method==='bank'||$method==='own_cheque'){
                $bank=$this->find('bank_accounts',required($d,'bank_account_id'));
                $row['bank_account_id']=$bank['id'];
                if($method==='own_cheque'){
                    if(trim($bank['account_ref'])==='')throw new Problem('Choose an account with an account number. Add a complete bank account in Bank Admin before issuing a cheque.');
                    $row['cheque_no']=strtoupper(required($d,'cheque_no',64));
                    $row['cheque_date']=$this->apDay(required($d,'cheque_date',10));
                    $row['cheque_branch']=required($d,'cheque_branch',120);
                    $row['cheque_bank']=$bank['bank_name'];$row['cheque_account']=$bank['account_ref'];$row['cheque_holder']=$vendor['name'];
                    if($this->db->one('SELECT id FROM supplier_payments WHERE bank_account_id=? AND cheque_no=?',[$bank['id'],$row['cheque_no']]))throw new Problem('This cheque number has already been issued from that bank account.');
                }
            }elseif($method==='party_cheque'){
                $cheque=$this->find('party_cheques',required($d,'party_cheque_id'));
                if($cheque['status']!=='available')throw new Problem('This party cheque has already been used. Select an available cheque.',409);
                if((int)$cheque['amount_cents']!==$amount)throw new Problem('A party cheque must be paid in its full registered amount.');
                if($day<(new \DateTimeImmutable($cheque['created_at']))->setTimezone(new \DateTimeZone($this->db->setting('timezone','Asia/Colombo')))->format('Y-m-d'))throw new Problem('Payment cannot predate receipt of the party cheque.');
                foreach(['cheque_no','cheque_date'] as $key)$row[$key]=$cheque[$key];
                $row['cheque_bank']=$cheque['bank_name'];$row['cheque_branch']=$cheque['branch'];$row['cheque_account']=$cheque['account_no'];$row['cheque_holder']=$cheque['holder'];$row['party_cheque_id']=$cheque['id'];
                $this->db->run("UPDATE party_cheques SET status='endorsed',endorsed_at=?,supplier_id=? WHERE id=?",[$at,$vendor['id'],$cheque['id']]);
            }
            $this->db->insert('supplier_payments',$row);
            if($row['bank_account_id'])$this->bankPost($row['bank_account_id'],-$amount,'vendor_payment','supplier_payment',$row['id'],$row['reference'],$vendor['name'].($method==='own_cheque'?' · Cheque '.$row['cheque_no']:''),$u,$at);
            if($row['party_cheque_id'])$this->db->event('party_cheque',$row['party_cheque_id'],$this->find('party_cheques',$row['party_cheque_id']));
            $this->db->audit($u['id'],'pay_vendor','supplier',$vendor['id'],$row);
            $this->db->event('supplier_payment',$row['id'],$row);
            return $row;
        });
    }
}
