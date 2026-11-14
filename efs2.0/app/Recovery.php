<?php
declare(strict_types=1);
namespace Petrol;

// Hold this handle for the whole request, before opening the database.
function recoveryGate(string $directory) {
    if (!is_dir($directory)) throw new Problem('Recovery storage is unavailable.',503);
    $handle=fopen($directory.'/recovery.lock','c+');
    if (!$handle) throw new Problem('Cannot open the recovery lock.',503);
    $exclusive=in_array($_GET['action']??'',['recovery_upload','recovery_restore','recovery_delete'],true);
    $until=microtime(true)+10;
    do {
        if (flock($handle,($exclusive?LOCK_EX:LOCK_SH)|LOCK_NB)) return $handle;
        usleep(100000);
    } while(microtime(true)<$until);
    fclose($handle);
    throw new Problem('Recovery is running. Please retry shortly.',503);
}

final class Recovery {
    private Db $db;
    private string $folder;
    private string $backups;
    public function __construct(Db $db,string $data) {
        $this->db=$db;
        $this->folder=rtrim($data,'/\\').'/recovery';
        $this->backups=rtrim($data,'/\\').'/backups';
        foreach([$this->folder,$this->backups] as $path) {
            if(!is_dir($path)&&!mkdir($path,0700,true)) throw new Problem('Cannot create recovery storage.',500);
        }
    }
    private function path(string $id,string $extension): string {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new Problem('Invalid recovery file.');
        return $this->folder.'/'.$id.$extension;
    }
    private function metadata(string $id): array {
        $path=$this->path($id,'.json');
        if(!is_file($path)) throw new Problem('Recovery file not found.',404);
        return json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    }
    private function writeMetadata(array $item): void {
        $path=$this->path($item['id'],'.json');
        $tmp=$path.'.tmp';
        $json=json_encode($item,JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json)||!rename($tmp,$path)) {
            throw new Problem('Cannot save recovery file details.',500);
        }
    }
    public function listing(): array {
        $items=[];
        foreach(glob($this->folder.'/*.json')?:[] as $path) {
            $item=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
            if(in_array($item['status']??'',['restoring','failed'],true)) {
                $applied=$this->db->setting('recovery_applied_'.$item['id'],'');
                $item['status']=$applied!==''?'restored':'failed';
                if($applied!=='')$item['restored_at']=$applied;
            }
            $items[]=$item;
        }
        usort($items,fn($a,$b)=>strcmp($b['uploaded_at'],$a['uploaded_at']));
        return $items;
    }
    // Tokenize the EFS export format. Uploaded SQL is NEVER executed.
    private function statements(string $sql): array {
        $result=[];$buffer='';$quote='';$length=strlen($sql);
        for($i=0;$i<$length;$i++) {
            $c=$sql[$i];$next=$sql[$i+1]??'';
            if($quote!=='') {
                $buffer.=$c;
                if($c==='\\'&&$quote==="'") {
                    if(++$i>=$length) throw new Problem('Incomplete backup string.');
                    $buffer.=$sql[$i];
                } elseif($c===$quote) {
                    if($next===$quote) {$buffer.=$next;$i++;} else $quote='';
                }
                continue;
            }
            if($c==='-'&&$next==='-'&&ctype_space($sql[$i+2]??' ')) {
                while($i<$length&&$sql[$i]!=="\n") $i++;
                $buffer.="\n";continue;
            }
            if($c==="'"||$c==='`') {$quote=$c;$buffer.=$c;continue;}
            if($c===';') {if(trim($buffer)!=='')$result[]=trim($buffer);$buffer='';}
            else $buffer.=$c;
        }
        if($quote!==''||trim($buffer)!=='') throw new Problem('Incomplete backup. Select an original EFS SQL backup.');
        return $result;
    }
    private function values(string $text): array {
        $values=[];$offset=0;$length=strlen($text);
        $map=['0'=>"\0",'n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'Z'=>"\x1a"];
        while($offset<$length) {
            while($offset<$length&&ctype_space($text[$offset]))$offset++;
            if(substr($text,$offset,4)==='NULL') {$values[]=null;$offset+=4;}
            elseif(($text[$offset]??'')==="'") {
                $offset++;$decoded='';$closed=false;
                while($offset<$length) {
                    $c=$text[$offset++];
                    if($c==='\\') {
                        if($offset===$length)throw new Problem('Incomplete backup value.');
                        $escape=$text[$offset++];$decoded.=$map[$escape]??$escape;
                    } elseif($c==="'") {
                        if(($text[$offset]??'')==="'") {$decoded.="'";$offset++;}
                        else {$closed=true;break;}
                    } else $decoded.=$c;
                }
                if(!$closed)throw new Problem('Incomplete backup value.');
                $values[]=$decoded;
            } else throw new Problem('Unsupported INSERT value. Only original EFS backups are accepted.');
            while($offset<$length&&ctype_space($text[$offset]))$offset++;
            if($offset===$length)break;
            if($text[$offset++]!==',')throw new Problem('Invalid backup value separator.');
            if(trim(substr($text,$offset))==='')throw new Problem('Incomplete INSERT values.');
        }
        return $values;
    }
    public function parse(string $sql): array {
        if(strlen($sql)>8*1024*1024||strpos($sql,'-- Ezy Filling System 1.4.0. MySQL/MariaDB backup.')!==0) {
            throw new Problem('Select an EFS Download backup SQL file, maximum 8 MB.');
        }
        $tables=[];$views=[];
        foreach($this->db->pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $entry) {
            if(!preg_match('/^[a-z_][a-z_0-9]*$/D',$entry[0]))throw new Problem('Unsupported database object.');
            if($entry[1]==='VIEW') {$views[$entry[0]]=true;continue;}
            $engine=$this->db->value('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$entry[0]]);
            if($engine!=='InnoDB')throw new Problem('Recovery requires InnoDB tables.');
            $columns=[];$allColumns=[];
            foreach($this->db->all('SHOW COLUMNS FROM `'.$entry[0].'`') as $column) {
                $allColumns[]=$column['Field'];
                if(stripos($column['Extra'],'GENERATED')===false)$columns[]=$column['Field'];
            }
            $tables[$entry[0]]=['columns'=>$columns,'all_columns'=>$allColumns,'rows'=>[]];
        }
        $seen=[];$seenViews=[];
        foreach($this->statements($sql) as $statement) {
            if(in_array($statement,['SET NAMES utf8mb4','SET FOREIGN_KEY_CHECKS=0','SET FOREIGN_KEY_CHECKS=1',"SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'"],true))continue;
            if(preg_match('/^CREATE TABLE `([a-z_][a-z_0-9]*)`\s*\(/',$statement,$m)) {
                $name=$m[1];
                if(!isset($tables[$name])||isset($seen[$name]))throw new Problem('Backup tables do not match this EFS version.');
                preg_match_all('/(?:^|\n)\s*`([a-z_][a-z_0-9]*)`\s/',$statement,$cols);
                if($name==='product_purchases' && $cols[1]===array_values(array_diff($tables[$name]['all_columns'],['sale_price_cents']))){
                    $legacyPurchaseColumns=true;
                    $tables[$name]['columns']=array_values(array_diff($tables[$name]['columns'],['sale_price_cents']));
                }elseif($cols[1]!==$tables[$name]['all_columns'])throw new Problem('Backup columns do not match this EFS version.');
                $seen[$name]=true;continue;
            }
            if(preg_match('/^CREATE\s+.*?\bVIEW\s+`([a-z_][a-z_0-9]*)`\s+AS\s/is',$statement,$m)) {
                if(!isset($views[$m[1]])||isset($seenViews[$m[1]]))throw new Problem('Unsupported backup view.');
                $seenViews[$m[1]]=true;continue;
            }
            if(!preg_match('/^INSERT INTO `([a-z_][a-z_0-9]*)` \(([^)]+)\) VALUES \((.*)\)$/sD',$statement,$m))throw new Problem('Unsupported SQL in backup. Nothing was restored.');
            $name=$m[1];
            if(!isset($seen[$name]))throw new Problem('Backup table is missing.');
            $columns='`'.implode('`,`',$tables[$name]['columns']).'`';
            if($m[2]!==$columns)throw new Problem('Backup INSERT columns do not match.');
            $values=$this->values($m[3]);
            if(count($values)!==count($tables[$name]['columns']))throw new Problem('Invalid backup row.');
            $tables[$name]['rows'][]=$values;
        }
        $records=function($table)use($tables){return array_map(fn($row)=>array_combine($tables[$table]['columns'],$row),$tables[$table]['rows']);};
        $versions=array_column($records('schema_migrations'),'version');
        $versions=array_map('intval',$versions);
        $version=$versions?max($versions):0;
        if(!in_array($version,[9,10,11],true)||array_diff(range(4,$version),$versions)||(!empty($legacyPurchaseColumns)&&$version!==9))throw new Problem('A complete schema 9, 10 or 11 backup is required. For older backups, import into a separate empty database, upgrade it with EFS, then export a new backup.');
        if($version<11){
            foreach(['ar_allocations','ar_invoices','ar_invoice_lines','ar_payment_links'] as $name){
                if(isset($seen[$name]))throw new Problem('Backup receivables tables do not match its schema version.');
                $seen[$name]=true;
            }
            $tables['schema_migrations']['rows'][]=[11,stamp()];
        }
        if(count($seen)!==count($tables)||count($seenViews)!==count($views))throw new Problem('Incomplete database backup.');
        if(!empty($legacyPurchaseColumns))$tables['schema_migrations']['rows'][]=[10,stamp()];
        $admins=array_filter($records('users'),fn($u)=>$u['role']==='admin'&&(int)$u['active']===1);
        if(!$admins)throw new Problem('Backup has no active administrator account.');
        $mutex=$records('efs_mutex');
        if(count($mutex)!==1||(int)$mutex[0]['id']!==1)throw new Problem('Backup database lock record is invalid.');
        return $tables;
    }
    public function upload(array $input): array {
        $encoded=$input['content']??null;
        if(!is_string($encoded)||strlen($encoded)>11200000)throw new Problem('Backup must be 8 MB or smaller.');
        $sql=base64_decode($encoded,true);
        if($sql===false)throw new Problem('Invalid upload.');
        $this->parse($sql);
        $name=basename(str_replace('\\','/',required($input,'name',200)));
        if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='sql')throw new Problem('Select a .sql backup file.');
        $item=['id'=>uid(),'name'=>$name,'size'=>strlen($sql),'sha256'=>hash('sha256',$sql),'uploaded_at'=>stamp(),'status'=>'ready'];
        $path=$this->path($item['id'],'.sql');
        if(file_put_contents($path,$sql,LOCK_EX)!==strlen($sql))throw new Problem('Could not store the uploaded backup.',500);
        $this->writeMetadata($item);
        return $item;
    }
    public function delete(string $id): array {
        $this->metadata($id);
        $sql=$this->path($id,'.sql');
        if(is_file($sql)&&!unlink($sql))throw new Problem('Could not delete the uploaded file.',500);
        if(!unlink($this->path($id,'.json')))throw new Problem('File removed, but its list entry could not be removed. Retry.',500);
        return ['deleted'=>true];
    }
    public function restore(array $input): array {
        if(($input['confirm']??'')!=='RESTORE')throw new Problem('Confirm recovery first.');
        $item=$this->metadata(required($input,'id',32));
        // Retry after a lost response must not overwrite newly entered data again.
        if($item['status']==='restored'||$this->db->setting('recovery_applied_'.$item['id'],'')!=='')throw new Problem('This file was already restored. Upload it again only if another recovery is intended.');
        $sql=file_get_contents($this->path($item['id'],'.sql'));
        if($sql===false||!hash_equals($item['sha256'],hash('sha256',$sql)))throw new Problem('Uploaded backup checksum failed.');
        $tables=$this->parse($sql);
        $safety='EFS-before-recovery-'.gmdate('Ymd-His').'-'.uid().'.sql';
        $fk=(int)$this->db->value('SELECT @@SESSION.FOREIGN_KEY_CHECKS');
        $mode=(string)$this->db->value('SELECT @@SESSION.sql_mode');
        $item['status']='restoring';unset($item['safety_backup']);
        $this->writeMetadata($item);
        try {
            $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $this->db->pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $this->db->tx(function()use($tables,$safety,&$item){
                $this->db->backup($this->backups.'/'.$safety);
                $item['safety_backup']=$safety;
                $this->writeMetadata($item);
                foreach($tables as $name=>$data)$this->db->run('DELETE FROM `'.$name.'`');
                foreach($tables as $name=>$data) {
                    $query='INSERT INTO `'.$name.'` (`'.implode('`,`',$data['columns']).'`) VALUES ('.implode(',',array_fill(0,count($data['columns']),'?')).')';
                    foreach($data['rows'] as $row)$this->db->run($query,$row);
                }
                $keys=$this->db->all('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
                foreach($keys as $key) {
                    $t=$key['TABLE_NAME'];$c=$key['COLUMN_NAME'];$rt=$key['REFERENCED_TABLE_NAME'];$rc=$key['REFERENCED_COLUMN_NAME'];
                    if($this->db->value("SELECT COUNT(*) FROM `$t` a LEFT JOIN `$rt` b ON a.`$c`=b.`$rc` WHERE a.`$c` IS NOT NULL AND b.`$rc` IS NULL"))throw new Problem('Backup contains broken record links. Original data was retained.');
                }
                $this->db->set('recovery_session_epoch',uid());
                $item['restored_at']=stamp();
                $this->db->set('recovery_applied_'.$item['id'],$item['restored_at']);
            });
        } catch(\Throwable $error) {
            $item['status']='failed';
            try{$this->writeMetadata($item);}catch(\Throwable $ignored){}
            error_log((string)$error);
            throw new Problem('Recovery failed or its result could not be confirmed. Refresh and check file status before retrying. Check the PHP log.'.(isset($item['safety_backup'])?' Safety backup: '.$safety:''),409);
        } finally {
            $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS='.$fk);
            $this->db->run('SET SESSION sql_mode=?',[$mode]);
        }
        $item['status']='restored';
        // The database marker also records success if a file write is interrupted.
        try{$this->writeMetadata($item);}catch(\Throwable $error){error_log((string)$error);}
        return ['restored'=>true,'file'=>$item,'sign_in_again'=>true];
    }
}
