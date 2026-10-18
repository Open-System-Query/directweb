<?php
declare(strict_types=1);
namespace DirectWeb\Backups;
use DirectWeb\Core\{App,Files,Problem};
final class Backups
{
    public function __construct(private App $app) {}
    public function create(array $u): array
    {
        $this->app->auth->requireOwner($u);return $this->app->lock(function()use($u){
            $id=Files::id();$tmp=$this->app->storage('staging/backup-'.$id);Files::mkdir($tmp);$out=$this->app->storage('backups/'.$id.'.zip');$z=new \ZipArchive();
            try{
                $source=new \SQLite3($this->app->storage('database/directweb.sqlite'),SQLITE3_OPEN_READONLY);$target=new \SQLite3($tmp.'/directweb.sqlite');if(!$source->backup($target)){throw new Problem('Database backup failed.');}$source->close();$target->close();
                if($z->open($out,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true){throw new Problem('Backup failed.');}$manifest=['version'=>1,'created_at'=>gmdate('c'),'files'=>[]];
                $entries=['database/directweb.sqlite'=>$tmp.'/directweb.sqlite','database/key'=>$this->app->storage('database/key')];
                if(is_file($this->app->storage('database/installation.json'))){$entries['database/installation.json']=$this->app->storage('database/installation.json');}
                foreach(['sites','plugins'] as $folder){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->app->storage($folder),\FilesystemIterator::SKIP_DOTS)) as $f){if($f->isLink()){throw new Problem('Invalid storage.');}if($f->isFile()){$rel=str_replace('\\','/',substr($f->getPathname(),strlen($this->app->storage(''))));$entries[$rel]=$f->getPathname();}}}
                foreach($entries as $relative=>$file){if(!$z->addFile($file,$relative)){throw new Problem('Backup failed.');}$manifest['files'][$relative]=['size'=>filesize($file),'hash'=>hash_file('sha256',$file)];}
                $z->addFromString('backup.json',Files::json($manifest));if(!$z->close()){throw new Problem('Backup failed.');}
                $r=['id'=>$id,'size'=>filesize($out),'hash'=>hash_file('sha256',$out),'created_at'=>gmdate('c')];$destination=$this->app->config['backup_destination']??'';if($destination!==''){Files::mkdir($destination);$temp=$destination.'/'.$id.'.tmp';if(!copy($out,$temp)||!hash_equals($r['hash'],hash_file('sha256',$temp))||!rename($temp,$destination.'/'.$id.'.zip')){throw new Problem('External backup failed.');}}$this->app->db->execute('INSERT INTO backups VALUES(?,?,?,?)',array_values($r));$this->app->audit($u['id'],'backup.created',$id);return $r;
            }catch(\Throwable $e){@unlink($out);throw $e;}finally{Files::remove($tmp);}
        });
    }
    public function delete(array $u,string $id): void
    {
        $this->app->auth->requireOwner($u);
        $this->app->lock(function()use($u,$id){
            if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!$this->app->db->one('SELECT id FROM backups WHERE id=?',[$id])){throw new Problem('Backup missing.',404);}
            if($this->app->jobs->usesBackup($id)){throw new Problem('Backup in use.',409);}
            $file=$this->app->storage('backups/'.$id.'.zip');$temporary=$file.'.delete';$moved=false;
            if(is_file($file)){if(!rename($file,$temporary)){throw new Problem('Delete failed.',503);}$moved=true;}
            try{$this->app->db->transaction(function()use($u,$id){$this->app->db->execute('DELETE FROM backups WHERE id=?',[$id]);$this->app->audit($u['id'],'backup.deleted',$id);});}
            catch(\Throwable $e){if($moved){rename($temporary,$file);}throw $e;}
            if($moved){unlink($temporary);}
        });
    }
    public function prune(): int
    {
        return $this->app->lock(function(){
            $all=$this->app->db->all('SELECT * FROM backups ORDER BY created_at DESC,rowid DESC');$keep=[];$days=[];$weeks=[];$months=[];
            foreach($all as $i=>$b){$time=strtotime($b['created_at']);$day=gmdate('Y-m-d',$time);$week=gmdate('o-W',$time);$month=gmdate('Y-m',$time);if($i<2){$keep[$b['id']]=true;}if(!isset($days[$day])&&count($days)<7){$days[$day]=true;$keep[$b['id']]=true;}if(!isset($weeks[$week])&&count($weeks)<4){$weeks[$week]=true;$keep[$b['id']]=true;}if(!isset($months[$month])&&count($months)<6){$months[$month]=true;$keep[$b['id']]=true;}}
            $count=0;foreach($all as $b){if(!isset($keep[$b['id']])&&!$this->app->jobs->usesBackup($b['id'])){$this->app->db->execute('DELETE FROM backups WHERE id=?',[$b['id']]);Files::remove($this->app->storage('backups/'.$b['id'].'.zip'));$count++;}}return $count;
        });
    }
    public function verify(string $id): array
    {
        $b=$this->app->db->one('SELECT * FROM backups WHERE id=?',[$id]);if(!$b){throw new Problem('Backup missing.',404);}$file=$this->app->storage('backups/'.$id.'.zip');if(!is_file($file)||!hash_equals($b['hash'],hash_file('sha256',$file))){throw new Problem('Backup corrupted.');}return self::inspect($file);
    }
    public function restore(array $u,string $id): array
    {
        $this->app->auth->requireOwner($u);if(PHP_SAPI!=='cli'){throw new Problem('Restore through the worker.');}$this->verify($id);
        $gate=fopen($this->app->storage('database/maintenance.lock'),'c');if(!$gate||!flock($gate,LOCK_EX)){throw new Problem('System busy.',503);}
        $work=$this->app->storage('staging/restore-'.Files::id());Files::mkdir($work);$complete=false;
        try{
            self::inspect($this->app->storage('backups/'.$id.'.zip'),$work.'/incoming');$recovery=$this->create($u);
            $result=$this->app->lock(function()use($u,$id,$work,$recovery){
                $database=$this->app->storage('database/directweb.sqlite');$old=new \SQLite3($database,SQLITE3_OPEN_READONLY);$snapshot=new \SQLite3($work.'/previous.sqlite');if(!$old->backup($snapshot)){throw new Problem('Recovery snapshot failed.');}$old->close();$snapshot->close();$oldKey=file_get_contents($this->app->storage('database/key'));$moved=[];$installed=[];
                try{
                    foreach(['sites','plugins'] as $folder){$current=$this->app->storage($folder);if(!rename($current,$work.'/'.$folder)){throw new Problem('Restore failed.');}$moved[]=$folder;if(is_dir($work.'/incoming/'.$folder)){if(!rename($work.'/incoming/'.$folder,$current)){throw new Problem('Restore failed.');}}else{Files::mkdir($current);}$installed[]=$folder;}
                    $source=new \SQLite3($work.'/incoming/database/directweb.sqlite',SQLITE3_OPEN_READONLY);$target=new \SQLite3($database);if(!$source->backup($target)){throw new Problem('Database restore failed.');}$source->close();$target->close();
                    Files::write($this->app->storage('database/key'),file_get_contents($work.'/incoming/database/key'));chmod($this->app->storage('database/key'),0600);
                    $this->app->db->execute('INSERT OR REPLACE INTO backups VALUES(?,?,?,?)',array_values($recovery));$this->app->set('worker_restart','1');new \DirectWeb\Plugins\Extensions($this->app);$this->app->audit('restore','backup.restored',$id,['recovery'=>$recovery['id']]);$this->app->extensions->emit('backup.restored','restore',['backup'=>$id,'recovery'=>$recovery['id']]);return ['restored'=>$id,'recovery'=>$recovery['id']];
                }catch(\Throwable $e){
                    foreach($installed as $folder){Files::remove($this->app->storage($folder));}foreach($moved as $folder){if(!rename($work.'/'.$folder,$this->app->storage($folder))){throw new Problem('Recovery files require administrator attention.',503);}}
                    $source=new \SQLite3($work.'/previous.sqlite',SQLITE3_OPEN_READONLY);$target=new \SQLite3($database);$ok=$source->backup($target);$source->close();$target->close();Files::write($this->app->storage('database/key'),$oldKey);if(!$ok){throw new Problem('Recovery database requires administrator attention.',503);}throw $e;
                }
            });$complete=true;return $result;
        }finally{if($complete){Files::remove($work);}flock($gate,LOCK_UN);fclose($gate);}
    }
    public static function inspect(string $file,?string $destination=null): array
    {
        $z=new \ZipArchive();if($z->open($file,\ZipArchive::RDONLY)!==true){throw new Problem('Backup corrupted.');}
        try{
            $raw=$z->getFromName('backup.json');$m=$raw?json_decode($raw,true):null;if(!$m||($m['version']??0)!==1||!isset($m['files']['database/directweb.sqlite'],$m['files']['database/key'])||count($m['files'])>200000){throw new Problem('Invalid backup.');}
            $total=0;foreach($m['files'] as $p=>$meta){Files::path($p);if(!preg_match('~^(database/(directweb\.sqlite|key|installation\.json)|sites/[a-f0-9]{32}/releases/[a-f0-9]{32}/.+|plugins/[a-z0-9-]+/.+)$~D',$p)){throw new Problem('Invalid backup path.');}$total+=$meta['size'];if($total>10737418240||$meta['size']<0){throw new Problem('Backup too large.');}$in=$z->getStream($p);if(!$in){throw new Problem('Backup incomplete.');}$hash=hash_init('sha256');$count=hash_update_stream($hash,$in);fclose($in);if($count!==$meta['size']||!hash_equals($meta['hash'],hash_final($hash))){throw new Problem('Backup corrupted.');}}
            if($destination!==null){if(is_dir($destination)&&count(scandir($destination))>2){throw new Problem('Destination must be empty.');}Files::mkdir($destination);foreach($m['files'] as $p=>$meta){Files::mkdir(dirname($destination.'/'.$p));$in=$z->getStream($p);$out=fopen($destination.'/'.$p,'xb');try{stream_copy_to_stream($in,$out);}finally{fclose($in);fclose($out);}}$db=new \SQLite3($destination.'/database/directweb.sqlite');if($db->querySingle('PRAGMA integrity_check')!=='ok'){throw new Problem('Database corrupted.');}$db->exec('DELETE FROM sessions; DELETE FROM tokens; UPDATE users SET session_version=session_version+1;');$db->close();}
            return ['valid'=>true,'files'=>count($m['files']),'size'=>$total];
        }finally{$z->close();}
    }
}
