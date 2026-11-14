<?php
require_once __DIR__.'/app/environment.php';
require_once __DIR__.'/app/Core.php';
require_once __DIR__.'/app/Station.php';
header('Content-Type: text/html; charset=utf-8');
$address=isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'';
$local=in_array($address,['127.0.0.1','::1','::ffff:127.0.0.1'],true);
if(!$local){http_response_code(403);exit('Open setup on this computer at http://localhost/efs/.');}
ini_set('session.use_strict_mode','1');
session_name('EFS_INSTALL');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
$defaults=require __DIR__.'/config/default.php';
$values=['host'=>'127.0.0.1','port'=>'3306','database'=>'efs','db_user'=>'root','station_name'=>'Ezy Filling System','owner_name'=>'','username'=>'owner','demo'=>'0'];
foreach($values as $key=>$default)if(isset($_POST[$key])&&is_string($_POST[$key]))$values[$key]=$_POST[$key];
$pdoReady=extension_loaded('pdo_mysql');
$writable=is_writable(__DIR__.'/config')&&is_writable(__DIR__.'/var');
$configured=is_file(__DIR__.'/config/database.php');
$success=false;$error='';$connectionOk=false;
function efs_e($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
if($configured){
    try{$existing=require __DIR__.'/config/database.php';$existingDb=new Petrol\Db($existing);$connectionOk=true;}
    catch(Throwable $e){$error='The saved database connection is unavailable. Start MySQL in XAMPP, then check host, port, database and password in config/database.php. Existing station data has not been replaced.';}
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!$configured){
    $lock=null;
    try{
        if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new Petrol\Problem('Refresh this page and try again.');
        if(!$pdoReady)throw new Petrol\Problem('Enable extension=pdo_mysql in XAMPP/php/php.ini, restart Apache, then refresh.');
        if(!$writable)throw new Petrol\Problem('Allow Apache to write to the efs/config and efs/var folders.');
        $lock=fopen(__DIR__.'/var/install.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new Petrol\Problem('Another setup is running. Wait for it to finish, then refresh.');
        if(is_file(__DIR__.'/config/database.php'))throw new Petrol\Problem('Setup is already complete. Open the station.');
        $dbPassword=(string)($_POST['db_password']??'');
        $accountPassword=(string)($_POST['password']??'');
        $accountPassword=Petrol\account_password($_POST);
        if($accountPassword!==(string)($_POST['password_confirm']??''))throw new Petrol\Problem('Owner passwords do not match.');
        if(!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/D',$values['username']))throw new Petrol\Problem('Owner username must contain 3–64 letters, numbers, dots, @, _ or -.');
        $stationName=Petrol\required($values,'station_name');$ownerName=Petrol\required($values,'owner_name');
        $database=['host'=>trim($values['host']),'port'=>(int)$values['port'],'database'=>trim($values['database']),'username'=>trim($values['db_user']),'password'=>$dbPassword];
        // First connect directly: a manually created database needs no CREATE privilege.
        try{$pdo=Petrol\Db::connect($database);}
        catch(PDOException $e){
            if(Petrol\mysql_error_number($e)!==1049)throw $e;
            $server=Petrol\Db::connect($database,false);
            $server->exec('CREATE DATABASE `'.$database['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
            $pdo=Petrol\Db::connect($database);
        }
        $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if(count($tables)===0){
            $schema=file_get_contents(__DIR__.'/mysql/efs.sql');
            // The bundled schema contains no triggers, procedures, or DELIMITER blocks.
            $schema=preg_replace('/^\s*--[^\r\n]*/m','',$schema);
            foreach(explode(';',$schema) as $statement){
                $statement=trim($statement);
                if($statement!=='')$pdo->exec($statement);
            }
        
        }else{
            if(!in_array('schema_migrations',$tables,true)||!in_array('users',$tables,true)||!in_array('efs_mutex',$tables,true))throw new Petrol\Problem('That database already contains other or incomplete tables. Choose a NEW database name, such as efs_station2. No existing tables have been deleted.');
            if(!in_array((int)$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn(),[3,4,5,6,7,8,9,10,11],true))throw new Petrol\Problem('That database belongs to a different version. Choose a new database name.');
            if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0)throw new Petrol\Problem('That database already has a station owner. To reconnect your own station, copy database.example.php to database.php and enter its connection details. Setup cannot replace its users.');
            // A completed empty SQL import can be attached through this same wizard.

        }
        $db=new Petrol\Db($database);
        $station=new Petrol\Station($db);
        // Claim configuration exclusively. Another setup cannot replace credentials.
        $configText="<?php\n// Created by EFS setup. Keep this file private.\nreturn ".var_export($database,true).";\n";
        $configPath=__DIR__.'/config/database.php';
        $file=@fopen($configPath,'xb');
        if(!$file)throw new Petrol\Problem('Could not save database settings. Check write permissions on efs/config.');
        $saved=fwrite($file,$configText)===strlen($configText)&&fflush($file);fclose($file);@chmod($configPath,0600);
        if(!$saved){@unlink($configPath);throw new Petrol\Problem('Could not save database settings completely. Check free disk space.');}
        try{
            $station->setup(['owner_name'=>$ownerName,'username'=>$values['username'],'password'=>$accountPassword,'station_name'=>$stationName,'currency'=>'LKR','demo'=>$values['demo']==='1']);
        }catch(Throwable $e){@unlink($configPath);throw $e;}
        $configured=true;$connectionOk=true;$success=true;$_SESSION['installed']=true;
        session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));
    }catch(PDOException $e){
        error_log('EFS setup: '.$e->getMessage());$number=Petrol\mysql_error_number($e);
        if(in_array($number,[2002,2003],true))$error='Cannot connect to MySQL. Start MySQL in the XAMPP Control Panel and check the port (normally 3306).';
        elseif(in_array($number,[1044,1045,1698],true))$error='MySQL rejected the account or database permissions. Fresh XAMPP normally uses username root and a blank database password. If you changed it, enter that password. You can create an empty database in phpMyAdmin first.';
        else $error='Database setup could not finish. Check var permissions and the Apache PHP error log. If tables were partially created, choose a new empty database name. Existing tables were not deleted.';
    }catch(Throwable $e){error_log('EFS setup: '.$e->getMessage());$error=$e instanceof Petrol\Problem?$e->getMessage():'Setup could not finish. Check the Apache PHP error log and folder permissions.';}
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>EFS · XAMPP setup</title><link rel="icon" href="assets/mark.svg"><link rel="stylesheet" href="assets/setup.css"><link rel="stylesheet" href="assets/navy-setup.css?v=1.0"></head>
<body><div class="setup-shell"><aside class="intro"><a class="wordmark" href="index.php"><img src="assets/mark.svg" alt="" width="46" height="46"><span>Ezy <b>EFS</b><small>FILLING SYSTEM</small></span></a><div class="intro-copy"><div class="eyebrow">YOUR STATION. YOUR COMPUTER.</div><h1>Every fill.<br>Made easy.</h1><p>One local workspace for fuel, sales, stock, shifts and accounts.</p><p class="tamil" lang="ta">உங்கள் filling station-க்கான கணக்குகள், ஒரே இடத்தில்.</p></div><ol class="steps"><li><span>01</span><div><strong>Start XAMPP</strong><small>Apache + MySQL → Start</small></div></li><li class="current"><span>02</span><div><strong>Set up your station</strong><small>Connect MySQL and create your owner account.</small></div></li><li><span>03</span><div><strong>Open your workspace</strong><small>Sign in and add your station details.</small></div></li></ol><div class="intro-footer">EFS 2.0E <span>LOCAL XAMPP EDITION</span></div></aside>
<main class="setup-main"><header class="setup-top"><span class="tag">XAMPP READY</span><a href="START-HERE.html">தமிழ் / Setup help ↗</a></header>
<?php if($configured&&$connectionOk):?>
<section class="success-card"><div class="success-mark" aria-hidden="true">✓</div><p class="eyebrow">STATION CONNECTED</p><h2><?=$success?'Your workspace is ready.':'EFS is already configured.'?></h2><p><?=$success?'The database and tables are installed. Sign in using the owner username and password you just chose.':'Your saved database connection is working. Continue to your station workspace.'?></p><p lang="ta">Setup முடிந்தது. நீங்கள் உருவாக்கிய owner account-ல் login செய்யுங்கள்.</p><a class="primary button" href="index.php">Open EFS workspace <span>→</span></a><p class="fine">Database: MySQL / MariaDB · Currency: LKR<br>Setup is now locked. Existing users and records are protected.</p></section>
<?php else:?>
<div class="page-heading"><p class="eyebrow">LET’S GET YOU STARTED</p><h2><?=$configured?'Check the connection.':'Set up your station.'?></h2><p>எல்லா settings-உம் ஒரே பக்கத்தில். Fresh XAMPP defaults are filled in below.</p></div>
<div class="checks" aria-label="System requirements"><span class="<?=$pdoReady?'pass':'fail'?>">● PHP <?=efs_e(PHP_VERSION)?></span><span class="<?=$pdoReady?'pass':'fail'?>">● PDO MySQL <?=$pdoReady?'ready':'missing'?></span><span class="<?=$writable?'pass':'fail'?>">● Folders <?=$writable?'writable':'need permission'?></span></div>
<?php if($error):?><div class="alert" role="alert"><?=efs_e($error)?></div><?php endif?>
<?php if(!$configured):?><form method="post" id="setup-form" autocomplete="off"><input type="hidden" name="csrf" value="<?=efs_e($_SESSION['csrf'])?>">
<section class="form-section"><div class="section-head"><span>01</span><div><h3>Database connection</h3><p>Use the MySQL service in XAMPP. Its database server may be named MariaDB.</p></div></div><div class="form-grid"><label>MySQL host<input name="host" value="<?=efs_e($values['host'])?>" required maxlength="253"></label><label>MySQL port<input name="port" type="number" min="1" max="65535" value="<?=efs_e($values['port'])?>" required></label><label>Database name<input name="database" value="<?=efs_e($values['database'])?>" pattern="[A-Za-z0-9_]{1,64}" maxlength="64" required><small>Created automatically if it does not exist.</small></label><label>MySQL username<input name="db_user" value="<?=efs_e($values['db_user'])?>" required maxlength="100"></label><label class="full">MySQL password <span class="optional">optional on fresh XAMPP</span><input name="db_password" type="password" autocomplete="new-password" placeholder="Leave blank if you have not set a MySQL password"><small>இது MySQL password. கீழே உருவாக்கும் EFS login password வேறு.</small></label></div></section>
<section class="form-section"><div class="section-head"><span>02</span><div><h3>Station & owner account</h3><p>Choose the details you will use to sign in to EFS.</p></div></div><div class="form-grid"><label class="full">Station name<input name="station_name" value="<?=efs_e($values['station_name'])?>" maxlength="160" required></label><label>Your name<input name="owner_name" value="<?=efs_e($values['owner_name'])?>" maxlength="160" autocomplete="name" required></label><label>EFS username<input name="username" value="<?=efs_e($values['username'])?>" pattern="[A-Za-z0-9_.@\-]{3,64}" maxlength="64" autocomplete="username" required></label><label>EFS password<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required><small>10+ characters; maximum 72 UTF-8 bytes. Spaces are preserved.</small></label><label>Confirm EFS password<input name="password_confirm" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label></div></section>
<fieldset class="form-section"><legend>03 · Starting records</legend><div class="record-options"><label class="record-option"><input type="radio" name="demo" value="0" <?=$values['demo']!=='1'?'checked':''?>><span><strong>Empty station <em>RECOMMENDED</em></strong><small>உங்கள் உண்மையான station details-ஐ add செய்ய.<br>No sample inventory or sales.</small></span></label><label class="record-option"><input type="radio" name="demo" value="1" <?=$values['demo']==='1'?'checked':''?>><span><strong>Demo station</strong><small>Test செய்ய sample pumps, fuel and products.<br>Training data — use a separate database for real work.</small></span></label></div></fieldset>
<div class="submit-row"><p>Runs locally after setup.<br>No Composer, npm or internet required.</p><button class="primary" type="submit" <?=!$pdoReady||!$writable?'disabled':''?>>Install & open EFS <span>→</span></button></div><p class="fine">Use a new, empty database. Setup never deletes existing tables. Keep Apache and MySQL running while you use EFS.</p></form>
<?php else:?><a class="button primary" href="index.php">Retry connection →</a><p class="fine">To correct saved connection details, edit config/database.php in VS Code or Notepad. Do not delete your database.</p><?php endif?>
<?php endif?><footer class="main-footer">Ezy Filling System · PHP 7.4 / 8.x · MySQL / MariaDB</footer></main></div>
<script>document.getElementById('setup-form')&&document.getElementById('setup-form').addEventListener('submit',function(){var b=this.querySelector('button[type=submit]');b.disabled=true;b.textContent='Setting up your station…';});</script></body></html>
