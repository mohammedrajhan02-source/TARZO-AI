<?php
declare(strict_types=1);
use Petrol\Problem;
use function Petrol\{required,require_role,stamp,uid,scaled};
require_once __DIR__.'/app/environment.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');
try {
    require __DIR__.'/app/bootstrap.php';
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));$host=preg_replace('/:\d+$/','',$host);
    if(getenv('PETROLPOS_PREVIEW')!=='1'&&!in_array($host,$config['allowed_hosts'],true))throw new Problem('Host is not allowed. Configure allowed_hosts for this deployment.',403);
    ini_set('session.use_strict_mode','1');session_name('PETROLPOS_SESSION');session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','path'=>'/']);session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(24));$method=$_SERVER['REQUEST_METHOD']??'GET';$action=(string)($_GET['action']??'bootstrap');
    $epoch=(string)$db->setting('recovery_session_epoch','');
if(($_SESSION['recovery_epoch']??'')!==$epoch)unset($_SESSION['user_id']);
    if(!in_array($method,['GET','POST'],true))throw new Problem('Method not allowed.',405);
    $input=[];if($method==='POST'){if((int)($_SERVER['CONTENT_LENGTH']??0)>($action==='recovery_upload'?12*1024*1024:1048576))throw new Problem('Request too large.',413);if(!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))throw new Problem('Session expired. Refresh and try again.',403);$input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);if(!is_array($input))throw new Problem('JSON object expected.');}
    $user=null;if(isset($_SESSION['user_id'])){if(time()-(int)($_SESSION['last_activity']??0)>$config['session_minutes']*60){unset($_SESSION['user_id']);}else{$user=$db->one('SELECT id,username,name,role,active FROM users WHERE id=? AND active=1',[$_SESSION['user_id']]);$_SESSION['last_activity']=time();}}
    $public=['bootstrap','setup','login'];if(!in_array($action,$public,true)&&!$user)throw new Problem('Please sign in.',401);
    $readActions=['bootstrap','state','receipt','statement','report','backup_download','logo','shift_details','ledger_statement'];
    $readActions=array_merge($readActions,['ar_day','ar_invoices','ar_invoice']);
    $readActions[]='purchase_order_report';$readActions[]='purchase_order';$readActions[]='recovery_list';$readActions[]='pump_meter_report';$readActions[]='pump_meter_edit';$readActions[]='pump_day';$readActions[]='pump_day_report';
    if(in_array($action,$readActions,true)&&$method!=='GET')throw new Problem('Use GET for this action.',405);
    if(!in_array($action,$readActions,true)&&$method!=='POST')throw new Problem('Use POST for this action.',405);
    if(!in_array($action,array_merge($public,$readActions,['recovery_upload','recovery_restore','recovery_delete','logout','license_activate','license_check','sync','backup','settings','station_logo','close_shift','change_password','user_status']),true))$licensing->enforce();
    $result=null;
    switch($action){
        case 'recovery_list':
        case 'recovery_upload':
        case 'recovery_restore':
        case 'recovery_delete':
            require_role($user,['admin']);
            $recovery=new Petrol\Recovery($db,$config['data_dir']);
            if($action==='recovery_list')$result=$recovery->listing();
            elseif($action==='recovery_upload')$result=$recovery->upload($input);
            elseif($action==='recovery_delete')$result=$recovery->delete(required($input,'id',32));
            else {
                set_time_limit(180);
                $result=$recovery->restore($input);
                $_SESSION=[];session_destroy();
            }
            break;
    case 'bootstrap':$result=['setup_required'=>(int)$db->value('SELECT COUNT(*) FROM users')===0,'csrf'=>$_SESSION['csrf'],'user'=>$user,'version'=>$config['version'],'license'=>$user?$licensing->state():null,'cloud_configured'=>$config['cloud_url']!==''&&$config['cloud_token']!=='','station_name'=>$db->setting('station_name','Ezy Filling System')];break;
        case 'setup':$user=$station->setup($input);session_regenerate_id(true);$_SESSION['user_id']=$user['id'];
$_SESSION['recovery_epoch']=$epoch;$_SESSION['last_activity']=time();$result=['user'=>$user];break;
        case 'login':
            $username=required($input,'username',64);$bucket=hash('sha256',strtolower($username).'|'.($_SERVER['REMOTE_ADDR']??''));$attempt=$db->one('SELECT * FROM login_attempts WHERE bucket=?',[$bucket]);if($attempt&&$attempt['attempts']>=5&&$attempt['locked_until']>time())throw new Problem('Too many attempts. Try again in five minutes.',429);
            $row=$db->one('SELECT * FROM users WHERE username=? AND active=1',[$username]);$valid=$row&&is_string($input['password']??null)&&strlen($input['password'])<=72&&password_verify((string)($input['password']??''),$row['password_hash']);
            if(!$valid){$count=($attempt&&$attempt['locked_until']>time()?(int)$attempt['attempts']:0)+1;$db->run('INSERT INTO login_attempts(bucket,attempts,locked_until) VALUES(?,?,?) ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),locked_until=VALUES(locked_until)',[$bucket,$count,time()+300]);throw new Problem('Username or password is incorrect.',401);}
            $db->run('DELETE FROM login_attempts WHERE bucket=?',[$bucket]);session_regenerate_id(true);$_SESSION['user_id']=$row['id'];$_SESSION['recovery_epoch']=$epoch;$_SESSION['last_activity']=time();$db->audit($row['id'],'login','user',$row['id']);$result=['ok'=>true];break;
        case 'logout':$_SESSION=[];session_destroy();$result=['ok'=>true];break;
        case 'state':$result=$station->snapshot($user);$result['license']=$licensing->state();$result['cloud_configured']=$config['cloud_url']!==''&&$config['cloud_token']!=='';break;
        case 'category':$result=$station->workOnce($action,$input,$user,fn()=>$station->saveCategory($input,$user));break;
        
case 'category_delete':
    $result=$station->workOnce(
        $action,
        $input,
        $user,
        fn()=>$station->deleteCategory($input,$user)
    );
    break;

        case 'product':$result=$station->workOnce($action,$input,$user,fn()=>$station->saveProduct($input,$user));break;
        case 'product_delete':
    $result=$station->workOnce(
        $action,$input,$user,
        fn()=>$station->deleteProduct($input,$user)
    );
    break;
case 'pump':
    $result=$station->workOnce(
        $action,$input,$user,
        fn()=>$station->savePump($input,$user)
    );
    break;

case 'pump_delete':
    $result=$station->workOnce(
        $action,$input,$user,
        fn()=>$station->deletePump($input,$user)
    );
    break;

        case 'vendor':$result=$station->workOnce($action,$input,$user,fn()=>$station->saveVendor($input,$user));break;
     case 'vendor_delete':
    $result = $station->workOnce(
        $action,
        $input,
        $user,
        fn() => $station->deleteVendor($input, $user)
    
    );
    break;   
        case 'ar_day':$result=$station->arDay($_GET,$user);break;
        case 'ar_invoices':$result=$station->arInvoices($_GET,$user);break;
        case 'ar_invoice':$result=$station->arInvoice($_GET,$user);break;
        case 'ar_allocation_save':$result=$station->workOnce($action,$input,$user,fn()=>$station->arSaveAllocation($input,$user));break;
        case 'ar_invoice_save':$result=$station->workOnce($action,$input,$user,fn()=>$station->arSaveInvoice($input,$user));break;
        case 'pump_day':$result=$station->meterDay($_GET,$user);break;
        case 'pump_day_report':$result=$station->meterDailySummary($_GET,$user);break;
        case 'pump_day_save':$result=$station->workOnce($action,$input,$user,fn()=>$station->saveMeterDay($input,$user));break;
        case 'pump_meter_report':$result=$station->meterReport($_GET,$user);break;
        case 'pump_meter_edit':$result=$station->meterEdit($_GET,$user);break;
        case 'pump_meter_update':$result=$station->workOnce($action,$input,$user,fn()=>$station->updateMeterSale($input,$user));break;
        case 'pump_meter_batch':$result=$station->workOnce($action,$input,$user,fn()=>$station->saveMeterBatch($input,$user));break;
        case 'pump_meter_sale':$result=$station->workOnce($action,$input,$user,fn()=>$station->saveMeterSale($input,$user));break;
        case 'pump_meter_collect':$result=$station->workOnce($action,$input,$user,fn()=>$station->collectMeterCash($input,$user));break;
        case 'purchase_order_report':$result=$station->purchaseOrderReport($_GET,$user);break;
        case 'purchase_order':$result=$station->purchaseOrderDetail($_GET,$user);break;
        case 'purchase_order_save':$result=$station->workOnce($action,$input,$user,fn()=>$station->savePurchaseOrder($input,$user));break;
        case 'purchase_batch':$result=$station->workOnce($action,$input,$user,fn()=>$station->purchaseBatch($input,$user));break;
        case 'purchase':$result=$station->workOnce($action,$input,$user,fn()=>$station->purchase($input,$user));break;
        case 'purchase_update':$result=$station->workOnce($action,$input,$user,fn()=>$station->updatePurchase($input,$user));break;
        case 'purchase_void':$result=$station->workOnce($action,$input,$user,fn()=>$station->voidPurchase($input,$user));break;
        case 'master':$result=$station->workOnce($action,$input,$user,fn()=>$station->master(required($input,'kind'),$input,$user));break;
        case 'open_shift':$result=$station->workOnce($action,$input,$user,fn()=>$station->openShift($input,$user));break;
        case 'sale':$result=$station->sale($input,$user);break;
        case 'test_fuel':$result=$station->workOnce($action,$input,$user,fn()=>$station->testFuel($input,$user));break;
        case 'price':$result=$station->workOnce($action,$input,$user,fn()=>$station->changePrice($input,$user));break;
        case 'close_shift':$result=$station->workOnce($action,$input,$user,fn()=>$station->closeShift($input,$user));break;
        case 'delivery':$result=$station->workOnce($action,$input,$user,fn()=>$station->delivery($input,$user));break;
        case 'cash_move':$result=$station->workOnce($action,$input,$user,fn()=>$station->cashMove($input,$user));break;
        case 'expense':$result=$station->workOnce($action,$input,$user,fn()=>$station->expense($input,$user));break;
        case 'vendor_payable':$result=$station->workOnce($action,$input,$user,fn()=>$station->addVendorPayable($input,$user));break;
        case 'party_cheque':$result=$station->workOnce($action,$input,$user,fn()=>$station->registerPartyCheque($input,$user));break;
        case 'supplier_payment':$result=$station->workOnce($action,$input,$user,fn()=>$station->supplierPayment($input,$user));break;
        case 'product_restock':$result=$station->workOnce($action,$input,$user,fn()=>$station->productRestock($input,$user));break;
        case 'customer_receipt':$result=$station->workOnce($action,$input,$user,fn()=>$station->customerReceipt($input,$user));break;
        case 'chart':$result=$station->workOnce($action,$input,$user,fn()=>$station->chart($input,$user));break;
        case 'dip':$result=$station->workOnce($action,$input,$user,fn()=>$station->dip($input,$user));break;
        case 'adjust_stock':$result=$station->workOnce($action,$input,$user,fn()=>$station->adjustStock($input,$user));break;
        case 'employee':$result=$station->workOnce($action,$input,$user,fn()=>$station->saveEmployee($input,$user));break;
        case 'employee_link':$result=$station->workOnce($action,$input,$user,fn()=>$station->linkEmployee($input,$user));break;
        case 'employee_status':$result=$station->workOnce($action,$input,$user,fn()=>$station->employeeStatus($input,$user));break;
        case 'employee_delete':
    $result = $station->workOnce(
        $action,
        $input,
        $user,
        fn() => $station->deleteEmployee(
            $input,
            $user
        )
    );
    break;
        case 'bank_account':$result=$station->workOnce($action,$input,$user,fn()=>$station->createBank($input,$user));break;
        case 'bank_move':$result=$station->workOnce($action,$input,$user,fn()=>$station->bankMove($input,$user));break;
        case 'bank_match':$result=$station->workOnce($action,$input,$user,fn()=>$station->bankMatch($input,$user));break;
        case 'product_purchase':$result=$station->workOnce($action,$input,$user,fn()=>$station->productPurchase($input,$user));break;
        case 'pump_receipt':$result=$station->workOnce($action,$input,$user,fn()=>$station->pumpReceipt($input,$user));break;
        case 'user_access':$result=$station->workOnce($action,$input,$user,fn()=>$station->userAccess($input,$user));break;
        case 'ledger_statement':$result=$station->ledgerStatement($_GET,$user);break;
        case 'receipt':$result=$station->receipt(required($_GET,'id'));if(in_array($user['role'],['cashier','viewer'],true)){unset($result['cost_cents']);foreach($result['items'] as &$i)unset($i['cost_cents']);unset($i);}break;
        case 'shift_details':$result=$station->shiftTotals(required($_GET,'id'));break;
        case 'statement':$result=$station->customerStatement(required($_GET,'id'));break;

        case 'station_logo':
            $logo=$input['logo']??'';
            if(!is_string($logo))throw new Problem('Logo must be 300 KB or smaller.');
            $result=$station->saveLogo($logo,$user);
            break;
        case 'settings':require_role($user,['admin','manager']);$db->tx(function()use($input,$db,$user){foreach(['station_name','address','phone','receipt_footer','email','registration_no']as $k)if(array_key_exists($k,$input))$db->set($k,Petrol\text_field($input,$k,400));$db->audit($user['id'],'update','settings','station');$db->event('settings','station',['station_name'=>$db->setting('station_name'),'address'=>$db->setting('address'),'phone'=>$db->setting('phone')]);});$result=['ok'=>true];break;
        case 'change_password':$row=$station->find('users',$user['id']);if(!password_verify((string)($input['current_password']??''),$row['password_hash']))throw new Problem('Current password is incorrect.');$pw=Petrol\account_password($input,'new_password');$db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($pw,PASSWORD_DEFAULT),$user['id']]);session_regenerate_id(true);$result=['ok'=>true];break;
        case 'user_status':require_role($user,['admin']);$target=$station->find('users',required($input,'id'));if($target['id']===$user['id'])throw new Problem('You cannot disable your own account.');if($db->one('SELECT id FROM shifts WHERE user_id=? AND status=\'open\'',[$target['id']]))throw new Problem('Close the employee’s shift before disabling the account.');$db->run('UPDATE users SET active=? WHERE id=?',[!empty($input['active'])?1:0,$target['id']]);$db->audit($user['id'],'status','user',$target['id'],['active'=>!empty($input['active'])]);$result=['ok'=>true];break;
        case 'license_activate':$result=$licensing->activate(required($input,'license_key',100),$user);break;
        case 'license_check':$result=$licensing->refresh();break;
        case 'sync':$result=(new Petrol\Sync($db,$station,$config))->send($user);break;
        case 'backup':require_role($user,['admin','manager']);$folder=rtrim($config['data_dir'],'/\\').'/backups';if(!is_dir($folder))mkdir($folder,0700,true);$name='EFS-'.gmdate('Ymd-His').'-'.substr(uid(),0,6).'.sql';$meta=$db->backup($folder.'/'.$name);$id=uid();$db->insert('backup_history',['id'=>$id,'filename'=>$name,'size_bytes'=>$meta['size'],'sha256'=>$meta['sha256'],'created_at'=>stamp(),'created_by'=>$user['id']]);$db->audit($user['id'],'backup','database',$id);$result=['id'=>$id,'filename'=>$name,'sha256'=>$meta['sha256']];break;
        case 'backup_download':require_role($user,['admin','manager']);$b=$station->find('backup_history',required($_GET,'id'));$path=rtrim($config['data_dir'],'/\\').'/backups/'.basename($b['filename']);if(!is_file($path))throw new Problem('Backup file not found.',404);header('Content-Type: application/sql');header('Content-Disposition: attachment; filename="'.basename($b['filename']).'"');readfile($path);exit;
        case 'report':
            $from=(string)($_GET['from']??$station->meterToday());$to=(string)($_GET['to']??$from);if(!Petrol\calendar_date($from)||!Petrol\calendar_date($to)||$from>$to)throw new Problem('Select a valid date range.');$tz=new DateTimeZone($db->setting('timezone','Asia/Colombo'));$start=(new DateTimeImmutable($from.' 00:00:00',$tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');$end=(new DateTimeImmutable($to.' 00:00:00',$tz))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $rows=$db->all('SELECT s.receipt_no,s.created_at,u.name operator,s.source,s.total_cents,COALESCE(c.name,\'Walk-in\') customer,COALESCE((SELECT SUM(quantity_milli) FROM sale_items i WHERE i.sale_id=s.id AND i.kind=\'fuel\'),0) fuel_ml,COALESCE((SELECT SUM(amount_cents) FROM sale_payments p WHERE p.sale_id=s.id AND p.method=\'cash\'),0) cash_cents,COALESCE((SELECT SUM(amount_cents) FROM sale_payments p WHERE p.sale_id=s.id AND p.method=\'card\'),0) card_cents,COALESCE((SELECT SUM(amount_cents) FROM sale_payments p WHERE p.sale_id=s.id AND p.method=\'qr\'),0) qr_cents,COALESCE((SELECT SUM(amount_cents) FROM sale_payments p WHERE p.sale_id=s.id AND p.method=\'credit\'),0) credit_cents FROM sales s JOIN users u ON u.id=s.created_by LEFT JOIN customers c ON c.id=s.customer_id WHERE s.created_at>=? AND s.created_at<? ORDER BY s.created_at,s._seq',[$start,$end]);
            if(($_GET['format']??'')==='csv'){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="EFS-sales-'.$from.'-'.$to.'.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Receipt','UTC time','Operator','Source','Total','Customer','Liters','Cash','Card','QR','Credit'],',','"','');foreach($rows as $r){foreach(['total_cents','cash_cents','card_cents','qr_cents','credit_cents']as $k)$r[$k]=number_format($r[$k]/100,2,'.','');$r['fuel_ml']=number_format($r['fuel_ml']/1000,3,'.','');foreach($r as &$v)if(is_string($v)&&preg_match('/^[=+@\-\t\r]/',$v))$v="'".$v;unset($v);fputcsv($out,array_values($r),',','"','');}fclose($out);exit;}$result=['from'=>$from,'to'=>$to,'rows'=>$rows];break;
        default:throw new Problem('Unknown action.',404);
    }
    echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Problem $e){http_response_code($e->status);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch(JsonException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Send a valid JSON request.']);}
catch(PDOException $e){error_log($e->getMessage());http_response_code(409);echo json_encode(['ok'=>false,'error'=>($e->getCode()==='23000' && isset($e->errorInfo[1]) && (int)$e->errorInfo[1]===1062)?(($action==='master'&&($input['kind']??'')==='user')?'That username already exists. Choose another username.':'That code, invoice, indent or active shift already exists.'):'The database could not complete this request. No partial transaction was saved.']);}
catch(Throwable $e){error_log((string)$e);http_response_code(500);echo json_encode(['ok'=>false,'error'=>'The request could not be completed. Check the application log.']);}
