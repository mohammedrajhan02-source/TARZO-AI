<?php
declare(strict_types=1);
namespace Petrol;

final class Problem extends \RuntimeException {
    public int $status;
    public function __construct(string $message, int $status=422) {
        parent::__construct($message);
        $this->status=$status;
    }
}
function uid(): string { return bin2hex(random_bytes(16)); }
function stamp(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function mysql_error_number(\PDOException $error): int {
    // Older PDO builds omit errorInfo when the connection constructor fails.
    if(isset($error->errorInfo[1]))return (int)$error->errorInfo[1];
    if(preg_match('/\[(\d{4})\]/',$error->getMessage(),$match))return (int)$match[1];
    return is_numeric($error->getCode())?(int)$error->getCode():0;
}
function missing(string $message,int $status=422) { throw new Problem($message,$status); }
function required(array $data,string $key,int $max=160): string {
    $v=text_field($data,$key,$max);
    if($v==='')throw new Problem("Please enter a valid $key (maximum $max characters).");
    return $v;
}
function text_field(array $data,string $key,int $max=160): string {
    $raw=$data[$key]??'';
    if(!is_scalar($raw))throw new Problem("Please enter a valid $key.");
    $v=trim((string)$raw);
    $length=preg_match_all('/./us',$v,$unused);
    if($length===false||$length>$max)throw new Problem("Please enter a valid $key (maximum $max characters).");
    return $v;
}
function account_password(array $data,string $key='password'): string {
    $v=$data[$key]??'';
    // Bcrypt uses at most 72 bytes. Never trim or silently truncate credentials.
    if(!is_string($v)||strpos($v,"\0")!==false||strlen($v)>72||preg_match_all('/./us',$v,$unused)<10||trim($v)==='')
        throw new Problem('Password must contain at least 10 characters and at most 72 UTF-8 bytes. Spaces are preserved.');
    return $v;
}
function account_username(array $data): string {
    $v=required($data,'username',64);
    if(!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/D',$v))throw new Problem('Username must contain 3–64 letters, numbers, dots, @, _ or -.');
    return $v;
}
function calendar_date(string $value): bool {
    if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m))return false;
    return checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
}
function scaled($value,int $digits=2,bool $negative=false): int {
    if(!is_scalar($value)||is_bool($value))throw new Problem('Enter a valid number.');
    $s=trim((string)$value);
    if(!preg_match('/^'.($negative?'-?':'').'\d{1,10}(?:\.\d{1,'.$digits.'})?$/D',$s))throw new Problem("Enter a valid number with at most $digits decimal places.");
    $sign=substr($s,0,1)==='-'?-1:1;
    $parts=explode('.',ltrim($s,'-'));
    $n=((int)$parts[0])*(10**$digits)+(int)str_pad($parts[1]??'',$digits,'0');
    if($n>100000000000)throw new Problem('Number exceeds the supported range.');
    return $n*$sign;
}
function line_total(int $quantityMilli,int $priceCents): int {
    if($quantityMilli<0||$quantityMilli>1000000000||$priceCents<0||$priceCents>10000000)throw new Problem('Quantity or rate exceeds the supported range.');
    return intdiv($quantityMilli*$priceCents+500,1000);
}
function require_role(array $user,array $roles): void {
    if(!in_array($user['role'],$roles,true))throw new Problem('Your role cannot perform this action.',403);
}

/** PDO MySQL/MariaDB storage. Amounts stay in integer cents and millilitres. */
final class Db {
    public \PDO $pdo;
    private bool $inside=false;

