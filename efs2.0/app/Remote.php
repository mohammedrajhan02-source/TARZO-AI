<?php
declare(strict_types=1);
namespace Petrol;

function remote_post(string $url,array $body,string $token=''): array {
    $parts=parse_url($url);$local=in_array($parts['host']??'',['127.0.0.1','localhost'],true);
    if(!$parts||(($parts['scheme']??'')!=='https'&&!($local&&($parts['scheme']??'')==='http'))||isset($parts['user'])||isset($parts['pass']))throw new Problem('Remote service requires a valid HTTPS URL.');
    $headers="Content-Type: application/json\r\nAccept: application/json\r\n";if($token!=='')$headers.='Authorization: Bearer '.$token."\r\n";
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>$headers,'content'=>json_encode($body,JSON_THROW_ON_ERROR),'timeout'=>8,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $raw=@file_get_contents($url,false,$ctx,0,2097152);if($raw===false)throw new Problem('Service unavailable. Local data is safe; retry when connected.',503);
    $status=0;foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+ (\d+)/',$h,$m))$status=(int)$m[1];
    $json=json_decode($raw,true);if(!is_array($json))throw new Problem('Remote service returned an invalid response.',502);
    if($status<200||$status>=300)throw new Problem((string)($json['error']??'Remote request failed.'),502);return $json;
}

final class Licensing {
    private Db $db; private array $config;
    public function __construct(Db $db,array $config){$this->db=$db;$this->config=$config;}
    public function state(): array {
        if(!$this->config['license_required'])return ['state'=>'source','label'=>'Source edition','can_sell'=>true,'message'=>'License enforcement is not enabled in this owner/development build.'];
        $r=$this->db->one('SELECT * FROM license_cache WHERE id=1');if(!$r)return ['state'=>'activation_required','label'=>'Activation required','can_sell'=>false,'message'=>'Activate a device-bound license online.'];
        try{$claims=$this->verify($r['signed_payload'],$r['signature']);}catch(\Throwable $ignored){return ['state'=>'invalid','label'=>'Invalid license','can_sell'=>false,'message'=>'The license signature could not be verified.'];}
        $now=time();$rollback=$now<(int)$r['last_seen_at']-300;$allowed=$claims['status']==='active'&&!$rollback&&$now<=(int)$claims['lease_until']&&$now<=(int)$claims['expires_at'];
        if($now>(int)$r['last_seen_at'])$this->db->run('UPDATE license_cache SET last_seen_at=? WHERE id=1',[$now]);
        $state=$rollback?'clock_check':($claims['status']!=='active'?$claims['status']:($now>(int)$claims['expires_at']?'expired':($now>(int)$claims['lease_until']?'verification_due':'active')));
        return ['state'=>$state,'label'=>ucwords(str_replace('_',' ',$state)),'can_sell'=>$allowed,'expires_at'=>(int)$claims['expires_at'],'offline_until'=>(int)$claims['lease_until'],'last_verified'=>(int)$r['received_at'],'check_due'=>$now-(int)$r['received_at']>=120,'message'=>$allowed?'Signed license verified.':'New business entries are locked. Reports, backups and shift closure remain available.'];
    }
    private function verify(string $encoded,string $signature): array {
        $payload=base64_decode($encoded,true);$sig=base64_decode($signature,true);$pk=base64_decode((string)$this->config['license_public_key'],true);
        if($payload===false||$sig===false||$pk===false||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||strlen($pk)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!sodium_crypto_sign_verify_detached($sig,$payload,$pk))throw new Problem('Invalid license signature.',403);
        $c=json_decode($payload,true,16,JSON_THROW_ON_ERROR);
        foreach(['license_id','device_id','status','expires_at','lease_until','issued_at'] as $k)if(!array_key_exists($k,$c))throw new Problem('Incomplete signed license.',403);
        if($c['device_id']!==$this->db->setting('device_id'))throw new Problem('License belongs to another installation.',403);
        if((int)$c['lease_until']>(int)$c['expires_at']||(int)$c['issued_at']>time()+300)throw new Problem('Invalid license dates.',403);return $c;
    }
    public function activate(string $key,array $user): array {
        require_role($user,['admin']);return $this->check($key);
    }
    public function refresh(): array {$r=$this->db->one('SELECT license_key FROM license_cache WHERE id=1');if(!$r)throw new Problem('Activate a license first.');return $this->check($r['license_key']);}
    private function check(string $key): array {
        if(!$this->config['license_url']||!$this->config['license_public_key'])throw new Problem('The owner must configure the license URL and public key.');
        $r=remote_post($this->config['license_url'],['action'=>'verify','license_key'=>$key,'device_id'=>$this->db->setting('device_id'),'station_name'=>$this->db->setting('station_name')]);
        if(!is_string($r['payload']??null)||!is_string($r['signature']??null))throw new Problem('Invalid license server response.');$this->verify($r['payload'],$r['signature']);
        $this->db->run('INSERT INTO license_cache(id,license_key,signed_payload,signature,received_at,last_seen_at) VALUES(1,?,?,?,?,?) ON DUPLICATE KEY UPDATE license_key=VALUES(license_key),signed_payload=VALUES(signed_payload),signature=VALUES(signature),received_at=VALUES(received_at),last_seen_at=VALUES(last_seen_at)',[$key,$r['payload'],$r['signature'],time(),time()]);return $this->state();
    }
    public function enforce(): void {$state=$this->state();if($this->config['license_required']&&$this->db->one('SELECT id FROM license_cache WHERE id=1')&&time()-(int)$this->db->setting('license_last_attempt','0')>=120){$this->db->set('license_last_attempt',(string)time());try{$state=$this->refresh();}catch(\Throwable $ignored){/* A valid signed offline lease remains usable during outages. */}}if(!$state['can_sell'])throw new Problem('License verification or renewal is required for new entries.',423);}
}

