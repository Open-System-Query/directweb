<?php
declare(strict_types=1);
namespace DirectWeb\Sites;
use DirectWeb\Core\{App,Files,Problem};
final class Sites
{
    public const TYPES=['html'=>'text/html; charset=utf-8','htm'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','mjs'=>'text/javascript; charset=utf-8','json'=>'application/json','txt'=>'text/plain; charset=utf-8','xml'=>'application/xml','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','pdf'=>'application/pdf','mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg','ogg'=>'audio/ogg','wav'=>'audio/wav','wasm'=>'application/wasm','webmanifest'=>'application/manifest+json','map'=>'application/json','vtt'=>'text/vtt'];
    public function __construct(private App $app) {}
    public function list(array $u): array
    {
        $rows=$this->app->db->all('SELECT s.*,r.name AS release_name,r.size,r.files FROM sites s LEFT JOIN releases r ON r.id=s.active_release ORDER BY s.name');
        return array_values(array_filter($rows,function($s)use($u){try{$this->app->auth->access($u,$s['id']);return true;}catch(Problem){return false;}}));
    }
    public function get(string $id): array { $s=$this->app->db->one('SELECT * FROM sites WHERE id=?',[$id]); if(!$s) { throw new Problem('Website not found.',404); } return $s; }
    public function create(array $u,string $name,string $host): array
    {
        $this->app->auth->requireOwner($u); $host=Files::host($host); if($host===strtolower($this->app->config['admin_host']) || trim($name)==='' || strlen($name)>100) { throw new Problem('Check name and domain.'); }
        if($this->app->db->one('SELECT id FROM sites WHERE host=?',[$host])) { throw new Problem('Domain already exists.'); }
        $id=Files::id();$this->app->db->execute('INSERT INTO sites(id,name,host,created_at,auto_publish) VALUES(?,?,?,?,0)',[$id,trim($name),$host,gmdate('c')]);$this->app->audit($u['id'],'site.created',$id);return $this->get($id);
    }
    public function releases(array $u,string $site): array { $this->app->auth->access($u,$site);$active=$this->get($site)['active_release'];return array_map(fn($r)=>$r+['status'=>$r['id']===$active?'live':($r['published']?'retained':'draft')],$this->app->db->all('SELECT * FROM releases WHERE site_id=? ORDER BY created_at DESC,rowid DESC',[$site])); }
    public function release(string $site,string $id): array { $r=$this->app->db->one('SELECT * FROM releases WHERE site_id=? AND id=?',[$site,$id]);if(!$r){throw new Problem('Release not found.',404);}return $r; }
    public function dir(string $site,string $release): string { if(!preg_match('/^[a-f0-9]{32}$/D',$site) || !preg_match('/^[a-f0-9]{32}$/D',$release)){throw new Problem('Invalid release.');} return $this->app->storage('sites/'.$site.'/releases/'.$release); }
    public function begin(array $u,string $site,?string $base): array
    {
        $this->app->auth->access($u,$site,'write');
        return $this->app->lock(function()use($u,$site,$base){
            $s=$this->get($site); if($base===null&&$s['active_release']!==null){throw new Problem('Release changed. Reopen the page.',409);}
            $pending=(int)$this->app->db->one("SELECT COUNT(*) AS n FROM changes WHERE actor=? AND status='open'",[$u['id']])['n'];if($pending>=10){throw new Problem('Too many open changes.',429);}$id=Files::id();$folder=$this->app->storage('staging/'.$id.'/files'); Files::mkdir($folder);
            if($base){$this->release($site,$base);Files::copy($this->dir($site,$base).'/files',$folder);}
            $this->app->db->execute('INSERT INTO changes(id,site_id,base_id,base_revision,actor,created_at) VALUES(?,?,?,?,?,?)',[$id,$site,$base,$s['revision'],$u['id'],gmdate('c')]); return ['change_id'=>$id,'base_release'=>$base,'revision'=>$s['revision']];
        });
    }
    public function change(array $u,string $id,bool $open=true): array
    {
        $c=$this->app->db->one('SELECT * FROM changes WHERE id=?',[$id]);if(!$c || $c['actor']!==$u['id']){throw new Problem('Change not found.',404);} $this->app->auth->access($u,$c['site_id'],'write');if($open && $c['status']!=='open'){throw new Problem('Change is closed.',409);}return $c;
    }
    public function apply(array $u,string $id,array $updates,array $deletes=[]): array
    {
        if(count($updates)+count($deletes)>500){throw new Problem('Too many files.');}
        return $this->app->lock(function()use($u,$id,$updates,$deletes){
            $this->change($u,$id);$base=$this->app->storage('staging/'.$id.'/files');
            foreach($updates as $file=>$content){Files::path($file);$this->type($file);if(!is_string($content)||strlen($content)>2097152){throw new Problem('File too large.');}}
            foreach($deletes as $file){Files::path($file);}
            foreach($updates as $file=>$content){Files::write($base.'/'.$file,$content);}
            foreach($deletes as $file){if(is_file($base.'/'.$file)){unlink($base.'/'.$file);}}
            $manifest=$this->manifest($base,false);return ['change_id'=>$id,'files'=>$manifest['count'],'size'=>$manifest['size']];
        });
    }
    public function type(string $file): string { $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));if(!isset(self::TYPES[$ext])){throw new Problem('File type not allowed: %s',422,[$file]);}return self::TYPES[$ext]; }
    public function manifest(string $dir,bool $entry=true): array
    {
        $files=[];$total=0;$names=[];
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS)) as $f){
            if($f->isLink()||!$f->isFile()){throw new Problem('Invalid file.');}
            $name=str_replace('\\','/',substr($f->getPathname(),strlen($dir)+1));Files::path($name);$type=$this->type($name);
            if(isset($names[strtolower($name)])){throw new Problem('Duplicate filename.');}$names[strtolower($name)]=true;
            $size=$f->getSize();$total+=$size;if($size>$this->app->config['max_upload']||$total>$this->app->config['max_site']||count($files)>=$this->app->config['max_files']){throw new Problem('Storage limit reached.');}
            $files[$name]=['size'=>$size,'hash'=>hash_file('sha256',$f->getPathname()),'type'=>$type];
        }
        ksort($files);$start=isset($files['index.html'])?'index.html':(isset($files['index.htm'])?'index.htm':null);
        if($entry&&!$start){throw new Problem('index.html missing.');}
        return ['start'=>$start,'files'=>$files,'size'=>$total,'count'=>count($files),'hash'=>hash('sha256',Files::json($files))];
    }
    public function validate(array $u,string $id): array
    {
        $c=$this->change($u,$id);$m=$this->manifest($this->app->storage('staging/'.$id.'/files'));$old=$c['base_id']?$this->readManifest($c['site_id'],$c['base_id']):['files'=>[]];$diff=[];
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
            $work=$this->app->storage('staging/'.$id);$m=$this->manifest($work.'/files');
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
                        $this->app->db->execute('INSERT INTO releases(id,site_id,parent_id,name,hash,size,files,actor,created_at,published) VALUES(?,?,?,?,?,?,?,?,?,?)',[$result['release_id'],$s['id'],$c['base_id'],trim($name)?:'Release '.gmdate('Y-m-d H:i'),$m['hash'],$m['size'],$m['count'],$u['id'],gmdate('c'),(int)$result['live']]);
                        if($result['live']){$this->app->db->execute('UPDATE sites SET active_release=?,revision=revision+1 WHERE id=?',[$result['release_id'],$s['id']]);}else{$this->app->db->execute('UPDATE sites SET revision=revision+1 WHERE id=?',[$s['id']]);}
                    }
                    $this->app->db->execute("UPDATE changes SET status='committed',result=? WHERE id=?",[Files::json($result),$id]);
                    $this->app->db->execute('INSERT INTO operations VALUES(?,?,?,?,?)',[$key,$u['id'],$fp,Files::json($result),gmdate('c')]);$this->app->audit($u['id'],'release.committed',$s['id'],$result);
                });
            }catch(\Throwable $e){if(isset($target)&&is_dir($target)){rename($target,$work);}throw $e;}
            if($result['unchanged']){Files::remove($work);}else{$this->app->extensions->emit($result['live']?'release.published':'release.created',$u['id'],['site'=>$s['id'],'release'=>$result['release_id']]);}return $result;
        });
    }
    public function import(array $u,string $site,string $zip,string $name,?string $base,bool $publish=false): array
    {
        $this->app->auth->access($u,$site,'write');if(!is_file($zip)||filesize($zip)>$this->app->config['max_upload']){throw new Problem('Upload too large.');}
        $archive=new \ZipArchive();if($archive->open($zip,\ZipArchive::RDONLY)!==true){throw new Problem('Invalid ZIP.');}
        $c=$this->begin($u,$site,$base);$dir=$this->app->storage('staging/'.$c['change_id'].'/files');
        try{
            Files::remove($dir);Files::mkdir($dir);$entries=[];$total=0;
            if($archive->numFiles>$this->app->config['max_files']){throw new Problem('Too many files.');}
            for($i=0;$i<$archive->numFiles;$i++){
                $stat=$archive->statIndex($i);$p=$stat['name'];if(str_starts_with($p,'__MACOSX/')||basename($p)==='.DS_Store'){continue;}
                Files::path(rtrim($p,'/'));$os=0;$attr=0;$archive->getExternalAttributesIndex($i,$os,$attr);$kind=($attr>>16)&0170000;
                if($kind!==0&&$kind!==0100000&&$kind!==0040000){throw new Problem('Links not allowed.');}if(str_ends_with($p,'/')){continue;}
                $this->type($p);$total+=$stat['size'];if($stat['size']<0||$stat['size']>$this->app->config['max_upload']||$total>$this->app->config['max_site']||!empty($stat['encryption_method'])){throw new Problem('ZIP exceeds the limit.');}
                if(isset($entries[strtolower($p)])){throw new Problem('Duplicate filename.');}$entries[strtolower($p)]=['path'=>$p,'stat'=>$stat];
            }
            $prefix='';$paths=array_column($entries,'path');if(!in_array('index.html',$paths,true)&&!in_array('index.htm',$paths,true)){$tops=array_unique(array_map(fn($p)=>explode('/',$p)[0],$paths));if(count($tops)===1){$prefix=reset($tops).'/';}}
            foreach($entries as $e){$p=$prefix?substr($e['path'],strlen($prefix)):$e['path'];Files::path($p);Files::mkdir(dirname($dir.'/'.$p));$in=$archive->getStream($e['path']);$out=fopen($dir.'/'.$p,'xb');if(!$in||!$out){throw new Problem('Extraction failed.');}try{$n=stream_copy_to_stream($in,$out,$e['stat']['size']+1);}finally{fclose($in);fclose($out);}if($n!==$e['stat']['size']||strtolower(hash_file('crc32b',$dir.'/'.$p))!==sprintf('%08x',$e['stat']['crc'])){throw new Problem('ZIP corrupted.');}}
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
        $this->app->auth->access($u,$site);Files::path($file);$m=$this->readManifest($site,$release);if(!isset($m['files'][$file])||$m['files'][$file]['size']>2097152){throw new Problem('File unavailable.');}return file_get_contents($this->dir($site,$release).'/files/'.$file);
    }
    public function restore(array $u,string $site,string $release,int $revision): array
    {
        return $this->publish($u,$site,$release,$revision,true);
    }
    public function publish(array $u,string $site,string $release,int $revision,bool $restored=false): array
    {
        $this->app->auth->access($u,$site,'write');
        return $this->app->lock(function()use($u,$site,$release,$revision,$restored){
            $s=$this->get($site);if($s['revision']!==$revision){throw new Problem('Release changed.',409);}
            $r=$this->release($site,$release);$m=$this->manifest($this->dir($site,$release).'/files');if(!hash_equals($r['hash'],$m['hash'])){throw new Problem('Release corrupted.',409);}
            $this->app->db->transaction(function()use($site,$release,$u,$restored){
                $this->app->db->execute('UPDATE releases SET published=1 WHERE id=?',[$release]);
                $this->app->db->execute('UPDATE sites SET active_release=?,revision=revision+1 WHERE id=?',[$release,$site]);
                $this->app->audit($u['id'],$restored?'release.restored':'release.published',$site,['release_id'=>$release]);
                $this->app->extensions->emit('release.published',$u['id'],['site'=>$site,'release'=>$release,'restored'=>$restored]);
            });
            return ['site_id'=>$site,'release_id'=>$release,'live'=>true,'enabled'=>(bool)$s['enabled'],'status'=>'live','revision'=>$revision+1];
        });
    }
    public function configure(array $u,string $site,array $values,int $revision): void
    {
        $this->app->auth->access($u,$site,'manage');$this->app->lock(function()use($u,$site,$values,$revision){$s=$this->get($site);if($s['revision']!==$revision){throw new Problem('Release changed.',409);}$this->app->db->execute('UPDATE sites SET enabled=?,auto_publish=?,spa=?,revision=revision+1 WHERE id=?',[(int)!empty($values['enabled']),(int)!empty($values['auto_publish']),(int)!empty($values['spa']),$site]);$this->app->audit($u['id'],'site.configured',$site);});
    }
    public function pin(array $u,string $site,string $id,bool $pin): void { $this->app->auth->access($u,$site,'manage');$this->release($site,$id);$this->app->db->execute('UPDATE releases SET pinned=? WHERE id=?',[(int)$pin,$id]); }
    public function prune(array $u,string $site): int
    {
        $this->app->auth->access($u,$site,'manage');return $this->app->lock(function()use($u,$site){$s=$this->get($site);$all=$this->releases($u,$site);$count=0;foreach(array_slice($all,max(2,(int)$this->app->setting('retention','20'))) as $r){if($r['pinned']||$r['id']===$s['active_release']||$this->app->db->one("SELECT id FROM changes WHERE base_id=? AND status='open'",[$r['id']])){continue;}$this->app->db->execute('DELETE FROM releases WHERE id=?',[$r['id']]);Files::remove($this->dir($site,$r['id']));$count++;}$this->app->audit($u['id'],'releases.pruned',$site,['count'=>$count]);return $count;});
    }
}
