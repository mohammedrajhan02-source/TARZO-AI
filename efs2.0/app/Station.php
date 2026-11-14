<?php
declare(strict_types=1);
namespace Petrol;
require_once __DIR__.'/Workspace.php';
require_once __DIR__.'/PumpMeterSales.php';
require_once __DIR__.'/AccountsReceivable.php';

final class Station {
    use Workspace, PumpMeterSales, AccountsReceivable;
    public Db $db;
    public function __construct(Db $db) { $this->db=$db; }
    public function saveLogo(string $logo,array $user): array {
        require_role($user,['admin','manager']);
        if(strlen($logo)>410000)throw new Problem('Logo must be 300 KB or smaller.');
        if(!preg_match('~^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~D',$logo,$matches))throw new Problem('Select a PNG, JPG or WebP image.');
        $bytes=base64_decode($matches[2],true);
        if($bytes===false||strlen($bytes)===0||strlen($bytes)>300*1024)throw new Problem('Invalid image or image exceeds 300 KB.');
        $imageInfo=@getimagesizefromstring($bytes);
        $mimeByType=['png'=>'image/png','jpeg'=>'image/jpeg','webp'=>'image/webp'];
        $typeByImage=['png'=>IMAGETYPE_PNG,'jpeg'=>IMAGETYPE_JPEG,'webp'=>IMAGETYPE_WEBP];
        $detectedMime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if($imageInfo===false||$imageInfo[2]!==$typeByImage[$matches[1]]||$detectedMime!==$mimeByType[$matches[1]])throw new Problem('The uploaded file is not a valid supported image.');
        if($imageInfo[0]<1||$imageInfo[1]<1||$imageInfo[0]>2048||$imageInfo[1]>2048)throw new Problem('Logo dimensions must not exceed 2048 × 2048 pixels.');
        $this->db->tx(function()use($user,$logo){
            $this->db->set('station_logo',$logo);
            $this->db->audit($user['id'],'update_logo','settings','station');
        });
        return ['saved'=>true];
    }
    public function find(string $table,string $id): array { return $this->db->one("SELECT * FROM $table WHERE id=?",[$id])??missing('Record not found.',404); }
    public function price(string $fuelId): int { return (int)($this->db->value('SELECT price_cents FROM fuel_prices WHERE fuel_type_id=? AND effective_at<=? ORDER BY effective_at DESC,_seq DESC LIMIT 1',[$fuelId,stamp()])?:0); }
    public function balance(string $tankId): int { return (int)$this->db->value('SELECT COALESCE(SUM(quantity_ml),0) FROM stock_movements WHERE tank_id=?',[$tankId]); }
    public function stock(string $tank,int $ml,string $kind,string $ref,string $reason,?string $user): void {
        $this->db->insert('stock_movements',['id'=>uid(),'tank_id'=>$tank,'kind'=>$kind,'quantity_ml'=>$ml,'reference_id'=>$ref,'reason'=>$reason,'created_by'=>$user,'occurred_at'=>stamp()]);
    }
    public function setup(array $d): array {
        return $this->db->tx(function()use($d){
            if((int)$this->db->value('SELECT COUNT(*) FROM users')>0)throw new Problem('This station is already configured.',409);
            $password=account_password($d);
            $id=uid();$name=required($d,'owner_name');$username=account_username($d);
            if(!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/D',$username))throw new Problem('Username must contain 3–64 letters, numbers, dots, @, _ or -.');
            $this->db->insert('users',['id'=>$id,'username'=>$username,'name'=>$name,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'admin','created_at'=>stamp()]);
            foreach(['station_name'=>required($d,'station_name'),'currency'=>($d['currency']??'LKR')==='INR'?'INR':'LKR','country'=>($d['currency']??'LKR')==='INR'?'IN':'LK','timezone'=>($d['currency']??'LKR')==='INR'?'Asia/Kolkata':'Asia/Colombo','address'=>trim((string)($d['address']??'')),'phone'=>trim((string)($d['phone']??'')),'station_id'=>uid(),'device_id'=>uid(),'setup_at'=>stamp(),'demo_mode'=>!empty($d['demo'])?'1':'0','receipt_footer'=>'Thank you. Drive safely.','variance_limit_cents'=>'50000'] as $k=>$v)$this->db->set($k,$v);
            $this->ensureEmployee($id);
            if(!empty($d['demo']))$this->demo($id);
            $this->db->audit($id,'setup','station',$this->db->setting('station_id'),['demo'=>!empty($d['demo'])]);
            return ['id'=>$id,'username'=>$username,'name'=>$name,'role'=>'admin','active'=>1];
        });
    }
    private function demo(string $user): void {
        $category=uid();$this->db->insert('categories',['id'=>$category,'code'=>'LUB','name'=>'Lubricants','created_at'=>stamp()]);
        $fuels=[['P92','Petrol 92','#27cdb4',30000,27500,20000000,12800000],['DSL','Auto Diesel','#d8a85c',28000,26000,20000000,6800000],['P95','Petrol 95','#60a5fa',35000,32000,10000000,1800000]];
        foreach($fuels as $i=>$f){
            [$code,$name,$color,$price,$cost,$capacity,$balance]=$f;$fuel=uid();$tank=uid();$pump=uid();
            $this->db->insert('fuel_types',['id'=>$fuel,'code'=>$code,'name'=>$name,'color'=>$color]);
            $this->db->insert('fuel_prices',['id'=>uid(),'fuel_type_id'=>$fuel,'price_cents'=>$price,'effective_at'=>stamp(),'created_by'=>$user,'created_at'=>stamp()]);
            $this->db->insert('tanks',['id'=>$tank,'code'=>'T0'.($i+1),'name'=>'Tank '.($i+1),'fuel_type_id'=>$fuel,'capacity_ml'=>$capacity,'reorder_ml'=>intdiv($capacity,5),'cost_cents_per_l'=>$cost]);
            $this->stock($tank,$balance,'opening',$tank,'DEMO opening inventory',$user);
            $this->db->insert('pumps',['id'=>$pump,'code'=>'P0'.($i+1),'name'=>'Pump '.($i+1),'description'=>$name,'start_meter_ml'=>100000000,'end_meter_ml'=>100000000]);
            $this->db->insert('nozzles',['id'=>uid(),'code'=>'N0'.($i+1),'pump_id'=>$pump,'tank_id'=>$tank,'last_meter_ml'=>100000000]);
        }
        $customer=uid();$this->db->insert('customers',['id'=>$customer,'code'=>'DEMO-FLEET','name'=>'Demo Logistics','phone'=>'','credit_limit_cents'=>25000000,'credit_days'=>30,'max_unpaid'=>50,'created_at'=>stamp()]);
        $this->db->insert('vehicles',['id'=>uid(),'customer_id'=>$customer,'registration'=>'DEMO-001','driver'=>'Demo driver','max_fill_ml'=>200000]);
        $this->db->insert('suppliers',['id'=>uid(),'code'=>'V-DEMO','name'=>'Demo Fuel Supplier','created_at'=>stamp()]);
        $this->db->insert('products',['id'=>uid(),'sku'=>'DEMO-OIL','name'=>'Engine Oil 1 L · Demo','unit'=>'each','price_cents'=>250000,'cost_cents'=>190000,'stock_milli'=>20000,'category_id'=>$category]);
    }
    public function snapshot(array $user): array {
        $db=$this->db;$settings=[];
        foreach($db->all('SELECT `key`,value FROM settings WHERE `key` IN (\'station_name\',\'currency\',\'country\',\'timezone\',\'address\',\'phone\',\'receipt_footer\',\'demo_mode\',\'station_id\',\'device_id\',\'variance_limit_cents\',\'email\',\'registration_no\')') as $r)$settings[$r['key']]=$r['value'];
        $settings['station_logo'] = $db->setting('station_logo', '');
        $fuels=$db->all('SELECT * FROM fuel_types ORDER BY code');foreach($fuels as &$f)$f['price_cents']=$this->price($f['id']);unset($f);
        $tanks=$db->all('SELECT t.*,f.name fuel_name,f.color FROM tank_balances t JOIN fuel_types f ON f.id=t.fuel_type_id ORDER BY t.code');
        foreach($tanks as &$t)$t['last_dip']=$db->one('SELECT * FROM dip_records WHERE tank_id=? ORDER BY measured_at DESC LIMIT 1',[$t['id']]);unset($t);
        $nozzles=$db->all('SELECT n.*,t.fuel_type_id,f.name fuel_name,f.color,t.code tank_code,p.name pump_name FROM nozzles n JOIN tanks t ON t.id=n.tank_id JOIN fuel_types f ON f.id=t.fuel_type_id JOIN pumps p ON p.id=n.pump_id ORDER BY n.code');
        $shifts=$db->all('SELECT s.*,u.name user_name FROM shifts s JOIN users u ON u.id=s.user_id ORDER BY s.opened_at DESC LIMIT 80');
        foreach($shifts as &$s){$s['nozzles']=$db->all('SELECT sn.*,n.code nozzle_code FROM shift_nozzles sn JOIN nozzles n ON n.id=sn.nozzle_id WHERE sn.shift_id=?',[$s['id']]);if($s['status']==='open')$s['totals']=$this->shiftTotals($s['id']);}unset($s);
        $sales=$db->all('SELECT s.*,u.name user_name,c.name customer_name,COALESCE((SELECT SUM(p.amount_cents) FROM sale_payments p WHERE p.sale_id=s.id AND p.method=\'credit\'),0) credit_cents FROM sales s JOIN users u ON u.id=s.created_by LEFT JOIN customers c ON c.id=s.customer_id ORDER BY s.created_at DESC,s._seq DESC LIMIT 200');
        $dayStart=$this->dayStart();$dayEnd=$this->dayEnd();
        $totals=$db->one('SELECT COALESCE(SUM(total_cents),0) revenue_cents,COALESCE(SUM(cost_cents),0) cost_cents,COUNT(*) receipts FROM sales WHERE created_at>=? AND created_at<?',[$dayStart,$dayEnd]);
        $totals['fuel_ml']=(int)$db->value('SELECT COALESCE(SUM(i.quantity_milli),0) FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE i.kind=\'fuel\' AND s.created_at>=? AND s.created_at<?',[$dayStart,$dayEnd]);
        $totals['credit_cents']=(int)$db->value('SELECT COALESCE(SUM(debit_cents-credit_cents),0) FROM customer_ledger');
        $totals['expenses_cents']=(int)$db->value('SELECT COALESCE(SUM(amount_cents),0) FROM expenses WHERE created_at>=? AND created_at<?',[$dayStart,$dayEnd]);
        $totals['pending_sync']=(int)$db->value('SELECT COUNT(*) FROM sync_outbox WHERE sent_at IS NULL');
        $pumps=$db->all('SELECT p.*,(EXISTS(SELECT 1 FROM shift_nozzles sn JOIN nozzles n ON n.id=sn.nozzle_id WHERE n.pump_id=p.id) OR EXISTS(SELECT 1 FROM pump_meter_sales ms WHERE ms.pump_id=p.id)) has_history FROM pumps p ORDER BY p.code');
        foreach($pumps as &$pump)$pump['product_id']=(string)$db->setting('pump_product_'.$pump['id'],'');
        unset($pump);
        $result=['settings'=>$settings,'user'=>$user,'fuels'=>$fuels,'tanks'=>$tanks,'pumps'=>$pumps,'nozzles'=>$nozzles,'shifts'=>$shifts,'sales'=>$sales,'totals'=>$totals,'products'=>$db->all('SELECT p.*,c.name category_name,c.code category_code,((SELECT COUNT(*) FROM sale_items si WHERE si.product_id=p.id)+(SELECT COUNT(*) FROM product_purchases pp WHERE pp.product_id=p.id)>0) has_transactions FROM products p LEFT JOIN categories c ON c.id=p.category_id ORDER BY p.name'),'customers'=>$db->all('SELECT * FROM customer_balances ORDER BY name'),'vehicles'=>$db->all('SELECT * FROM vehicles ORDER BY registration'),'suppliers'=>$db->all('SELECT * FROM supplier_balances ORDER BY name'),'deliveries'=>$db->all('SELECT d.*,t.code tank_code,s.name supplier_name,s.code supplier_code,i.con_bill_no,i.purchase_date,i.invoice_no company_bill_no FROM deliveries d JOIN tanks t ON t.id=d.tank_id JOIN suppliers s ON s.id=d.supplier_id LEFT JOIN purchase_invoices i ON i.purchase_id=d.id ORDER BY d.received_at DESC LIMIT 100'),'expenses'=>$db->all('SELECT * FROM expenses ORDER BY created_at DESC LIMIT 100'),'settlements'=>$db->all('SELECT st.*,s.label,u.name user_name FROM settlements st JOIN shifts s ON s.id=st.shift_id JOIN users u ON u.id=s.user_id ORDER BY st.created_at DESC LIMIT 100'),'dips'=>$db->all('SELECT d.*,t.code tank_code FROM dip_records d JOIN tanks t ON t.id=d.tank_id ORDER BY d.measured_at DESC LIMIT 100'),'charts'=>$db->all('SELECT c.*,t.code tank_code FROM calibration_charts c JOIN tanks t ON t.id=c.tank_id ORDER BY c.created_at DESC'),'sync'=>['last_sync'=>$db->setting('last_sync'),'last_error'=>$db->setting('sync_error')]];
        if(in_array($user['role'],['admin','manager'],true)){$result['users']=$db->all('SELECT id,username,name,role,active,created_at FROM users ORDER BY name');$result['audit']=$db->all('SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC,a._seq DESC LIMIT 120');}
        if(in_array($user['role'],['cashier','viewer'],true)){foreach($result['products'] as &$p)unset($p['cost_cents']);unset($p);foreach($result['tanks'] as &$t)unset($t['cost_cents_per_l']);unset($t);foreach($result['sales'] as &$s)unset($s['cost_cents']);unset($s);unset($result['totals']['cost_cents']);}
        return array_merge($result,$this->workspaceSnapshot($user),['meter_sales_meta'=>$this->meterSalesMeta($user)]);
    }
    public function dayStart(): string {return (new \DateTimeImmutable('today',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}
    public function dayEnd(): string {return (new \DateTimeImmutable('tomorrow',new \DateTimeZone($this->db->setting('timezone','Asia/Colombo'))))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');}
    public function master(string $kind,array $d,array $u): array {
        if($kind==='category')return $this->saveCategory($d,$u);
        if($kind==='product')return $this->saveProduct($d,$u);
        if($kind==='supplier')return $this->saveVendor($d,$u);
        if($kind==='pump')return $this->savePump(array_merge(['description'=>$d['name']??'Pump','start_meter'=>'0','end_meter'=>'0'],$d),$u);
        require_role($u,['admin','manager']);
        return $this->db->tx(function()use($kind,$d,$u){
            $id=uid();$row=['id'=>$id];$db=$this->db;
            switch($kind){
                case 'fuel':$row+=['code'=>strtoupper(required($d,'code',16)),'name'=>required($d,'name'),'color'=>preg_match('/^#[0-9a-fA-F]{6}$/D',(string)($d['color']??''))?$d['color']:'#27cdb4'];$table='fuel_types';break;
                case 'tank':$this->find('fuel_types',required($d,'fuel_type_id'));$capacity=scaled($d['capacity_liters']??'',3);$opening=scaled($d['opening_liters']??'0',3);$reorder=scaled($d['reorder_liters']??'0',3);if($capacity<=0||$opening>$capacity||$reorder>$capacity)throw new Problem('Check tank capacity, opening stock and reorder level.');$row+=['code'=>strtoupper(required($d,'code',16)),'name'=>required($d,'name'),'fuel_type_id'=>$d['fuel_type_id'],'capacity_ml'=>$capacity,'reorder_ml'=>$reorder,'cost_cents_per_l'=>scaled($d['cost_per_liter']??'0')];$table='tanks';break;
                case 'pump':$row+=['code'=>strtoupper(required($d,'code',16)),'name'=>required($d,'name')];$table='pumps';break;
                case 'nozzle':$this->find('pumps',required($d,'pump_id'));$this->find('tanks',required($d,'tank_id'));$row+=['code'=>strtoupper(required($d,'code',16)),'pump_id'=>$d['pump_id'],'tank_id'=>$d['tank_id'],'last_meter_ml'=>scaled($d['meter_liters']??'0',3)];$table='nozzles';break;
                case 'customer':if(!preg_match('/^[0-9]{1,5}$/D',(string)($d['max_unpaid']??50))||(int)($d['max_unpaid']??50)<1||(int)($d['max_unpaid']??50)>10000)throw new Problem('Maximum unpaid invoices must be between 1 and 10000. Use a zero credit limit to disable new credit.');$row+=['code'=>strtoupper(required($d,'code',32)),'name'=>required($d,'name'),'phone'=>trim((string)($d['phone']??'')),'credit_limit_cents'=>scaled($d['credit_limit']??'0'),'credit_days'=>max(0,min(3650,(int)($d['credit_days']??30))),'max_unpaid'=>max(1,min(10000,(int)($d['max_unpaid']??50))),'created_at'=>stamp()];$table='customers';break;
                case 'vehicle':$this->find('customers',required($d,'customer_id'));$fuel=trim((string)($d['fuel_type_id']??''));if($fuel)$this->find('fuel_types',$fuel);$row+=['customer_id'=>$d['customer_id'],'registration'=>strtoupper(required($d,'registration',40)),'driver'=>trim((string)($d['driver']??'')),'fuel_type_id'=>$fuel?:null,'max_fill_ml'=>scaled($d['max_fill_liters']??'0',3)];$table='vehicles';break;
                case 'supplier':$row+=['name'=>required($d,'name'),'phone'=>trim((string)($d['phone']??'')),'created_at'=>stamp()];$table='suppliers';break;
                case 'product':$row+=['sku'=>strtoupper(required($d,'sku',40)),'name'=>required($d,'name'),'unit'=>'each','price_cents'=>scaled($d['price']??''),'cost_cents'=>scaled($d['cost']??'0'),'stock_milli'=>scaled($d['quantity']??'0',3)];$table='products';break;
                case 'user':require_role($u,['admin']);$pw=account_password($d);$role=required($d,'role');if(!in_array($role,['admin','manager','cashier','viewer'],true))throw new Problem('Unknown role.');$row+=['username'=>account_username($d),'name'=>required($d,'name'),'password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'role'=>$role,'created_at'=>stamp()];$table='users';break;
                default:throw new Problem('Unknown master record.');
            }
            $db->insert($table,$row);
            if($kind==='user'){
                if(!empty($d['employee_id']))$this->linkEmployee(['employee_id'=>$d['employee_id'],'user_id'=>$id],$u);
                else $this->ensureEmployee($id);
            }
            if($kind==='tank'&&$opening>0)$this->stock($id,$opening,'opening',$id,'Opening inventory',$u['id']);
            if($kind==='fuel'){$price=scaled($d['price']??'0');if($price>0)$db->insert('fuel_prices',['id'=>uid(),'fuel_type_id'=>$id,'price_cents'=>$price,'effective_at'=>stamp(),'created_by'=>$u['id'],'created_at'=>stamp()]);}
            unset($row['password_hash']);$db->audit($u['id'],'create',$table,$id,$row);$db->event($table,$id,$row);return $row;
        });
    }
    public function openShift(array $d,array $u): array {
        require_role($u,['admin','manager','cashier']);
        return $this->db->tx(function()use($d,$u){
            $owner=$u['id'];if(!empty($d['user_id'])&&$d['user_id']!==$owner){require_role($u,['admin','manager']);$target=$this->find('users',$d['user_id']);if(!$target['active']||$target['role']==='viewer')throw new Problem('Select an active operator.');$owner=$target['id'];}
            $employee=$this->db->one('SELECT * FROM employees WHERE user_id=?',[$owner]);
            if($employee&&!$employee['active'])throw new Problem('The employee is inactive. Reactivate their staff record before opening a shift.');
            $ids=$d['nozzle_ids']??[];if(!is_array($ids)||count($ids)<1||count($ids)>30||count(array_unique($ids))!==count($ids))throw new Problem('Select at least one unique nozzle.');
            $id=uid();$shift=['id'=>$id,'label'=>required($d,'label',60),'user_id'=>$owner,'opened_at'=>stamp(),'opening_cash_cents'=>scaled($d['opening_cash']??'0'),'status'=>'open'];$this->db->insert('shifts',$shift);
            foreach($ids as $nId){$n=$this->find('nozzles',(string)$nId);$tank=$this->find('tanks',$n['tank_id']);if(!$n['active'])throw new Problem('Nozzle is disabled.');if($this->db->one('SELECT sn.id FROM shift_nozzles sn JOIN shifts s ON s.id=sn.shift_id WHERE sn.nozzle_id=? AND s.status=\'open\'',[$nId]))throw new Problem('Nozzle '.$n['code'].' belongs to another open shift.');$rate=$this->price($tank['fuel_type_id']);if($rate<=0)throw new Problem('Set an active fuel rate first.');$sn=uid();$this->db->insert('shift_nozzles',['id'=>$sn,'shift_id'=>$id,'nozzle_id'=>$nId,'opening_ml'=>$n['last_meter_ml']]);$this->db->insert('meter_periods',['id'=>uid(),'shift_nozzle_id'=>$sn,'opening_ml'=>$n['last_meter_ml'],'rate_cents'=>$rate,'started_at'=>stamp()]);}
            $this->db->audit($u['id'],'open','shift',$id);$this->db->event('shift',$id,$shift);return $shift;
        });
    }
    public function requireShift(string $id,array $u): array {
        $s=$this->find('shifts',$id);if($s['status']!=='open')throw new Problem('The shift is already closed.',409);
        if($s['user_id']!==$u['id'])require_role($u,['admin','manager']);return $s;
    }
    public function sale(array $d,array $u): array {
        require_role($u,['admin','manager','cashier']);
        return $this->db->tx(function()use($d,$u){
            $key=required($d,'idempotency_key',100);$requestHash=hash('sha256',json_encode($d,JSON_THROW_ON_ERROR));$existing=$this->db->one('SELECT id,created_by FROM sales WHERE idempotency_key=?',[$key]);
            if($existing){$savedHash=$this->db->value('SELECT request_hash FROM sale_requests WHERE idempotency_key=?',[$key]);if($savedHash!==$requestHash)throw new Problem('This request key was used for a different bill. Review the existing receipt before starting a new bill.',409);if($existing['created_by']!==$u['id'])throw new Problem('Request key is already used.',409);return $this->receipt($existing['id']);}
            $shift=$this->requireShift(required($d,'shift_id'),$u);$items=$d['items']??[];if(!is_array($items)||count($items)<1||count($items)>50)throw new Problem('Add 1–50 bill items.');
            $saleId=uid();$lines=[];$total=0;$cost=0;$fuelLiters=0;$vehicleFuel=[];
            foreach($items as $item){
                $qty=scaled($item['quantity']??'',3);if($qty<=0)throw new Problem('Quantity must be positive.');$line=['id'=>uid(),'sale_id'=>$saleId,'kind'=>$item['kind']??'fuel','quantity_milli'=>$qty];
                if($line['kind']==='fuel'){
                    $n=$this->find('nozzles',required($item,'nozzle_id'));$tank=$this->find('tanks',$n['tank_id']);
                    $period=$this->db->one('SELECT mp.* FROM meter_periods mp JOIN shift_nozzles sn ON sn.id=mp.shift_nozzle_id WHERE sn.shift_id=? AND sn.nozzle_id=? AND mp.ended_at IS NULL',[$shift['id'],$n['id']]);
                    if(!$period||!$n['active'])throw new Problem('Selected nozzle is not assigned to this shift.');
                    if($this->balance($tank['id'])<$qty)throw new Problem('Insufficient book stock in '.$tank['code'].'.');
                    $fuel=$this->find('fuel_types',$tank['fuel_type_id']);$price=(int)$period['rate_cents'];
                    if(isset($item['expected_rate_cents'])&&(int)$item['expected_rate_cents']!==$price)throw new Problem('The fuel rate changed. Refresh the bill before saving.',409);
                    $productId=$this->deductPumpProduct($n['pump_id'],$qty);
                    $line+=['nozzle_id'=>$n['id'],'meter_period_id'=>$period['id'],'description'=>$fuel['name'].' · '.$n['code'],'unit_price_cents'=>$price,'line_cents'=>line_total($qty,$price),'cost_cents'=>line_total($qty,(int)$tank['cost_cents_per_l'])];
                    if($productId!==null)$line['product_id']=$productId;
                    $this->stock($tank['id'],-$qty,'sale',$saleId,'Fuel sale',$u['id']);$fuelLiters+=$qty;$vehicleFuel[]=$fuel['id'];
                }elseif($line['kind']==='product'){
                    $p=$this->find('products',required($item,'product_id'));if(!$p['active']||$p['stock_milli']<$qty)throw new Problem('Insufficient product stock.');if($qty%1000!==0)throw new Problem('Packaged products require whole units.');
                    $line+=['product_id'=>$p['id'],'description'=>$p['name'],'unit_price_cents'=>$p['price_cents'],'line_cents'=>line_total($qty,(int)$p['price_cents']),'cost_cents'=>line_total($qty,(int)$p['cost_cents'])];
                    $this->db->run('UPDATE products SET stock_milli=stock_milli-? WHERE id=?',[$qty,$p['id']]);
                }else throw new Problem('Unknown item type.');
                $total+=$line['line_cents'];$cost+=$line['cost_cents'];$lines[]=$line;
            }
            if($total<=0)throw new Problem('Bill total must be positive.');
            $payments=$d['payments']??[];$payTotal=0;$credit=0;$payRows=[];
            foreach($payments as $pay){$method=$pay['method']??'';if(!in_array($method,['cash','card','qr','credit'],true))throw new Problem('Unknown payment method.');$amount=scaled($pay['amount']??'0');if($amount<=0)continue;if(in_array($method,['card','qr'],true)&&empty($pay['confirmed']))throw new Problem('Verify card/QR payment before confirming the sale.');$ref=trim((string)($pay['reference']??''));if(in_array($method,['card','qr'],true)&&$ref==='')throw new Problem('Enter the card/QR payment reference.');$payTotal+=$amount;if($method==='credit')$credit+=$amount;$payRows[]=['id'=>uid(),'sale_id'=>$saleId,'method'=>$method,'amount_cents'=>$amount,'reference'=>$ref,'confirmed'=>1];}
            if($payTotal!==$total)throw new Problem('Payment allocations must exactly match the bill total.');
            $customerId=trim((string)($d['customer_id']??''))?:null;$vehicleId=trim((string)($d['vehicle_id']??''))?:null;
            if($credit>0){if(!$customerId)throw new Problem('Choose a customer for credit sales.');$this->validateCredit($customerId,$credit);}
            elseif($customerId)$this->find('customers',$customerId);
            $odometer=null;
            if($vehicleId){$v=$this->find('vehicles',$vehicleId);if(!$v['active']||$v['customer_id']!==$customerId)throw new Problem('Vehicle does not belong to this active customer.');if($v['max_fill_ml']>0&&$fuelLiters>$v['max_fill_ml'])throw new Problem('Vehicle filling limit exceeded.');if($v['fuel_type_id']&&count(array_diff($vehicleFuel,[$v['fuel_type_id']]))>0)throw new Problem('Fuel grade is not authorized for this vehicle.');$o=(string)($d['odometer']??'');if(!preg_match('/^\d{1,9}$/D',$o))throw new Problem('Enter a whole-number odometer reading.');$odometer=(int)$o;if($v['last_odometer']!==null&&$odometer<$v['last_odometer'])throw new Problem('Odometer cannot be lower than the previous reading.');$this->db->run('UPDATE vehicles SET last_odometer=? WHERE id=?',[$odometer,$vehicleId]);}
            $indent=trim((string)($d['indent_no']??''));$driver=trim((string)($d['driver_name']??''));if($credit>0&&$vehicleId&&($indent===''||$driver===''))throw new Problem('Fleet credit requires an indent number and driver name.');
            $sale=['id'=>$saleId,'idempotency_key'=>$key,'receipt_no'=>'P-'.gmdate('ymd').'-'.strtoupper(substr($saleId,0,10)),'shift_id'=>$shift['id'],'customer_id'=>$customerId,'vehicle_id'=>$vehicleId,'driver_name'=>$driver,'indent_no'=>$indent,'odometer'=>$odometer,'full_tank'=>!empty($d['full_tank'])?1:0,'total_cents'=>$total,'cost_cents'=>$cost,'created_at'=>stamp(),'created_by'=>$u['id']];
            $this->db->insert('sales',$sale);$this->db->insert('sale_requests',['idempotency_key'=>$key,'request_hash'=>$requestHash,'sale_id'=>$saleId]);foreach($lines as $l)$this->db->insert('sale_items',$l);foreach($payRows as $p)$this->db->insert('sale_payments',$p);
            if($credit>0)$this->db->insert('customer_ledger',['id'=>uid(),'customer_id'=>$customerId,'kind'=>'invoice','debit_cents'=>$credit,'credit_cents'=>0,'sale_id'=>$saleId,'reference'=>$sale['receipt_no'],'occurred_at'=>stamp(),'created_by'=>$u['id']]);
            $this->db->audit($u['id'],'post','sale',$saleId,['total_cents'=>$total]);$receipt=$this->receipt($saleId);$this->db->event('sale',$saleId,$receipt);return $receipt;
        });
    }
    private function validateCredit(string $customer,int $amount): void {
        $c=$this->db->one('SELECT * FROM customer_balances WHERE id=?',[$customer])??missing('Customer not found.');
        if(!$c['active'])throw new Problem('Customer account is disabled.');if($c['balance_cents']+$amount>$c['credit_limit_cents'])throw new Problem('Customer credit limit exceeded.');
        $open=$this->db->all('SELECT l.*,l.debit_cents-COALESCE((SELECT SUM(a.amount_cents) FROM customer_allocations a WHERE a.invoice_entry_id=l.id),0) remaining FROM customer_ledger l WHERE l.customer_id=? AND l.debit_cents>0',[$customer]);
        $count=0;foreach($open as $inv)if($inv['remaining']>0){$count++;if(strtotime($inv['occurred_at'])<time()-$c['credit_days']*86400)throw new Problem('Customer has overdue credit. Record payment first.');}
        if($count>=$c['max_unpaid'])throw new Problem('Maximum unpaid invoice count reached.');
    }
    public function receipt(string $id): array {
        $sale=$this->db->one('SELECT s.*,u.name user_name,c.name customer_name,v.registration FROM sales s JOIN users u ON u.id=s.created_by LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN vehicles v ON v.id=s.vehicle_id WHERE s.id=?',[$id])??missing('Receipt not found.',404);
        $sale['items']=$this->db->all('SELECT * FROM sale_items WHERE sale_id=?',[$id]);$sale['payments']=$this->db->all('SELECT * FROM sale_payments WHERE sale_id=?',[$id]);return $sale;
    }
    public function shiftTotals(string $id): array {
        $shift=$this->find('shifts',$id);$totals=['cash'=>0,'card'=>0,'qr'=>0,'credit'=>0];
        foreach($this->db->all('SELECT p.method,SUM(p.amount_cents) amount FROM sale_payments p JOIN sales s ON s.id=p.sale_id WHERE s.shift_id=? GROUP BY p.method',[$id]) as $r)$totals[$r['method']]=(int)$r['amount'];
        $totals['cash_movements']=(int)$this->db->value('SELECT COALESCE(SUM(amount_cents),0) FROM cash_movements WHERE shift_id=?',[$id]);$totals['expected_cash']=$shift['opening_cash_cents']+$totals['cash']+$totals['cash_movements'];$totals['sales']=$totals['cash']+$totals['card']+$totals['qr']+$totals['credit'];
        $totals['periods']=$this->db->all('SELECT mp.*,n.code nozzle_code,n.id nozzle_id,COALESCE((SELECT SUM(quantity_ml) FROM fuel_tests f WHERE f.meter_period_id=mp.id),0) test_ml,COALESCE((SELECT SUM(quantity_milli) FROM sale_items si WHERE si.meter_period_id=mp.id),0) billed_ml FROM meter_periods mp JOIN shift_nozzles sn ON sn.id=mp.shift_nozzle_id JOIN nozzles n ON n.id=sn.nozzle_id WHERE sn.shift_id=? ORDER BY n.code,mp.started_at,mp._seq',[$id]);return $totals;
    }
    public function testFuel(array $d,array $u): array {
        return $this->db->tx(function()use($d,$u){require_role($u,['admin','manager','cashier']);$s=$this->requireShift(required($d,'shift_id'),$u);$n=$this->find('nozzles',required($d,'nozzle_id'));$p=$this->db->one('SELECT mp.* FROM meter_periods mp JOIN shift_nozzles sn ON sn.id=mp.shift_nozzle_id WHERE sn.shift_id=? AND sn.nozzle_id=? AND mp.ended_at IS NULL',[$s['id'],$n['id']]);if(!$p)throw new Problem('Nozzle not active in this shift.');$qty=scaled($d['liters']??'',3);$returned=scaled($d['returned_liters']??'0',3);if($qty<=0||$returned>$qty||$qty>$this->balance($n['tank_id']))throw new Problem('Invalid test/return quantity.');$id=uid();$row=['id'=>$id,'meter_period_id'=>$p['id'],'quantity_ml'=>$qty,'returned_ml'=>$returned,'reason'=>required($d,'reason'),'created_at'=>stamp(),'created_by'=>$u['id']];$this->db->insert('fuel_tests',$row);$this->stock($n['tank_id'],-$qty,'test_out',$id,$row['reason'],$u['id']);if($returned)$this->stock($n['tank_id'],$returned,'test_return',$id,'Returned test fuel',$u['id']);$this->db->audit($u['id'],'record','fuel_test',$id,$row);$this->db->event('fuel_test',$id,$row);return $row;});
    }
    public function changePrice(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$fuel=$this->find('fuel_types',required($d,'fuel_type_id'));$rate=scaled($d['price']??'');if($rate<=0)throw new Problem('Price must be positive.');$active=$this->db->all('SELECT mp.*,n.id nozzle_id,n.code FROM meter_periods mp JOIN shift_nozzles sn ON sn.id=mp.shift_nozzle_id JOIN nozzles n ON n.id=sn.nozzle_id JOIN tanks t ON t.id=n.tank_id WHERE t.fuel_type_id=? AND mp.ended_at IS NULL',[$fuel['id']]);foreach($active as $p){$reading=$d['readings'][$p['nozzle_id']]??null;if($reading===null)throw new Problem('A price-boundary meter reading is required for '.$p['code'].'.');$close=scaled($reading,3);$min=$p['opening_ml']+(int)$this->db->value('SELECT COALESCE(SUM(quantity_ml),0) FROM fuel_tests WHERE meter_period_id=?',[$p['id']])+(int)$this->db->value('SELECT COALESCE(SUM(quantity_milli),0) FROM sale_items WHERE meter_period_id=?',[$p['id']]);if($close<$min)throw new Problem('Price-boundary reading is below recorded dispensing.');$this->db->run('UPDATE meter_periods SET closing_ml=?,ended_at=? WHERE id=?',[$close,stamp(),$p['id']]);$this->db->insert('meter_periods',['id'=>uid(),'shift_nozzle_id'=>$p['shift_nozzle_id'],'opening_ml'=>$close,'rate_cents'=>$rate,'started_at'=>stamp()]);}$id=uid();$row=['id'=>$id,'fuel_type_id'=>$fuel['id'],'price_cents'=>$rate,'effective_at'=>stamp(),'created_by'=>$u['id'],'created_at'=>stamp()];$this->db->insert('fuel_prices',$row);$this->db->audit($u['id'],'change_rate','fuel',$fuel['id'],['new_rate_cents'=>$rate]);$this->db->event('fuel_price',$id,$row);return $row;});
    }
    public function closeShift(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$shift=$this->requireShift(required($d,'shift_id'),$u);$totals=$this->shiftTotals($shift['id']);
            foreach($totals['periods'] as $p){$closing=$p['closing_ml'];if($p['ended_at']===null){if(!array_key_exists($p['nozzle_id'],$d['readings']??[]))throw new Problem('Enter every closing meter reading.');$closing=scaled($d['readings'][$p['nozzle_id']],3);$this->db->run('UPDATE meter_periods SET closing_ml=?,ended_at=? WHERE id=?',[$closing,stamp(),$p['id']]);$this->db->run('UPDATE shift_nozzles SET closing_ml=? WHERE id=?',[$closing,$p['shift_nozzle_id']]);$this->db->run('UPDATE nozzles SET last_meter_ml=? WHERE id=?',[$closing,$p['nozzle_id']]);}
                $net=$closing-$p['opening_ml']-$p['test_ml'];$gap=$net-$p['billed_ml'];if($net<0||$gap<0)throw new Problem($p['nozzle_code'].': meter reading is below recorded sales plus tests.');
                if($gap>0){if(empty($d['recognize_meter_gaps']))throw new Problem($p['nozzle_code'].': '.($gap/1000).' L is not billed. Confirm meter-gap cash sales, or correct the records.');$this->meterGapSale($shift,$p,$gap,$u);}
            }
            $totals=$this->shiftTotals($shift['id']);$cash=scaled($d['counted_cash']??'');$card=scaled($d['counted_card']??'');$qr=scaled($d['counted_qr']??'');$variance=$cash-$totals['expected_cash']+$card-$totals['card']+$qr-$totals['qr'];$note=trim((string)($d['note']??''));if($variance!==0&&$note==='')throw new Problem('Explain the settlement variance before approval.');
            $row=['id'=>uid(),'shift_id'=>$shift['id'],'expected_sales_cents'=>$totals['sales'],'expected_cash_cents'=>$totals['expected_cash'],'counted_cash_cents'=>$cash,'card_expected_cents'=>$totals['card'],'card_counted_cents'=>$card,'qr_expected_cents'=>$totals['qr'],'qr_counted_cents'=>$qr,'credit_cents'=>$totals['credit'],'variance_cents'=>$variance,'note'=>$note,'approved_by'=>$u['id'],'created_at'=>stamp()];$this->db->insert('settlements',$row);$this->db->run('UPDATE shifts SET status=\'closed\',closed_at=?,closed_by=? WHERE id=?',[stamp(),$u['id'],$shift['id']]);if($variance!==0)$this->db->insert('staff_variances',['id'=>uid(),'settlement_id'=>$row['id'],'user_id'=>$shift['user_id'],'amount_cents'=>$variance,'note'=>$note,'created_at'=>stamp()]);$this->db->audit($u['id'],'close','shift',$shift['id'],$row);$this->db->event('settlement',$row['id'],$row);return $row;
        });
    }
    private function meterGapSale(array $shift,array $period,int $qty,array $u): void {
        $n=$this->find('nozzles',$period['nozzle_id']);$tank=$this->find('tanks',$n['tank_id']);if($this->balance($tank['id'])<$qty)throw new Problem('Meter gap exceeds remaining tank stock.');$productId=$this->deductPumpProduct($n['pump_id'],$qty);$id=uid();$amount=line_total($qty,(int)$period['rate_cents']);if($amount<=0)throw new Problem('Meter gap is too small to value.');$cost=line_total($qty,(int)$tank['cost_cents_per_l']);
        $saleItem=['id'=>uid(),'sale_id'=>$id,'kind'=>'fuel','nozzle_id'=>$n['id'],'meter_period_id'=>$period['id'],'description'=>'Meter settlement · '.$n['code'],'quantity_milli'=>$qty,'unit_price_cents'=>$period['rate_cents'],'line_cents'=>$amount,'cost_cents'=>$cost];if($productId!==null)$saleItem['product_id']=$productId;
        $this->db->insert('sales',['id'=>$id,'idempotency_key'=>'meter:'.$period['id'],'receipt_no'=>'M-'.gmdate('ymd').'-'.strtoupper(substr($id,0,10)),'shift_id'=>$shift['id'],'source'=>'meter_settlement','total_cents'=>$amount,'cost_cents'=>$cost,'created_at'=>stamp(),'created_by'=>$u['id']]);$this->db->insert('sale_items',$saleItem);$this->db->insert('sale_payments',['id'=>uid(),'sale_id'=>$id,'method'=>'cash','amount_cents'=>$amount,'reference'=>'Supervisor-confirmed unbilled meter sales']);$this->stock($tank['id'],-$qty,'sale',$id,'Meter gap cash sale',$u['id']);$this->db->audit($u['id'],'recognize_meter_gap','sale',$id,['quantity_ml'=>$qty]);$this->db->event('sale',$id,$this->receipt($id));
    }
    private function deductPumpProduct(string $pumpId,int $quantity): ?string {
        $productId=trim((string)$this->db->setting('pump_product_'.$pumpId,''));
        if($productId==='')return null;
        $product=$this->db->one('SELECT id,active,stock_milli,name,sku FROM products WHERE id=?',[$productId]);
        if(!$product)throw new Problem('The product linked to this pump no longer exists.');
        if(!(int)$product['active'])throw new Problem('The product linked to this pump is inactive.');
        $pump=$this->db->one('SELECT id,code,name,description FROM pumps WHERE id=?',[$pumpId]) ?? ['id'=>$pumpId,'code'=>'','name'=>'Unknown pump'];
        $requiredLiters=$quantity/1000.0;$availableLiters=((int)$product['stock_milli'])/1000.0;$pumpLabel=trim((string)($pump['code']??''));
        $pumpName=trim((string)($pump['name']??''));if($pumpName!==''){$pumpLabel=$pumpLabel!==''?$pumpLabel.' ('.$pumpName.')':$pumpName;}
        $message=sprintf('Insufficient stock: Required %.3f L, Available %.3f L. Product: %s. Pump: %s.', $requiredLiters, $availableLiters, trim((string)($product['name']??$product['sku']??'Unknown product')), $pumpLabel!==''?$pumpLabel:'Unknown pump');
        if((int)$product['stock_milli']<$quantity)throw new Problem($message);
        $updated=$this->db->run('UPDATE products SET stock_milli=stock_milli-? WHERE id=? AND active=1 AND stock_milli>=?',[$quantity,$productId,$quantity]);
        if($updated->rowCount()!==1)throw new Problem($message);
        return $productId;
    }
    public function delivery(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$t=$this->find('tanks',required($d,'tank_id'));$this->find('suppliers',required($d,'supplier_id'));$qty=scaled($d['liters']??'',3);$rate=scaled($d['cost_per_liter']??'');$balance=$this->balance($t['id']);if($qty<=0||$balance+$qty>$t['capacity_ml'])throw new Problem('Delivery exceeds tank capacity or is zero.');$total=line_total($qty,$rate);$paid=scaled($d['paid_amount']??'0');if($total<=0)throw new Problem('Delivery cost must be positive.');if($paid>$total)throw new Problem('Paid amount exceeds the delivery invoice.');$id=uid();$document=$this->registerPurchaseDocument($d,$id,'fuel');$row=['id'=>$id,'tank_id'=>$t['id'],'supplier_id'=>$d['supplier_id'],'invoice_no'=>$document['company_bill_no'],'quantity_ml'=>$qty,'cost_cents_per_l'=>$rate,'total_cents'=>$total,'paid_cents'=>$paid,'bank_account_id'=>$paid?required($d,'bank_account_id'):null,'notes'=>trim((string)($d['notes']??'')),'received_at'=>$document['received_at'],'created_by'=>$u['id']];$this->db->insert('deliveries',$row);if($paid)$this->bankPost($row['bank_account_id'],-$paid,'purchase_payment','fuel_purchase',$id,$row['invoice_no'],'Fuel delivery',$u,$row['received_at'],$id);$newCost=intdiv($balance*(int)$t['cost_cents_per_l']+$qty*$rate+intdiv($balance+$qty,2),$balance+$qty);$this->db->run('UPDATE tanks SET cost_cents_per_l=? WHERE id=?',[$newCost,$t['id']]);$this->stock($t['id'],$qty,'delivery',$id,'Supplier delivery '.$row['invoice_no'],$u['id']);$result=array_merge($row,$document);$this->db->audit($u['id'],'receive','delivery',$id,$result);$this->db->event('delivery',$id,$result);return $result;});
    }
    public function cashMove(array $d,array $u): array {
        require_role($u,['admin','manager','cashier']);return $this->db->tx(function()use($d,$u){$s=$this->requireShift(required($d,'shift_id'),$u);$kind=required($d,'kind');if(!in_array($kind,['drop','paid_in'],true))throw new Problem('Unknown cash movement.');if($kind==='paid_in')require_role($u,['admin','manager']);$amount=scaled($d['amount']??'');if($amount<=0)throw new Problem('Amount must be positive.');if($kind==='drop'&&$amount>$this->shiftTotals($s['id'])['expected_cash'])throw new Problem('Cash drop exceeds expected drawer cash.');$row=['id'=>uid(),'shift_id'=>$s['id'],'kind'=>$kind,'amount_cents'=>$kind==='drop'?-$amount:$amount,'reason'=>required($d,'reason'),'created_at'=>stamp(),'created_by'=>$u['id']];$this->db->insert('cash_movements',$row);$this->db->audit($u['id'],'cash_movement','shift',$s['id'],$row);$this->db->event('cash_movement',$row['id'],$row);return $row;});
    }
    public function expense(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$amount=scaled($d['amount']??'');if($amount<=0)throw new Problem('Amount must be positive.');$method=$d['payment_method']??'cash';if(!in_array($method,['cash','bank'],true))throw new Problem('Invalid payment method.');$shiftId=trim((string)($d['shift_id']??''))?:null;if($method==='cash'){if(!$shiftId)throw new Problem('Choose a shift for cash expenses.');$this->requireShift($shiftId,$u);if($amount>$this->shiftTotals($shiftId)['expected_cash'])throw new Problem('Cash expense exceeds expected drawer cash.');}else $shiftId=null;$row=['id'=>uid(),'shift_id'=>$shiftId,'category'=>required($d,'category'),'amount_cents'=>$amount,'payment_method'=>$method,'bank_account_id'=>$method==='bank'?required($d,'bank_account_id'):null,'note'=>required($d,'note'),'created_at'=>stamp(),'created_by'=>$u['id']];$this->db->insert('expenses',$row);if($method==='bank')$this->bankPost($row['bank_account_id'],-$amount,'expense','expense',$row['id'],$row['category'],$row['note'],$u);if($shiftId)$this->db->insert('cash_movements',['id'=>uid(),'shift_id'=>$shiftId,'kind'=>'expense','amount_cents'=>-$amount,'reason'=>$row['note'],'reference_id'=>$row['id'],'created_at'=>stamp(),'created_by'=>$u['id']]);$this->db->audit($u['id'],'record','expense',$row['id'],$row);$this->db->event('expense',$row['id'],$row);return $row;});
    }
    public function customerReceipt(array $d,array $u): array {
        require_role($u,['admin','manager','cashier']);return $this->db->tx(function()use($d,$u){$customer=$this->find('customers',required($d,'customer_id'));$amount=scaled($d['amount']??'');$balance=(int)$this->db->value('SELECT balance_cents FROM customer_balances WHERE id=?',[$customer['id']]);if($amount<=0||$amount>$balance)throw new Problem('Receipt must be positive and no more than the outstanding balance.');$method=$d['method']??'cash';if(!in_array($method,['cash','bank'],true))throw new Problem('Invalid receipt method.');$shift=null;if($method==='cash')$shift=$this->requireShift(required($d,'shift_id'),$u);$id=uid();$row=['id'=>$id,'customer_id'=>$customer['id'],'kind'=>'receipt','payment_method'=>$method,'bank_account_id'=>$method==='bank'?required($d,'bank_account_id'):null,'debit_cents'=>0,'credit_cents'=>$amount,'reference'=>required($d,'reference'),'occurred_at'=>stamp(),'created_by'=>$u['id']];$this->db->insert('customer_ledger',$row);if($method==='bank')$this->bankPost($row['bank_account_id'],$amount,'customer_receipt','customer_receipt',$id,$row['reference'],$customer['name'],$u);$remaining=$amount;$invoices=$this->db->all('SELECT l.*,l.debit_cents-COALESCE((SELECT SUM(a.amount_cents) FROM customer_allocations a WHERE a.invoice_entry_id=l.id),0) due FROM customer_ledger l WHERE l.customer_id=? AND l.debit_cents>0 ORDER BY l.occurred_at,l._seq',[$customer['id']]);foreach($invoices as $inv){$allocation=min($remaining,(int)$inv['due']);if($allocation>0){$this->db->insert('customer_allocations',['id'=>uid(),'receipt_entry_id'=>$id,'invoice_entry_id'=>$inv['id'],'amount_cents'=>$allocation]);$remaining-=$allocation;}if($remaining===0)break;}if($shift)$this->db->insert('cash_movements',['id'=>uid(),'shift_id'=>$shift['id'],'kind'=>'credit_receipt','amount_cents'=>$amount,'reason'=>'Credit receipt · '.$customer['name'],'reference_id'=>$id,'created_at'=>stamp(),'created_by'=>$u['id']]);$this->db->audit($u['id'],'receive_payment','customer',$customer['id'],$row);$this->db->event('customer_receipt',$id,$row);return $row;});
    }
    public function chart(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$t=$this->find('tanks',required($d,'tank_id'));$lines=preg_split('/\R/',trim(required($d,'points',60000)));$points=[];foreach($lines as $line){$p=preg_split('/[,;\t]+/',trim($line));if(count($p)!==2||!preg_match('/^\d+$/D',trim($p[0])))throw new Problem('Each chart line must contain dip_mm,liters.');if(array_key_exists((int)$p[0],$points))throw new Problem('Duplicate millimeter reading in chart.');$points[(int)$p[0]]=scaled(trim($p[1]),3);}ksort($points);if(count($points)<2||array_key_first($points)!==0||$points[0]!==0)throw new Problem('Chart must start at 0,0 and contain at least two points.');$last=-1;foreach($points as $ml){if($ml<=$last||$ml>$t['capacity_ml'])throw new Problem('Chart volumes must increase and stay within tank capacity.');$last=$ml;}$id=uid();$row=['id'=>$id,'tank_id'=>$t['id'],'name'=>required($d,'name'),'valid_from'=>stamp(),'created_at'=>stamp()];$this->db->insert('calibration_charts',$row);foreach($points as $mm=>$ml)$this->db->insert('calibration_points',['chart_id'=>$id,'dip_mm'=>$mm,'volume_ml'=>$ml]);$this->db->audit($u['id'],'import_calibration','tank',$t['id'],['chart_id'=>$id,'points'=>count($points)]);$this->db->event('calibration',$id,['chart'=>$row,'points'=>$points]);return $row;});
    }
    public function interpolate(string $chart,int $dip): int {
        $low=$this->db->one('SELECT * FROM calibration_points WHERE chart_id=? AND dip_mm<=? ORDER BY dip_mm DESC LIMIT 1',[$chart,$dip]);$high=$this->db->one('SELECT * FROM calibration_points WHERE chart_id=? AND dip_mm>=? ORDER BY dip_mm LIMIT 1',[$chart,$dip]);if(!$low||!$high)throw new Problem('Dip is outside the calibrated range.');if($low['dip_mm']===$high['dip_mm'])return (int)$low['volume_ml'];$span=$high['dip_mm']-$low['dip_mm'];return (int)$low['volume_ml']+intdiv(($dip-$low['dip_mm'])*($high['volume_ml']-$low['volume_ml'])+intdiv($span,2),$span);
    }
    public function dip(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$tank=$this->find('tanks',required($d,'tank_id'));$chart=$this->db->one('SELECT * FROM calibration_charts WHERE tank_id=? ORDER BY created_at DESC,_seq DESC LIMIT 1',[$tank['id']])??missing('Import this tank’s approved calibration chart first.');$dip=(string)($d['dip_mm']??'');$water=(string)($d['water_mm']??'0');if(!ctype_digit($dip)||!ctype_digit($water)||(int)$water>(int)$dip)throw new Problem('Enter valid whole millimeter readings.');$gross=$this->interpolate($chart['id'],(int)$dip);$waterMl=$this->interpolate($chart['id'],(int)$water);$physical=$gross-$waterMl;$book=$this->balance($tank['id']);$density15=trim((string)($d['density_15']??''));$method=trim((string)($d['density_method']??''));if($density15!==''&&$method==='')throw new Problem('Record the source/method of the externally corrected density.');$row=['id'=>uid(),'tank_id'=>$tank['id'],'chart_id'=>$chart['id'],'dip_mm'=>(int)$dip,'water_mm'=>(int)$water,'gross_ml'=>$gross,'water_ml'=>$waterMl,'physical_ml'=>$physical,'book_ml'=>$book,'variance_ml'=>$physical-$book,'temperature_milli_c'=>($d['temperature']??'')!==''?scaled($d['temperature'],3,true):null,'density_milli_kg_m3'=>($d['density']??'')!==''?scaled($d['density'],3):null,'density_15_milli_kg_m3'=>$density15!==''?scaled($density15,3):null,'density_method'=>$method?:null,'notes'=>trim((string)($d['notes']??'')),'measured_at'=>stamp(),'created_by'=>$u['id']];$this->db->insert('dip_records',$row);$this->db->audit($u['id'],'record_dip','tank',$tank['id'],$row);$this->db->event('dip',$row['id'],$row);return $row;});
    }
    public function adjustStock(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$t=$this->find('tanks',required($d,'tank_id'));$qty=scaled($d['liters']??'',3,true);$after=$this->balance($t['id'])+$qty;if($qty===0||$after<0||$after>$t['capacity_ml'])throw new Problem('Adjustment would create invalid stock.');$reason=required($d,'reason');$id=uid();$this->stock($t['id'],$qty,'adjustment',$id,$reason,$u['id']);$this->db->audit($u['id'],'adjust_stock','tank',$t['id'],['quantity_ml'=>$qty,'reason'=>$reason]);$this->db->event('adjustment',$id,['tank_id'=>$t['id'],'quantity_ml'=>$qty,'reason'=>$reason]);return ['balance_ml'=>$after];});
    }
    public function supplierPayment(array $d,array $u): array {
        return $this->vendorPayment($d,$u);
    }

    public function productRestock(array $d,array $u): array {
        require_role($u,['admin','manager']);return $this->db->tx(function()use($d,$u){$p=$this->find('products',required($d,'product_id'));$qty=scaled($d['quantity']??'',3);$cost=scaled($d['cost']??'');if($qty<=0||$qty%1000!==0||$qty>1000000000)throw new Problem('Enter positive whole units.');$reference=required($d,'reference');$total=$p['stock_milli']+$qty;$avg=intdiv($p['stock_milli']*$p['cost_cents']+$qty*$cost+intdiv($total,2),$total);$this->db->run('UPDATE products SET stock_milli=?,cost_cents=? WHERE id=?',[$total,$avg,$p['id']]);$row=['id'=>uid(),'product_id'=>$p['id'],'quantity_milli'=>$qty,'cost_cents'=>$cost,'reference'=>$reference];$this->db->audit($u['id'],'restock','product',$p['id'],$row);$this->db->event('product_restock',$row['id'],$row);return $row;});
    }
    public function customerStatement(string $id): array {return ['customer'=>$this->db->one('SELECT * FROM customer_balances WHERE id=?',[$id]),'ledger'=>$this->db->all('SELECT * FROM customer_ledger WHERE customer_id=? ORDER BY occurred_at,_seq',[$id])];}
}
