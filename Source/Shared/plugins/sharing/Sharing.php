<?php
declare(strict_types=1);
namespace DirectWebSharing;
use DirectWeb\Core\{App,Files,Problem};
final class Sharing
{
    public function __construct(private App $app){}
    public function settings(): array
    {
        return json_decode($this->app->setting('sharing_settings','{}'),true)+['portal'=>true,'public_uploads'=>false,'password_required'=>false,'file_mb'=>128,'transfer_mb'=>512,'storage_mb'=>(int)ceil((int)$this->app->setting('sharing_quota','1073741824')/1048576),'files'=>50,'hours'=>48,'max_hours'=>168,'upload_rate'=>10,'notify'=>false];
    }
    public function configure(array $u,array $input): void
    {
        $this->app->auth->requireOwner($u);$s=[];foreach(['portal','public_uploads','password_required','notify'] as $key){$s[$key]=!empty($input[$key]);}
        foreach(['file_mb'=>[1,102400],'transfer_mb'=>[1,102400],'storage_mb'=>[1,1048576],'files'=>[1,200],'hours'=>[1,8760],'max_hours'=>[1,8760],'upload_rate'=>[1,1000]] as $key=>[$min,$max]){$v=filter_var($input[$key]??null,FILTER_VALIDATE_INT);if($v===false||$v<$min||$v>$max){throw new Problem('Check sharing limits.');}$s[$key]=$v;}
        if($s['file_mb']>$s['transfer_mb']||$s['transfer_mb']>$s['storage_mb']||$s['hours']>$s['max_hours']){throw new Problem('Check sharing limits.');}
        $this->app->set('sharing_settings',Files::json($s));$this->app->audit($u['id'],'sharing.configured');
    }
    public function actor(?array $u): string {if($u){return $u['id'];}$_SESSION['sharing_owner']??=Files::id();return 'guest:'.$_SESSION['sharing_owner'];}
    public function canUpload(?array $u): bool {return $u?in_array($u['role'],['owner','admin','editor'],true):$this->settings()['portal']&&$this->settings()['public_uploads'];}
    public function requireUpload(?array $u): void {if(!$this->canUpload($u)){throw new Problem('Sign in required.',403);}}
    public function usage(string $except=''): int
    {
        return (int)$this->app->db->one('SELECT COALESCE(SUM(size),0) AS size FROM share_files')['size']+(int)$this->app->db->one('SELECT COALESCE(SUM(size),0) AS size FROM share_uploads WHERE result IS NULL AND id<>?',[$except])['size'];
    }
    public function validate(array $files,array $options): array
    {
        $s=$this->settings();$hours=filter_var($options['hours']??$s['hours'],FILTER_VALIDATE_INT);$limit=filter_var($options['limit']??0,FILTER_VALIDATE_INT);$password=(string)($options['password']??'');$name=trim((string)($options['name']??''));
        if(!$files||count($files)>$s['files']||$hours===false||$hours<1||$hours>$s['max_hours']||$limit===false||$limit<0||$limit>100000||strlen($name)>120||strlen($password)>1024||($s['password_required']&&$password==='')||($password!==''&&strlen($password)<8)){throw new Problem('Check transfer settings.');}
        $size=0;foreach($files as $file){$filename=(string)($file['name']??'');$bytes=$file['size']??null;if($filename===''||strlen($filename)>200||preg_match('~[\x00-\x1f\x7f/\\\\]~',$filename)||!is_int($bytes)||$bytes<0||$bytes>$s['file_mb']*1048576){throw new Problem('File limit exceeded.');}$size+=$bytes;}
        if($size>$s['transfer_mb']*1048576){throw new Problem('Transfer limit exceeded.');}
        return ['name'=>$name!==''?$name:mb_strcut($files[0]['name'],0,120,'UTF-8'),'hours'=>$hours,'limit'=>$limit,'password'=>$password,'size'=>$size,'request'=>(string)($options['request']??'')];
    }
    private function allocateCode(): string
    {
        $alphabet='23456789abcdefghjkmnpqrstuvwxyz';for($attempt=0;$attempt<100;$attempt++){$code='';for($i=0;$i<5;$i++){$code.=$alphabet[random_int(0,strlen($alphabet)-1)];}if(in_array($code,['admin','oauth','files','login'],true)){continue;}$insert=$this->app->db->execute('INSERT OR IGNORE INTO share_codes VALUES(?,?)',[$code,gmdate('c')]);if($insert->rowCount()===1){return $code;}}throw new Problem('Try again later.',503);
    }
    public function list(?array $u): array
    {
        $rows=$u&&$u['role']==='owner'?$this->app->db->all('SELECT * FROM shares ORDER BY created_at DESC'):$this->app->db->all('SELECT * FROM shares WHERE owner=? ORDER BY created_at DESC',[$this->actor($u)]);
        foreach($rows as &$row){if(!$row['code']){$row['code']=$this->app->lock(fn()=>$this->app->db->transaction(function()use($row){$current=$this->app->db->one('SELECT code FROM shares WHERE id=?',[$row['id']]);if($current['code']){return $current['code'];}$code=$this->allocateCode();$this->app->db->execute('UPDATE shares SET code=? WHERE id=?',[$code,$row['id']]);return $code;}));}}unset($row);return $rows;
    }
    public function url(string $code): string {return ($this->app->config['secure']?'https':'http').'://'.$this->app->config['admin_host'].$this->app->url('/'.$code);}
    public function create(?array $u,array $uploads,array $options,string $uploadId=''): array
    {
        $request=$this->requireTransfer($u,(string)($options['request']??''));$this->prune();
        return $this->app->lock(function()use($u,$uploads,$options,$uploadId,$request){
            if($uploadId!==''){$pending=$this->app->db->one('SELECT * FROM share_uploads WHERE id=? AND session_hash=?',[$uploadId,hash('sha256',session_id())]);if(!$pending||$pending['owner']!==$this->actor($u)||$pending['expires_at']<time()){throw new Problem('Upload expired.',410);}if($pending['result']){return json_decode($pending['result'],true);}}
            $files=[];foreach($uploads as $upload){if(($upload['error']??1)!==UPLOAD_ERR_OK||!is_file($upload['tmp_name'])){throw new Problem('Upload failed.');}$files[]=['name'=>$upload['name'],'size'=>filesize($upload['tmp_name'])];}$o=$this->validate($files,$options);$s=$this->settings();
            if($this->usage($uploadId)+$o['size']>$s['storage_mb']*1048576){throw new Problem('Storage limit reached.');}
            $id=Files::id();$dir=$this->app->storage('plugins/sharing/'.$id);Files::mkdir($dir);$stored=[];
            try{foreach($uploads as $i=>$upload){$file=Files::id();if(!copy($upload['tmp_name'],$dir.'/'.$file)){throw new Problem('Save failed.');}$stored[]=['id'=>$file,'name'=>$files[$i]['name'],'size'=>$files[$i]['size'],'hash'=>hash_file('sha256',$dir.'/'.$file)];}
                return $this->app->db->transaction(function()use($u,$id,$o,$stored,$uploadId,$request){$code=$this->allocateCode();$this->app->db->execute('INSERT INTO shares(id,owner,name,token_hash,password,expires_at,max_downloads,created_at,code) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$request['owner']??$this->actor($u),$o['name'],hash('sha256',$code),$o['password']!==''?password_hash($o['password'],PASSWORD_ARGON2ID):null,time()+$o['hours']*3600,$o['limit'],gmdate('c'),$code]);foreach($stored as $f){$this->app->db->execute('INSERT INTO share_files VALUES(?,?,?,?,?)',[$f['id'],$id,$f['name'],$f['size'],$f['hash']]);}$result=['id'=>$id,'code'=>$code,'url'=>$this->url($code)];if($uploadId!==''){$this->app->db->execute('UPDATE share_uploads SET result=?,size=0,expires_at=? WHERE id=?',[Files::json($result),time()+3600,$uploadId]);}$this->app->audit($this->actor($u),'share.created',$id);return $result;});
            }catch(\Throwable $e){Files::remove($dir);throw $e;}
        });
    }
    public function find(string $token): array
    {
        if(!preg_match('/^(?:[a-z2-9]{5}|[a-f0-9]{64})$/D',$token)){throw new Problem('Link unavailable.',404);}
        $s=strlen($token)===5?$this->app->db->one('SELECT * FROM shares WHERE code=?',[$token]):$this->app->db->one('SELECT * FROM shares WHERE token_hash=?',[hash('sha256',$token)]);
        if(!$s||$s['revoked']||$s['expires_at']<=time()||($s['max_downloads']>0&&$s['downloads']>=$s['max_downloads'])){throw new Problem('Link unavailable.',404);}return $s;
    }
    public function guard(string $ip): void {$this->app->auth->limit('share-lookup:'.hash('sha256',$ip),120,60);}
    public function unlock(string $token,string $password,string $ip): void
    {
        $this->app->auth->limit('share-password:'.hash('sha256',$ip),12,900);$s=$this->find($token);if($s['password']&&!password_verify($password,$s['password'])){throw new Problem('Invalid password.',403);}$_SESSION['shares'][$s['id']]=time()+3600;
    }
    public function allowed(array $s): bool {return !$s['password']||($_SESSION['shares'][$s['id']]??0)>time();}
    public function files(string $id): array {return $this->app->db->all('SELECT id,name,size FROM share_files WHERE share_id=?',[$id]);}
    public function download(string $token,string $file): array
    {
        return $this->app->lock(fn()=>$this->app->db->transaction(function()use($token,$file){$s=$this->find($token);if(!$this->allowed($s)){throw new Problem('Password required.',403);}$f=$this->app->db->one('SELECT * FROM share_files WHERE id=? AND share_id=?',[$file,$s['id']]);if(!$f){throw new Problem('File not found.',404);}$path=$this->app->storage('plugins/sharing/'.$s['id'].'/'.$f['id']);if(!is_file($path)){throw new Problem('File unavailable.',404);}$this->app->db->execute('UPDATE shares SET downloads=downloads+1 WHERE id=?',[$s['id']]);$this->notify($s);return $f+['path'=>$path];}));
    }
    public function revoke(?array $u,string $id,bool $remove=false): void
    {
        if($u&&!in_array($u['role'],['owner','admin','editor'],true)){throw new Problem('Access denied.',403);}
        $this->app->lock(function()use($u,$id,$remove){$s=$this->app->db->one('SELECT * FROM shares WHERE id=?',[$id]);if(!$s||(($u['role']??'')!=='owner'&&$s['owner']!==$this->actor($u))){throw new Problem('Access denied.',403);}$this->app->db->execute('UPDATE shares SET revoked=1 WHERE id=?',[$id]);if($remove){Files::remove($this->app->storage('plugins/sharing/'.$id));$this->app->db->execute('DELETE FROM shares WHERE id=?',[$id]);}$this->app->audit($this->actor($u),$remove?'share.deleted':'share.revoked',$id);});
    }
    public function owned(?array $u,string $id): array
    {
        $s=$this->app->db->one('SELECT * FROM shares WHERE id=?',[$id]);
        if(!$s||(($u['role']??'')!=='owner'&&$s['owner']!==$this->actor($u))||($u&&$u['role']==='reader')){throw new Problem('Access denied.',403);}return $s;
    }
    public function extend(?array $u,string $id,int $hours): void
    {
        $s=$this->owned($u,$id);if($hours<1||$hours>$this->settings()['max_hours']||$s['revoked']){throw new Problem('Check expiry.');}$this->app->db->execute('UPDATE shares SET expires_at=? WHERE id=?',[time()+$hours*3600,$id]);$this->app->audit($this->actor($u),'share.extended',$id);
    }
    public function zip(string $token): array
    {
        return $this->app->lock(function()use($token){$s=$this->find($token);if(!$this->allowed($s)){throw new Problem('Password required.',403);}$files=$this->files($s['id']);$path=$this->app->storage('staging/share-'.Files::id().'.zip');$z=new \ZipArchive();if($z->open($path,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true){throw new Problem('Archive failed.');}$names=[];
            try{foreach($files as $f){$name=$f['name'];$n=1;while(isset($names[strtolower($name)])){$name=$n++.'-'.$f['name'];}$names[strtolower($name)]=true;$z->addFile($this->app->storage('plugins/sharing/'.$s['id'].'/'.$f['id']),$name);}if(!$z->close()){throw new Problem('Archive failed.');}$this->app->db->execute('UPDATE shares SET downloads=downloads+1 WHERE id=?',[$s['id']]);$this->notify($s);return ['path'=>$path,'name'=>$s['name'].'.zip'];}catch(\Throwable $e){Files::remove($path);throw $e;}
        });
    }
    private function notify(array $share): void {if($this->settings()['notify']){$this->app->extensions->emit('sharing.downloaded',$share['owner'],['share'=>$share['id'],'name'=>$share['name']]);}}
    public function request(string $code): array
    {
        $r=$this->app->db->one('SELECT * FROM share_requests WHERE code=? AND enabled=1 AND expires_at>?',[$code,time()])??throw new Problem('Link unavailable.',404);return $r;
    }
    public function requireTransfer(?array $u,string $request=''): ?array {if($request!==''){return $this->request($request);}$this->requireUpload($u);return null;}
    public function requestCreate(array $u,string $name,int $hours): array
    {
        $this->requireUpload($u);if(trim($name)===''||strlen($name)>120||$hours<1||$hours>$this->settings()['max_hours']){throw new Problem('Check request settings.');}
        return $this->app->db->transaction(function()use($u,$name,$hours){$id=Files::id();$code=$this->allocateCode();$this->app->db->execute('INSERT INTO share_requests VALUES(?,?,?,?,?,1)',[$id,$u['id'],trim($name),$code,time()+$hours*3600]);return ['id'=>$id,'code'=>$code];});
    }
    public function prune(): int
    {
        return $this->app->lock(function(){$count=0;foreach($this->app->db->all('SELECT * FROM shares WHERE expires_at<?',[time()]) as $s){Files::remove($this->app->storage('plugins/sharing/'.$s['id']));$this->app->db->execute('DELETE FROM shares WHERE id=?',[$s['id']]);$count++;}foreach($this->app->db->all('SELECT id FROM share_uploads WHERE expires_at<?',[time()]) as $upload){Files::remove($this->app->storage('plugins/sharing/uploads/'.$upload['id']));$this->app->db->execute('DELETE FROM share_uploads WHERE id=?',[$upload['id']]);}return $count;});
    }
}