    public static function connect(array $config,bool $selectDatabase=true): \PDO {
        if(PHP_INT_SIZE<8)throw new Problem('Use a 64-bit PHP / XAMPP installation.',500);
        if(!extension_loaded('pdo_mysql'))throw new Problem('Enable extension=pdo_mysql in the XAMPP php.ini, then restart Apache.',500);
        $name=(string)($config['database']??'efs');
        $host=(string)($config['host']??'127.0.0.1');
        $port=(int)($config['port']??3306);
        if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name)||!preg_match('/^[A-Za-z0-9.:-]+$/D',$host)||$port<1||$port>65535)throw new Problem('Check the database name, host and port.',500);
        $dsn='mysql:host='.$host.';port='.$port.';charset=utf8mb4'.($selectDatabase?';dbname='.$name:'');
        $pdo=new \PDO($dsn,(string)($config['username']??'root'),(string)($config['password']??''),[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES=>false,
            \PDO::ATTR_EMULATE_PREPARES=>false,
            \PDO::ATTR_TIMEOUT=>5,
        ]);
        $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone='+00:00'");
        return $pdo;
    }
    public function __construct(array $config) {
        $this->pdo=self::connect($config);
        $version=(int)$this->value('SELECT MAX(version) FROM schema_migrations');
        if($version===3){require_once __DIR__.'/Migrations.php';upgrade_workspace_4($this,$config);$version=(int)$this->value('SELECT MAX(version) FROM schema_migrations');}
        if($version===4){require_once __DIR__.'/Migrations.php';upgrade_workspace_5($this,$config);$version=(int)$this->value('SELECT MAX(version) FROM schema_migrations');}
        if($version===5){require_once __DIR__.'/Migrations.php';upgrade_accounts_payable_6($this,$config);$version=(int)$this->value('SELECT MAX(version) FROM schema_migrations');}
        if($version===6){require_once __DIR__.'/PurchasingBatch.php';upgrade_purchasing_7($this,$config);$version=(int)$this->value('SELECT MAX(version) FROM schema_migrations');}
        if ($version === 7) {
            require_once __DIR__ . '/PumpMeterSales.php';
            upgrade_pump_meter_8($this, $config);
            $version = (int) $this->value(
                'SELECT MAX(version) FROM schema_migrations'
            );
        }

        if ($version === 8) {
            require_once __DIR__ . '/PumpMeterSales.php';
            upgrade_pump_meter_9($this, $config);
            $version = (int) $this->value(
                'SELECT MAX(version) FROM schema_migrations'
            );
        }

        if ($version === 9) {
            require_once __DIR__.'/PurchaseOrders.php';
            upgrade_purchase_orders_10($this,$config);
            $version=(int)$this->value('SELECT MAX(version) FROM schema_migrations');
        }
        if ($version === 10) {
            require_once __DIR__.'/AccountsReceivable.php';
            upgrade_receivables_11($this,$config);
            $version=(int)$this->value('SELECT MAX(version) FROM schema_migrations');
        }
        if ($version !== 11) {
            throw new Problem(
                'This database needs the EFS XAMPP schema. Open setup.php; do not overwrite an existing station database.',
                500
            );
        }
    }
    public function run(string $sql,array $params=[]): \PDOStatement {
        $s=$this->pdo->prepare($sql);
        foreach(array_values($params) as $i=>$v)$s->bindValue($i+1,$v,$v===null?\PDO::PARAM_NULL:(is_int($v)?\PDO::PARAM_INT:(is_bool($v)?\PDO::PARAM_BOOL:\PDO::PARAM_STR)));
        $s->execute();return $s;
    }
    private function numbers(\PDOStatement $s,array $row): array {
        foreach(array_keys($row) as $i=>$name){
            $meta=$s->getColumnMeta($i);
            if($meta&&in_array($meta['native_type'],['TINY','SHORT','LONG','LONGLONG','INT24','NEWDECIMAL'],true)&&is_string($row[$name])&&preg_match('/^-?\d{1,18}$/D',$row[$name]))$row[$name]=(int)$row[$name];
        }
        return $row;
    }
    public function all(string $sql,array $params=[]): array {
        $s=$this->run($sql,$params);$rows=[];
        while(($r=$s->fetch())!==false)$rows[]=$this->numbers($s,$r);
        return $rows;
    }
    public function one(string $sql,array $params=[]): ?array {
        $s=$this->run($sql,$params);$r=$s->fetch();return $r===false?null:$this->numbers($s,$r);
    }
    public function value(string $sql,array $params=[]) {
        $s=$this->run($sql,$params);$r=$s->fetch();
        if($r===false)return false;
        return array_values($this->numbers($s,$r))[0];
    }
    public function insert(string $table,array $row): void {
        $keys=array_keys($row);
        foreach(array_merge([$table],$keys) as $key)if(!preg_match('/^[a-z_][a-z_0-9]*$/D',$key))throw new \LogicException('Invalid SQL identifier.');
        $this->run('INSERT INTO `'.$table.'` (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($row));
    }
    public function tx(callable $fn) {
        if($this->inside)return $fn();
        $this->pdo->beginTransaction();$this->inside=true;
        try{
            // Preserve the original single-station write ordering across cashiers.
            if($this->pdo->query('SELECT id FROM efs_mutex WHERE id=1 FOR UPDATE')->fetchColumn()===false)
                throw new Problem('The station lock record is missing. Restore a complete backup before continuing.',503);
            $result=$fn();$this->pdo->commit();$this->inside=false;return $result;
        }catch(\Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            $this->inside=false;throw $e;
        }
    }
    public function setting(string $key,$fallback='') {
        $v=$this->value('SELECT value FROM settings WHERE `key`=?',[$key]);return $v===false?$fallback:$v;
    }
    public function set(string $key,string $value): void {
        $this->run('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$value]);
    }
    public function event(string $type,string $id,array $payload): void {
        $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->insert('sync_outbox',['event_id'=>uid(),'aggregate_type'=>$type,'aggregate_id'=>$id,'payload_json'=>$json,'payload_sha256'=>hash('sha256',$json),'created_at'=>stamp()]);
    }
    public function audit(?string $user,string $action,string $type,string $id,array $details=[]): void {
        $this->insert('audit_logs',['id'=>uid(),'user_id'=>$user,'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,'details_json'=>json_encode($details,JSON_THROW_ON_ERROR),'created_at'=>stamp()]);
    }
    public function backup(string $destination): array {
        $handle=@fopen($destination,'xb');
        if(!$handle)throw new Problem('Cannot write a backup. Check permissions on var/backups.',500);
        $write=function(string $text)use($handle){
            $length=strlen($text);$offset=0;
            while($offset<$length){$bytes=fwrite($handle,substr($text,$offset));if(!$bytes)throw new Problem('Backup could not be written. Check free disk space.',500);$offset+=$bytes;}
        };
        try{
            $this->tx(function()use($write){
                $write("-- Ezy Filling System 1.4.0. MySQL/MariaDB backup. UTC ".stamp()."\n-- Import into a NEW, EMPTY database. Keep this file private.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
                $tables=$this->pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(\PDO::FETCH_NUM);
                $views=$this->pdo->query("SHOW FULL TABLES WHERE Table_type='VIEW'")->fetchAll(\PDO::FETCH_NUM);
                foreach($tables as $table){
                    $name=$table[0];$quoted='`'.str_replace('`','``',$name).'`';
                    $ddl=$this->pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(\PDO::FETCH_NUM);
                    $write($ddl[1].";\n");
                    $columns=[];
                    foreach($this->pdo->query('SHOW COLUMNS FROM '.$quoted)->fetchAll() as $column)if(stripos($column['Extra'],'GENERATED')===false)$columns[]=$column['Field'];
                    $columnSql='`'.implode('`,`',$columns).'`';
                    $rows=$this->pdo->query('SELECT '.$columnSql.' FROM '.$quoted);
                    while(($row=$rows->fetch(\PDO::FETCH_NUM))!==false){
                        $values=[];foreach($row as $v)$values[]=$v===null?'NULL':$this->pdo->quote((string)$v);
                        $write('INSERT INTO '.$quoted.' ('.$columnSql.') VALUES ('.implode(',',$values).");\n");
                    }
                }
                $database=(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
                foreach($views as $view){
                    $quoted='`'.str_replace('`','``',$view[0]).'`';
                    $ddl=$this->pdo->query('SHOW CREATE VIEW '.$quoted)->fetch(\PDO::FETCH_NUM);
                    $sql=preg_replace('/DEFINER\s*=\s*`[^`]*`@`[^`]*`\s*/i','',$ddl[1]);
                    $sql=preg_replace('/SQL SECURITY DEFINER/i','SQL SECURITY INVOKER',$sql);
                    $sql=str_replace('`'.str_replace('`','``',$database).'`.','',$sql);
                    $write($sql.";\n");
                }
                $write("SET FOREIGN_KEY_CHECKS=1;\n");
            });
            if(!fflush($handle))throw new Problem('Backup could not be flushed to disk.',500);
            fclose($handle);
            return ['size'=>filesize($destination),'sha256'=>hash_file('sha256',$destination)];
        }catch(\Throwable $e){fclose($handle);@unlink($destination);throw $e;}
    }
}
