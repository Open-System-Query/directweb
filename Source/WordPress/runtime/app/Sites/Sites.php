<?php
declare(strict_types=1);
namespace DirectWeb\Sites;
use DirectWeb\Core\{App,Files,Problem};
final class Sites
{
    public const TYPES=['html'=>'text/html; charset=utf-8','htm'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','mjs'=>'text/javascript; charset=utf-8','json'=>'application/json','txt'=>'text/plain; charset=utf-8','xml'=>'application/xml','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','pdf'=>'application/pdf','mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav','wasm'=>'application/wasm','webmanifest'=>'application/manifest+json','map'=>'application/json','vtt'=>'text/vtt'];
    public const RUNTIMES=['static','server'];
    public function __construct(private App $app) {}
    public function list(array $u): array
    {
        $rows=$this->app->db->all('SELECT s.*,r.name AS release_name,r.size,r.files FROM sites s LEFT JOIN releases r ON r.id=s.active_release ORDER BY s.name');
        return array_values(array_filter($rows,function($s)use($u){try{$this->app->auth->access($u,$s['id']);return true;}catch(Problem){return false;}}));
    }
    public function get(string $id): array { $s=$this->app->db->one('SELECT * FROM sites WHERE id=?',[$id]); if(!$s) { throw new Problem('Website not found.',404); } return $s; }
    public function server(array $site): bool { return ($site['runtime']??'static')==='server'; }
    public function create(array $u,string $name,string $host,string $runtime='static'): array
    {
        $this->app->auth->requireOwner($u); $host=Files::host($host); if(($host===strtolower($this->app->config['admin_host'])&&!$this->adminHostAllowed()) || trim($name)==='' || strlen($name)>100) { throw new Problem('Check name and domain.'); }
        if(!in_array($runtime,self::RUNTIMES,true)){throw new Problem('Invalid website type.');}if($runtime==='server'){$this->supportsServer();$this->executable($u,['runtime'=>'server']);}
        if($this->app->db->one('SELECT id FROM sites WHERE host=?',[$host])) { throw new Problem('Domain already exists.'); }
        $id=Files::id();$this->app->db->execute('INSERT INTO sites(id,name,host,created_at,auto_publish,runtime) VALUES(?,?,?,?,0,?)',[$id,trim($name),$host,gmdate('c'),$runtime]);$this->app->audit($u['id'],'site.created',$id,['runtime'=>$runtime]);return $this->get($id);
    }
    public function releases(array $u,string $site): array { $this->app->auth->access($u,$site);$active=$this->get($site)['active_release'];return array_map(fn($r)=>$r+['status'=>$r['id']===$active?'live':($r['published']?'retained':'draft')],$this->app->db->all('SELECT * FROM releases WHERE site_id=? ORDER BY created_at DESC,rowid DESC',[$site])); }
    public function release(string $site,string $id): array { $r=$this->app->db->one('SELECT * FROM releases WHERE site_id=? AND id=?',[$site,$id]);if(!$r){throw new Problem('Release not found.',404);}return $r; }
    public function dir(string $site,string $release): string { if(!preg_match('/^[a-f0-9]{32}$/D',$site) || !preg_match('/^[a-f0-9]{32}$/D',$release)){throw new Problem('Invalid release.');} return $this->app->storage('sites/'.$site.'/releases/'.$release); }
    public function begin(array $u,string $site,?string $base): array
    {
        $this->app->auth->access($u,$site,'write');
        return $this->app->lock(function()use($u,$site,$base){
            $s=$this->get($site);$this->executable($u,$s); if($base===null&&$s['active_release']!==null){throw new Problem('Release changed. Reopen the page.',409);}
            $pending=(int)$this->app->db->one("SELECT COUNT(*) AS n FROM changes WHERE actor=? AND status='open'",[$u['id']])['n'];if($pending>=10){throw new Problem('Too many open changes.',429);}$id=Files::id();$folder=$this->app->storage('staging/'.$id.'/files'); Files::mkdir($folder);
            if($base){$this->release($site,$base);Files::copy($this->dir($site,$base).'/files',$folder);}
            $this->app->db->execute('INSERT INTO changes(id,site_id,base_id,base_revision,actor,created_at) VALUES(?,?,?,?,?,?)',[$id,$site,$base,$s['revision'],$u['id'],gmdate('c')]); return ['change_id'=>$id,'base_release'=>$base,'revision'=>$s['revision']];
        });
    }
    public function change(array $u,string $id,bool $open=true): array
    {
        $c=$this->app->db->one('SELECT * FROM changes WHERE id=?',[$id]);if(!$c || $c['actor']!==$u['id']){throw new Problem('Change not found.',404);} $this->app->auth->access($u,$c['site_id'],'write');$this->executable($u,$this->get($c['site_id']));if($open && $c['status']!=='open'){throw new Problem('Change is closed.',409);}return $c;
    }
    public function apply(array $u,string $id,array $updates,array $deletes=[]): array
    {
        if(count($updates)+count($deletes)>500){throw new Problem('Too many files.');}
        return $this->app->lock(function()use($u,$id,$updates,$deletes){
            $c=$this->change($u,$id);$served=$this->server($this->get($c['site_id']));$base=$this->app->storage('staging/'.$id.'/files');
            foreach($updates as $file=>$content){Files::path((string)$file,$served);$this->type((string)$file,$served);if(!is_string($content)||strlen($content)>2097152){throw new Problem('File too large.');}}
            foreach($deletes as $file){Files::path($file,$served);}
            foreach($updates as $file=>$content){Files::write($base.'/'.$file,$content);}
            foreach($deletes as $file){if(is_file($base.'/'.$file)){unlink($base.'/'.$file);}}
            $manifest=$this->manifest($base,false,$served);return ['change_id'=>$id,'files'=>$manifest['count'],'size'=>$manifest['size']];
        });
    }
    public function type(string $file,bool $served=false): string { $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));if(!isset(self::TYPES[$ext])){if($served){return 'application/octet-stream';}throw new Problem('File type not allowed: %s',422,[$file]);}return self::TYPES[$ext]; }
    public function manifest(string $dir,bool $entry=true,bool $served=false): array
    {
        $files=[];$total=0;$names=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)) as $f){
            if($f->isLink()||!$f->isFile()){throw new Problem('Invalid file.');}
            $name=str_replace('\\','/',substr($f->getPathname(),strlen($dir)+1));Files::path($name,$served);$type=$this->type($name,$served);
            if(isset($names[strtolower($name)])){throw new Problem('Duplicate filename.');}$names[strtolower($name)]=true;
            $size=$f->getSize();$total+=$size;if($size>$this->app->config['max_upload']||$total>$this->app->config['max_site']||count($files)>=$this->app->config['max_files']){throw new Problem('Storage limit reached.');}
            $files[$name]=['size'=>$size,'hash'=>hash_file('sha256',$f->getPathname()),'type'=>$type];
        }
        ksort($files);$start=null;foreach($served?['index.php','index.html','index.htm']:['index.html','index.htm'] as $candidate){if(isset($files[$candidate])){$start=$candidate;break;}}
        if($entry&&!$start&&!$served){throw new Problem('index.html missing.');}
        return ['start'=>$start,'files'=>$files,'size'=>$total,'count'=>count($files),'hash'=>hash('sha256',Files::json($files))];
    }
    public function validate(array $u,string $id): array
    {
        $c=$this->change($u,$id);$m=$this->manifest($this->app->storage('staging/'.$id.'/files'),true,$this->server($this->get($c['site_id'])));$old=$c['base_id']?$this->readManifest($c['site_id'],$c['base_id']):['files'=>[]];$diff=[];
        foreach($m['files'] as $p=>$v){if(!isset($old['files'][$p])){$diff[]=['path'=>$p,'change'=>'added'];}elseif($v['hash']!==$old['files'][$p]['hash']){$diff[]=['path'=>$p,'change'=>'changed'];}}
        foreach($old['files'] as $p=>$v){if(!isset($m['files'][$p])){$diff[]=['path'=>$p,'change'=>'deleted'];}}
        return ['valid'=>true,'files'=>$m['count'],'size'=>$m['size'],'diff'=>$diff];
    }
    public function commit(array $u,string $id,string $name,string $request,bool $publish=false): array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$request)){throw new Problem('Invalid request ID.');}
        return $this->app->lock(function()use($u,$id,$name,$request,$publish){
            $c=$this->change($u,$id,false);$fp=hash('sha256',Files::json([$id,$name,$publish]));$key=hash('sha256',$u['id'].':'.$request);$op=$this->app->db->one('SELECT * FROM operations WHERE id=?',[$key]);
            if($op){if($op['actor']!==$u['id']||!hash_equals($op['fingerprint'],$fp)){throw new Problem('Request conflict.',409);}return json_decode($op['result'],true);}
            if($c['status']==='committed'){return json_decode($c['result'],true);}if($c['status']!=='open'){throw new Problem('Change is closed.',409);}
            $s=$this->get($c['site_id']);if($s['revision']!==$c['base_revision']){throw new Problem('Release changed. Reopen the page.',409);}
            $work=$this->app->storage('staging/'.$id);$m=$this->manifest($work.'/files',true,$this->server($s));
            $old=$c['base_id']?$this->release($s['id'],$c['base_id']):null;
            if($old && hash_equals($old['hash'],$m['hash'])){$result=['release_id'=>$old['id'],'live'=>$s['active_release']===$old['id'],'unchanged'=>true];}
            else{
                $rid=Files::id();$target=$this->dir($s['id'],$rid);Files::mkdir(dirname($target));Files::write($work.'/manifest.json',Files::json($m+['change_id'=>$id]));
                if(!rename($work,$target)){throw new Problem('Publication failed.',503);}
                $result=['release_id'=>$rid,'live'=>$publish,'unchanged'=>false];
            }
            $result+=['site_id'=>$s['id'],'revision'=>$s['revision']+($result['unchanged']?0:1),'status'=>$result['live']?'live':(($old&&$result['unchanged']&&$old['published'])?'retained':'draft')];
            try{
                $this->app->db->transaction(function()use($c,$s,$m,$old,$name,$u,$result,$key,$fp,$id){
                    if(!$result['unchanged']){
                        if($result['live']&&$this->server($s)){$this->deploy($s,$result['release_id']);}
                        $this->app->db->execute('INSERT INTO releases(id,site_id,parent_id,name,hash,size,files,actor,created_at,published) VALUES(?,?,?,?,?,?,?,?,?,?)',[$result['release_id'],$s['id'],$c['base_id'],trim($name)?:'Release '.gmdate('Y-m-d H:i'),$m['hash'],$m['size'],$m['count'],$u['id'],gmdate('c'),(int)$result['live']]);
                        if($result['live']){$this->app->db->execute('UPDATE sites SET active_release=?,revision=revision+1 WHERE id=?',[$result['release_id'],$s['id']]);}else{$this->app->db->execute('UPDATE sites SET revision=revision+1 WHERE id=?',[$s['id']]);}
                    }
                    $this->app->db->execute("UPDATE changes SET status='committed',result=? WHERE id=?",[Files::json($result),$id]);
                    $this->app->db->execute('INSERT INTO operations VALUES(?,?,?,?,?)',[$key,$u['id'],$fp,Files::json($result),gmdate('c')]);$this->app->audit($u['id'],'release.committed',$s['id'],$result);
                });
            }catch(\Throwable $e){if(isset($target)&&is_dir($target)){rename($target,$work);}throw $e;}
            if($result['live']&&!$result['unchanged']&&$this->server($s)){$this->routes();}
            if($result['unchanged']){Files::remove($work);}else{$this->app->extensions->emit($result['live']?'release.published':'release.created',$u['id'],['site'=>$s['id'],'release'=>$result['release_id']]);}return $result;
        });
    }
    public function import(array $u,string $site,string $zip,string $name,?string $base,bool $publish=false): array
    {
        $this->app->auth->access($u,$site,'write');if(!is_file($zip)||filesize($zip)>$this->app->config['max_upload']){throw new Problem('Upload too large.');}
        $served=$this->server($this->get($site));
        $archive=new \ZipArchive();if($archive->open($zip,\ZipArchive::RDONLY)!==true){throw new Problem('Invalid ZIP.');}
        $c=$this->begin($u,$site,$base);$dir=$this->app->storage('staging/'.$c['change_id'].'/files');
        try{
            Files::remove($dir);Files::mkdir($dir);$entries=[];$total=0;
            if($archive->numFiles>$this->app->config['max_files']){throw new Problem('Too many files.');}
            for($i=0;$i<$archive->numFiles;$i++){
                $stat=$archive->statIndex($i);$p=$stat['name'];if(str_starts_with($p,'__MACOSX/')||basename($p)==='.DS_Store'){continue;}
                Files::path(rtrim($p,'/'),$served);$os=0;$attr=0;$archive->getExternalAttributesIndex($i,$os,$attr);$kind=($attr>>16)&0170000;
                if($kind!==0&&$kind!==0100000&&$kind!==0040000){throw new Problem('Links not allowed.');}if(str_ends_with($p,'/')){continue;}
                $this->type($p,$served);$total+=$stat['size'];if($stat['size']<0||$stat['size']>$this->app->config['max_upload']||$total>$this->app->config['max_site']||!empty($stat['encryption_method'])){throw new Problem('ZIP exceeds the limit.');}
                if(isset($entries[strtolower($p)])){throw new Problem('Duplicate filename.');}$entries[strtolower($p)]=['path'=>$p,'stat'=>$stat];
            }
            $prefix='';$paths=array_column($entries,'path');if(!array_intersect($served?['index.php','index.html','index.htm']:['index.html','index.htm'],$paths)){$tops=array_unique(array_map(fn($p)=>explode('/',$p)[0],$paths));if(count($tops)===1){$prefix=reset($tops).'/';}}
            foreach($entries as $e){$p=$prefix?substr($e['path'],strlen($prefix)):$e['path'];Files::path($p,$served);Files::mkdir(dirname($dir.'/'.$p));$in=$archive->getStream($e['path']);$out=fopen($dir.'/'.$p,'xb');if(!$in||!$out){throw new Problem('Extraction failed.');}try{$n=stream_copy_to_stream($in,$out,$e['stat']['size']+1);}finally{fclose($in);fclose($out);}if($n!==$e['stat']['size']||strtolower(hash_file('crc32b',$dir.'/'.$p))!==sprintf('%08x',$e['stat']['crc'])){throw new Problem('ZIP corrupted.');}}
            return $this->commit($u,$c['change_id'],$name,Files::id(),$publish);
        }catch(\Throwable $e){$this->abort($u,$c['change_id']);throw $e;}finally{$archive->close();}
    }
    public function abort(array $u,string $id): void { $this->app->lock(function()use($u,$id){$c=$this->change($u,$id,false);if($c['status']!=='open'){return;}$this->app->db->execute("UPDATE changes SET status='aborted' WHERE id=?",[$id]);Files::remove($this->app->storage('staging/'.$id));}); }
    public function recover(): int
    {
        return $this->app->lock(function(){
            $count=0;foreach(glob($this->app->storage('sites/*/releases/*'),GLOB_ONLYDIR) as $dir){if(is_link($dir)||$this->app->db->one('SELECT id FROM releases WHERE id=?',[basename($dir)])){continue;}$manifest=is_file($dir.'/manifest.json')?json_decode(file_get_contents($dir.'/manifest.json'),true):null;$id=$manifest['change_id']??'';$c=$this->app->db->one("SELECT * FROM changes WHERE id=? AND status='open'",[$id]);if($c&&!is_dir($this->app->storage('staging/'.$id))){if(rename($dir,$this->app->storage('staging/'.$id))){$count++;}}elseif(filemtime($dir)<time()-86400){Files::remove($dir);}}
            foreach($this->app->db->all("SELECT * FROM changes WHERE status='open' AND created_at<?",[gmdate('c',time()-86400)]) as $c){$this->app->db->execute("UPDATE changes SET status='aborted' WHERE id=?",[$c['id']]);Files::remove($this->app->storage('staging/'.$c['id']));}return $count;
        });
    }
    public function readManifest(string $site,string $release): array { $this->release($site,$release);$data=file_get_contents($this->dir($site,$release).'/manifest.json');return json_decode($data,true,512,JSON_THROW_ON_ERROR); }
    public function read(array $u,string $site,string $release,string $file): string
    {
        $this->app->auth->access($u,$site);Files::path($file,$this->server($this->get($site)));$m=$this->readManifest($site,$release);if(!isset($m['files'][$file])||$m['files'][$file]['size']>2097152){throw new Problem('File unavailable.');}return file_get_contents($this->dir($site,$release).'/files/'.$file);
    }
    public function restore(array $u,string $site,string $release,int $revision): array
    {
        return $this->publish($u,$site,$release,$revision,true);
    }
    public function publish(array $u,string $site,string $release,int $revision,bool $restored=false): array
    {
        $this->app->auth->access($u,$site,'write');
        return $this->app->lock(function()use($u,$site,$release,$revision,$restored){
            $s=$this->get($site);$this->executable($u,$s);if($s['revision']!==$revision){throw new Problem('Release changed.',409);}
            $r=$this->release($site,$release);$m=$this->manifest($this->dir($site,$release).'/files',true,$this->server($s));if(!hash_equals($r['hash'],$m['hash'])){throw new Problem('Release corrupted.',409);}
            $this->app->db->transaction(function()use($s,$site,$release,$u,$restored){
                if($this->server($s)){$this->deploy($s,$release);}
                $this->app->db->execute('UPDATE releases SET published=1 WHERE id=?',[$release]);
                $this->app->db->execute('UPDATE sites SET active_release=?,revision=revision+1 WHERE id=?',[$release,$site]);
                $this->app->audit($u['id'],$restored?'release.restored':'release.published',$site,['release_id'=>$release]);
                $this->app->extensions->emit('release.published',$u['id'],['site'=>$site,'release'=>$release,'restored'=>$restored]);
            });
            if($this->server($s)){$this->routes();}
            return ['site_id'=>$site,'release_id'=>$release,'live'=>true,'enabled'=>(bool)$s['enabled'],'status'=>'live','revision'=>$revision+1];
        });
    }
    public function configure(array $u,string $site,array $values,int $revision): void
    {
        $changes=['enabled'=>!empty($values['enabled']),'auto_publish'=>!empty($values['auto_publish']),'spa'=>!empty($values['spa'])];
        foreach(['name','host','runtime'] as $key){if(isset($values[$key])&&is_scalar($values[$key])){$changes[$key]=(string)$values[$key];}}
        $this->update($u,$site,$changes,$revision);
    }
    public function update(array $u,string $site,array $values,int $revision): array
    {
        $this->app->auth->access($u,$site,'manage');
        return $this->app->lock(function()use($u,$site,$values,$revision){
            $s=$this->get($site);if($s['revision']!==$revision){throw new Problem('Release changed.',409);}
            $name=array_key_exists('name',$values)?trim((string)$values['name']):$s['name'];if($name===''||mb_strlen($name)>100){throw new Problem('Check name and domain.');}
            $host=array_key_exists('host',$values)?Files::host((string)$values['host']):$s['host'];
            $runtime=array_key_exists('runtime',$values)?(string)$values['runtime']:($s['runtime']??'static');if(!in_array($runtime,self::RUNTIMES,true)){throw new Problem('Invalid website type.');}
            $wasServer=$this->server($s);$isServer=$runtime==='server';
            if($host!==$s['host']){
                $this->app->auth->requireOwner($u);
                if($host===strtolower($this->app->config['admin_host'])&&!$this->adminHostAllowed()){throw new Problem('Check name and domain.');}
                if($this->app->db->one('SELECT id FROM sites WHERE host=? AND id<>?',[$host,$site])){throw new Problem('Domain already exists.');}
                $this->platform('host',$s,$host);
            }
            if($isServer!==$wasServer){$this->app->auth->requireOwner($u);$this->executable($u,['runtime'=>'server']);if($isServer){$this->supportsServer();}}
            if($wasServer&&!$isServer&&$s['active_release']){$this->staticSafe($site,$s['active_release']);}
            if($isServer&&!$wasServer&&$s['active_release']){$this->deploy(['host'=>$host]+$s,$s['active_release']);}
            elseif($isServer&&$wasServer&&$host!==$s['host']){$this->moveApp($s['host'],$host);}
            $flag=fn(string $key):int=>array_key_exists($key,$values)?(int)!empty($values[$key]):(int)$s[$key];
            $this->app->db->execute('UPDATE sites SET name=?,host=?,enabled=?,auto_publish=?,spa=?,runtime=?,revision=revision+1 WHERE id=?',[$name,$host,$flag('enabled'),$flag('auto_publish'),$flag('spa'),$runtime,$site]);
            if($wasServer&&!$isServer){$this->archiveApp($s);}
            $this->app->audit($u['id'],'site.configured',$site,array_filter(['host'=>$host!==$s['host']?$host:null,'previous_host'=>$host!==$s['host']?$s['host']:null,'runtime'=>$runtime!==$s['runtime']?$runtime:null]));
            $this->routes();
            return $this->get($site);
        });
    }
    public function delete(array $u,string $site,int $revision,string $confirm): array
    {
        $this->app->auth->requireOwner($u);$this->app->auth->access($u,$site,'manage');
        return $this->app->lock(function()use($u,$site,$revision,$confirm){
            $s=$this->get($site);if($s['revision']!==$revision){throw new Problem('Release changed.',409);}
            if(trim($confirm)!==$s['name']){throw new Problem('Type the website name to confirm.');}
            $this->platform('delete',$s,null);
            $changes=array_column($this->app->db->all('SELECT id FROM changes WHERE site_id=?',[$site]),'id');
            $this->app->db->transaction(function()use($site){
                foreach(['DELETE FROM releases WHERE site_id=?','DELETE FROM changes WHERE site_id=?','DELETE FROM user_sites WHERE site_id=?',"UPDATE extension_records SET deleted=1 WHERE site=?"] as $sql){$this->app->db->execute($sql,[$site]);}
                foreach($this->app->db->all('SELECT id,sites,scopes FROM tokens') as $row){$list=json_decode($row['sites'],true)?:[];$scopes=json_decode($row['scopes'],true)?:[];$kept=array_values(array_filter($scopes,fn($scope)=>!str_ends_with((string)$scope,'@'.$site)));if(in_array($site,$list,true)||count($kept)!==count($scopes)){$this->app->db->execute('UPDATE tokens SET sites=?,scopes=? WHERE id=?',[Files::json(array_values(array_diff($list,[$site]))),Files::json($kept),$row['id']]);}}
                if($this->app->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='oauth_refresh'")){foreach($this->app->db->all('SELECT hash,sites FROM oauth_refresh') as $row){$list=json_decode($row['sites'],true);if(is_array($list)&&in_array($site,$list,true)){$this->app->db->execute('UPDATE oauth_refresh SET sites=? WHERE hash=?',[Files::json(array_values(array_diff($list,[$site]))),$row['hash']]);}}}
                $this->app->db->execute('DELETE FROM sites WHERE id=?',[$site]);
            });
            foreach($changes as $change){Files::remove($this->app->storage('staging/'.$change));}
            Files::remove($this->app->storage('sites/'.$site));
            if($this->server($s)){$this->routes();Files::remove($this->appDir($s['host']));}
            $this->app->audit($u['id'],'site.deleted',$site,['name'=>$s['name'],'host'=>$s['host']]);
            $this->app->extensions->emit('site.deleted',$u['id'],['site'=>$site,'host'=>$s['host']]);
            return ['site_id'=>$site,'deleted'=>true];
        });
    }
    public function deployment(array $site): ?array
    {
        if(!$this->server($site)){return null;}$file=$this->app->storage('sites/'.$site['id'].'/deployed.json');$data=is_file($file)?json_decode((string)file_get_contents($file),true):null;
        return ['folder'=>$this->appDir($site['host']).'/current','live'=>is_dir($this->appDir($site['host']).'/current'),'release_id'=>$data['release']??null,'deployed_at'=>$data['deployed_at']??null,'kept'=>$data['kept']??[]];
    }
    public function routes(): void
    {
        if(!$this->serverPossible()){return;}
        $front=dirname($this->app->root);$file=$front.'/.htaccess';$install=preg_quote(basename($this->app->root),'~');$apps=basename($this->appsRoot());$admin=strtolower($this->app->config['admin_host']);$rules=[];
        foreach($this->app->db->all('SELECT * FROM sites ORDER BY host') as $s){
            $bare=preg_replace('/^www\./','',preg_replace('/:[0-9]+$/','',$s['host']));$condition='RewriteCond %{HTTP_HOST} ^(?:www\.)?'.preg_quote($bare,'~').'(?::[0-9]+)?$ [NC]';
            if($this->server($s)){
                if(!$s['active_release']){continue;}
                $live=$s['enabled']&&is_dir($this->appDir($s['host']).'/current');
                $rules[]=$condition;$rules[]=$live?'RewriteRule ^(.*)$ '.$apps.'/'.$this->appFolder($s['host']).'/current/$1 [L]':'RewriteRule ^ '.basename($this->app->root).'/index.php [L]';
            }elseif($s['host']===$admin&&$s['active_release']){$rules[]=$condition;$rules[]='RewriteRule ^ '.basename($this->app->root).'/index.php [L]';}
        }
        $old=is_file($file)?(string)file_get_contents($file):'';$rest=trim((string)preg_replace('~# BEGIN DirectWeb\R.*?# END DirectWeb\R?~s','',$old));
        $block='';
        if($rules){
            $block="# BEGIN DirectWeb\n# Managed by DirectWeb. Changes inside this block are replaced.\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^\.well-known/acme-challenge/ - [L]\nRewriteCond %{THE_REQUEST} \\s/+".preg_quote($apps,'~')."[/?\\s] [NC]\nRewriteRule ^ - [F,L]\nRewriteRule ^".preg_quote($apps,'~')."/ - [L]\nRewriteRule ^".$install."(?:/|$) - [L]\n".implode("\n",$rules)."\n</IfModule>\n# END DirectWeb\n";
        }
        $next=$block.($rest!==''?($block!==''?"\n":'').$rest."\n":'');
        if($next===$old){if(is_file($file)){@chmod($file,0644);}return;}
        if($next===''){if(is_file($file)&&!@unlink($file)){throw new Problem('DirectWeb cannot update %s.',503,[$file]);}return;}
        $tmp=$file.'.'.Files::id().'.tmp';if(@file_put_contents($tmp,$next)===false||!@chmod($tmp,0644)||!@rename($tmp,$file)){@unlink($tmp);throw new Problem('DirectWeb cannot update %s.',503,[$file]);}
    }
    private function deploy(array $site,string $release): void
    {
        $this->serverPossible()||$this->supportsServer();
        $base=$this->appDir($site['host']);Files::mkdir($base);@chmod($this->appsRoot(),0755);@chmod($base,0755);$current=$base.'/current';$next=$base.'/next-'.Files::id();$record=$this->app->storage('sites/'.$site['id'].'/deployed.json');
        $manifest=json_decode((string)file_get_contents($this->dir($site['id'],$release).'/manifest.json'),true,512,JSON_THROW_ON_ERROR);$previous=is_file($record)?(json_decode((string)file_get_contents($record),true)['files']??[]):[];$kept=[];
        try{
            Files::copy($this->dir($site['id'],$release).'/files',$next);$this->webPermissions($next);
            if(is_dir($current)){
                foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($current,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $f){
                    if($f->isLink()){continue;}$rel=str_replace('\\','/',substr($f->getPathname(),strlen($current)+1));$target=$next.'/'.$rel;
                    if($f->isDir()){if(!is_dir($target)){Files::mkdir($target);@chmod($target,$f->getPerms()&0777);}continue;}
                    if(isset($previous[$rel])){continue;}
                    Files::mkdir(dirname($target));if(is_file($target)){unlink($target);}if(!copy($f->getPathname(),$target)){throw new Problem('Publication failed.',503);}@chmod($target,$f->getPerms()&0777);$kept[]=$rel;
                }
            }
            $old=null;if(is_dir($current)){$old=$base.'/old-'.Files::id();if(!rename($current,$old)){throw new Problem('Publication failed.',503);}}
            if(!rename($next,$current)){if($old){rename($old,$current);}throw new Problem('Publication failed.',503);}
        }catch(\Throwable $e){Files::remove($next);throw $e;}
        Files::write($record,Files::json(['release'=>$release,'files'=>$manifest['files'],'kept'=>$kept,'deployed_at'=>gmdate('c')]));
        if($old){Files::remove($old);}
    }
    private function webPermissions(string $dir): void
    {
        @chmod($dir,0755);foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $f){if(!$f->isLink()){@chmod($f->getPathname(),$f->isDir()?0755:0644);}}
    }
    private function moveApp(string $from,string $to): void
    {
        $source=$this->appDir($from);$target=$this->appDir($to);if(!is_dir($source)){return;}if(file_exists($target)){throw new Problem('Domain already exists.');}if(!rename($source,$target)){throw new Problem('Publication failed.',503);}
    }
    private function archiveApp(array $site): void
    {
        $source=$this->appDir($site['host']);if(!is_dir($source)){return;}$target=$this->app->storage('sites/'.$site['id'].'/app-archive-'.gmdate('Ymd-His'));Files::mkdir(dirname($target));
        if(!@rename($source,$target)){Files::copy($source,$target);Files::remove($source);}@unlink($this->app->storage('sites/'.$site['id'].'/deployed.json'));
    }
    private function staticSafe(string $site,string $release): void
    {
        foreach(array_keys($this->readManifest($site,$release)['files']) as $file){try{Files::path($file);$this->type($file);}catch(Problem){throw new Problem('The live version contains files that a static website cannot serve: %s',422,[$file]);}}
    }
    private function executable(array $u,array $site): void
    {
        if($this->server($site)&&!$this->app->auth->can($u,'execute',(string)($site['id']??'*'))){throw new Problem('This connection may not publish server applications here. That needs the execute right for this website.',403);}
    }
    public function serverPossible(): bool { return empty($this->app->config['wordpress'])&&($this->app->config['base_path']??'')!==''; }
    private function supportsServer(): void
    {
        if(!empty($this->app->config['wordpress'])){throw new Problem('Server applications need the webserver edition of DirectWeb.');}
        if(($this->app->config['base_path']??'')===''){throw new Problem('Server applications need DirectWeb installed in a subfolder, for example /directweb.');}
    }
    private function adminHostAllowed(): bool { return !empty($this->app->config['allow_admin_host_site'])||$this->serverPossible(); }
    private function appsRoot(): string { return rtrim((string)($this->app->config['apps_root']??dirname($this->app->root).'/'.basename($this->app->root).'-apps'),'/\\'); }
    private function appFolder(string $host): string { return str_replace(':','_',Files::host($host)); }
    private function appDir(string $host): string { return $this->appsRoot().'/'.$this->appFolder($host); }
    private function platform(string $event,array $site,?string $host): void
    {
        if(!empty($this->app->config['wordpress'])&&class_exists('\DirectWebWordPress\Bridge')){\DirectWebWordPress\Bridge::siteChanging($this->app,$event,$site,$host);}
    }
    public function pin(array $u,string $site,string $id,bool $pin): void { $this->app->auth->access($u,$site,'manage');$this->release($site,$id);$this->app->db->execute('UPDATE releases SET pinned=? WHERE id=?',[(int)$pin,$id]); }
    public function prune(array $u,string $site): int
    {
        $this->app->auth->access($u,$site,'manage');return $this->app->lock(function()use($u,$site){$s=$this->get($site);$all=$this->releases($u,$site);$count=0;foreach(array_slice($all,max(2,(int)$this->app->setting('retention','20'))) as $r){if($r['pinned']||$r['id']===$s['active_release']||$this->app->db->one("SELECT id FROM changes WHERE base_id=? AND status='open'",[$r['id']])){continue;}$this->app->db->execute('DELETE FROM releases WHERE id=?',[$r['id']]);Files::remove($this->dir($site,$r['id']));$count++;}$this->app->audit($u['id'],'releases.pruned',$site,['count'=>$count]);return $count;});
    }
}
