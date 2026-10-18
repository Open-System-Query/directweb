<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"remote-backups","Remote Backups") extends Module {

protected bool $siteScoped=false;
protected function fields(): array {return ['endpoint'=>['label'=>'S3 endpoint','required'=>true],'bucket'=>['label'=>'Bucket','required'=>true],'region'=>['label'=>'Region','required'=>true,'value'=>'us-east-1'],'access'=>['label'=>'Access key','required'=>true],'secret'=>['label'=>'Secret key','kind'=>'passwordField','required'=>true],'encryption'=>['label'=>'Backup passphrase','kind'=>'passwordField','required'=>true],'retain'=>['label'=>'Copies to retain','kind'=>'numberField','value'=>30,'min'=>2,'max'=>365],'maximum'=>['label'=>'Backup limit (MB)','kind'=>'numberField','value'=>32,'min'=>1,'max'=>128],'automatic'=>['label'=>'Upload new backups','kind'=>'checkBox','value'=>false]];}
protected function validate(array $d,?array $old): array {if(!preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?(?:/[a-z0-9/_-]*)?$~iD',$d['endpoint'])||!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',$d['bucket'])||!preg_match('/^[a-z0-9-]+$/D',$d['region'])){throw new Problem('Check S3 settings.');}if(strlen($this->app->unseal($d['encryption']))<16){throw new Problem('Use a passphrase of at least 16 characters.');}return $d;}
protected function operations(): array {return ['upload'=>'Upload latest backup','list'=>'Remote copies','retrieve'=>'Retrieve backup'];}
protected function operationFields(string $op,array $r): array {return $op==='retrieve'?['key'=>['label'=>'Object key','required'=>true]]:[];}
private function request(array $r,string $method,string $key,string $body=''): array
{
    $d=$r['data'];$url=rtrim($d['endpoint'],'/').'/'.rawurlencode($d['bucket']).'/'.implode('/',array_map('rawurlencode',explode('/',$key)));$parts=parse_url($url);$host=$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');$date=gmdate('Ymd\THis\Z');$day=substr($date,0,8);$hash=hash('sha256',$body);$headers="host:$host\nx-amz-content-sha256:$hash\nx-amz-date:$date\n";$signed='host;x-amz-content-sha256;x-amz-date';$canonical=$method."\n".$parts['path']."\n\n".$headers."\n".$signed."\n".$hash;$scope=$day.'/'.$d['region'].'/s3/aws4_request';$string="AWS4-HMAC-SHA256\n$date\n$scope\n".hash('sha256',$canonical);$k=hash_hmac('sha256',$day,'AWS4'.$this->secret($r,'secret'),true);foreach([$d['region'],'s3','aws4_request'] as $part){$k=hash_hmac('sha256',$part,$k,true);}
    $result=Client::request($method,$url,['x-amz-date'=>$date,'x-amz-content-sha256'=>$hash,'Authorization'=>'AWS4-HMAC-SHA256 Credential='.$d['access'].'/'.$scope.', SignedHeaders='.$signed.', Signature='.hash_hmac('sha256',$string,$k)],$body,$d['maximum']*1048576+1048576);if($result['status']<200||$result['status']>=300){throw new Problem('S3 returned HTTP %s.',422,[(string)$result['status']]);}return $result;
}
protected function execute(string $op,array $u,array $r,array $i): array
{
    if($op==='list'){return ['copies'=>array_map(fn($v)=>$v['data'],$this->app->extensions->records('remote-copy',$r['id']))];}
    if($op==='upload'){
        $backup=$this->app->db->one('SELECT * FROM backups ORDER BY created_at DESC LIMIT 1')??throw new Problem('Create a backup first.');$this->app->backups->verify($backup['id']);if($backup['size']>$r['data']['maximum']*1048576){throw new Problem('Backup exceeds remote limit.');}
        $salt=random_bytes(16);$key=hash_pbkdf2('sha256',$this->secret($r,'encryption'),$salt,210000,32,true);$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$encrypted='DWB1'.$salt.$nonce.sodium_crypto_secretbox(file_get_contents($this->app->storage('backups/'.$backup['id'].'.zip')),$nonce,$key);sodium_memzero($key);$object='directweb/'.$r['id'].'/'.$backup['id'].'.dwb';$this->request($r,'PUT',$object,$encrypted);
        $existing=array_filter($this->app->extensions->records('remote-copy',$r['id']),fn($v)=>$v['data']['key']===$object);if(!$existing){$this->app->extensions->save('remote-copy',$backup['id'],$r['id'],['key'=>$object,'created_at'=>gmdate('c'),'hash'=>$backup['hash']]);}
        foreach(array_slice($this->app->extensions->records('remote-copy',$r['id']),(int)$r['data']['retain']) as $copy){$this->request($r,'DELETE',$copy['data']['key']);$this->app->db->execute('UPDATE extension_records SET deleted=1 WHERE id=?',[$copy['id']]);}return ['key'=>$object];
    }
    $object=(string)($i['key']??'');if(!preg_match('~^directweb/[a-f0-9]{32}/[a-f0-9]{32}\.dwb$~D',$object)){throw new Problem('Invalid object key.');}$raw=$this->request($r,'GET',$object)['body'];if(substr($raw,0,4)!=='DWB1'||strlen($raw)<60){throw new Problem('Invalid encrypted backup.');}$key=hash_pbkdf2('sha256',$this->secret($r,'encryption'),substr($raw,4,16),210000,32,true);$plain=sodium_crypto_secretbox_open(substr($raw,44),substr($raw,20,24),$key);sodium_memzero($key);if($plain===false){throw new Problem('Backup decryption failed.');}$id=Files::id();$file=$this->app->storage('backups/'.$id.'.zip');Files::write($file,$plain);
    try{\DirectWeb\Backups\Backups::inspect($file);$this->app->db->execute('INSERT INTO backups VALUES(?,?,?,?)',[$id,filesize($file),hash_file('sha256',$file),gmdate('c')]);}catch(\Throwable $e){Files::remove($file);throw $e;}return ['backup'=>$id,'retrieved'=>true];
}
protected function extend(Registry $registry): void {$registry->on('backup.created','remote-backups.upload',function($u,$data){foreach($this->app->extensions->records($this->id) as $r){if($r['data']['automatic']){$this->execute('upload',$u,$r,[]);}}return ['uploaded'=>true];});}

};$module->register($registry);
}
};