final class Sync {
    private Db $db; private Station $station; private array $config;
    public function __construct(Db $db,Station $station,array $config){$this->db=$db;$this->station=$station;$this->config=$config;}
    public function send(array $user): array {
        require_role($user,['admin','manager']);if(!$this->config['cloud_url']||!$this->config['cloud_token']||!$this->config['cloud_tenant'])throw new Problem('Cloud URL, tenant and device token are not configured. Offline operation is available.');
        $events=$this->db->all('SELECT * FROM sync_outbox WHERE sent_at IS NULL ORDER BY seq LIMIT 200');
        // A read transaction holds a consistent snapshot while assembling related tables.
        $this->db->pdo->exec('BEGIN');try{$snapshot=$this->station->snapshot(['id'=>$user['id'],'name'=>$user['name'],'username'=>$user['username'],'role'=>'manager']);$snapshotSeq=(int)$this->db->value('SELECT COALESCE(MAX(seq),0) FROM sync_outbox');$this->db->pdo->exec('COMMIT');}catch(\Throwable $e){$this->db->pdo->exec('ROLLBACK');throw $e;}
        unset($snapshot['user'],$snapshot['users'],$snapshot['audit']);
        try{$response=remote_post($this->config['cloud_url'],['action'=>'ingest','tenant'=>$this->config['cloud_tenant'],'device_id'=>$this->db->setting('device_id'),'events'=>$events,'snapshot_seq'=>$snapshotSeq,'snapshot'=>$snapshot],$this->config['cloud_token']);$acks=$response['acknowledged']??[];if(!is_array($acks))throw new Problem('Invalid cloud acknowledgement.');$allowed=array_column($events,'event_id');$this->db->tx(function()use($acks,$allowed){foreach($acks as $id)if(is_string($id)&&in_array($id,$allowed,true))$this->db->run('UPDATE sync_outbox SET sent_at=?,last_error=NULL WHERE event_id=?',[stamp(),$id]);$this->db->set('last_sync',stamp());$this->db->set('sync_error','');});return ['acknowledged'=>count(array_intersect($allowed,$acks)),'remaining'=>(int)$this->db->value('SELECT COUNT(*) FROM sync_outbox WHERE sent_at IS NULL'),'last_sync'=>$this->db->setting('last_sync')];}
        catch(\Throwable $e){$this->db->set('sync_error',substr($e->getMessage(),0,300));foreach($events as $event)$this->db->run('UPDATE sync_outbox SET attempts=attempts+1,last_error=? WHERE event_id=?',[substr($e->getMessage(),0,300),$event['event_id']]);throw $e;}
    }
}
