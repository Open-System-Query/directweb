<?php
declare(strict_types=1);
namespace DirectWebMcp;
use DirectWeb\Core\{App,Files,Problem};
final class Updater
{
    private const REQUIRED=['index.php','.htaccess','app/bootstrap.php','config/app.php','public/index.php'];
    private const KEEP=3;
    public function __construct(private App $app){}
    public function update(array $u,string $package,string $url): array
    {
        $this->app->auth->requireOwner($u);
        if(!empty($this->app->config['wordpress'])){throw new Problem('Update DirectWeb in WordPress under Plugins.');}
        if(($package==='')===($url==='')){throw new Problem('Pass either package or url.');}
        if($package!==''){$bytes=base64_decode(strtr((string)preg_replace('/\s+/','',(string)preg_replace('~^data:[^,]*,~','',$package)),'-_','+/'),true);if($bytes===false||$bytes===''){throw new Problem('Invalid package.');}}
        else{$response=\DirectWeb\Http\Client::fetch($url,['Accept'=>'application/zip,*/*'],67108864);if($response['status']!==200||$response['body']===''){throw new Problem('Download failed (HTTP %s).',422,[(string)$response['status']]);}$bytes=$response['body'];}
        return $this->app->lock(fn()=>$this->install($u,$bytes));
    }
    private function install(array $u,string $bytes): array
    {
        $id=Files::id();$zip=$this->app->storage('staging/update-'.$id.'.zip');$stage=$this->app->storage('staging/update-'.$id);Files::write($zip,$bytes);
        try{
            $files=$this->extract($zip,$stage);
            $current=(string)$this->app->config['version'];$next=$this->version($stage.'/config/app.php');
            if(version_compare($next,$current,'<')){throw new Problem('The package is older than the installed version (%s < %s).',422,[$next,$current]);}
            foreach($files as $file){if(str_ends_with($file,'.php')){try{token_get_all((string)file_get_contents($stage.'/'.$file),TOKEN_PARSE);}catch(\ParseError){throw new Problem('The package contains invalid PHP: %s',422,[$file]);}}}
            $root=rtrim(str_replace('\\','/',$this->app->root),'/');$backup=$this->app->storage('code-backups/'.$current.'-'.gmdate('Ymd-His').'-'.substr($id,0,6));
            $saved=$this->backup($root,$backup);
            try{$this->place($stage,$root,$files);$this->reset();$health=$this->health();}
            catch(\Throwable $e){$health=['ok'=>false,'detail'=>$e->getMessage()];}
            if(!$health['ok']){
                $this->place($backup,$root,$saved);$this->reset();
                $this->app->audit($u['id'],'directweb.update.failed','',['from'=>$current,'to'=>$next,'detail'=>$health['detail']]);
                throw new Problem('The update failed and the previous version was restored: %s',503,[$health['detail']]);
            }
            $this->prune();$this->app->audit($u['id'],'directweb.updated','',['from'=>$current,'to'=>$next]);
            return ['updated'=>true,'previous_version'=>$current,'version'=>$next,'files'=>count($files),'backup'=>basename($backup),'check'=>$health['detail'],'next_action'=>'Tell the user DirectWeb was updated. Reconnect if new tools are expected.'];
        }finally{Files::remove($stage);@unlink($zip);}
    }
    private function extract(string $zip,string $stage): array
    {
        $archive=new \ZipArchive();if($archive->open($zip,\ZipArchive::RDONLY)!==true){throw new Problem('Invalid ZIP.');}
        try{
            if($archive->numFiles<1||$archive->numFiles>20000){throw new Problem('Invalid package.');}
            $names=[];for($i=0;$i<$archive->numFiles;$i++){$names[]=(string)$archive->getNameIndex($i);}
            $prefix='';if(!in_array('app/bootstrap.php',$names,true)){foreach($names as $name){if(preg_match('~^([^/]+/)app/bootstrap\.php$~D',$name,$m)){$prefix=$m[1];break;}}}
            $files=[];$total=0;Files::mkdir($stage);
            for($i=0;$i<$archive->numFiles;$i++){
                $stat=$archive->statIndex($i);$name=$stat['name'];if(str_ends_with($name,'/')){continue;}
                if($prefix!==''&&!str_starts_with($name,$prefix)){throw new Problem('Invalid package.');}
                $rel=substr($name,strlen($prefix));Files::path($rel,true);
                if(str_starts_with($rel,'storage/')||$rel==='config/local.php'){throw new Problem('The package may not contain %s.',422,[$rel]);}
                $os=0;$attr=0;$archive->getExternalAttributesIndex($i,$os,$attr);$kind=($attr>>16)&0170000;if($kind!==0&&$kind!==0100000){throw new Problem('Links not allowed.');}
                $total+=$stat['size'];if($total>134217728||!empty($stat['encryption_method'])){throw new Problem('Package too large.');}
                Files::mkdir(dirname($stage.'/'.$rel));$in=$archive->getStream($name);$out=fopen($stage.'/'.$rel,'xb');if(!$in||!$out){throw new Problem('Extraction failed.');}
                try{$n=stream_copy_to_stream($in,$out);}finally{fclose($in);fclose($out);}
                if($n!==$stat['size']||strtolower(hash_file('crc32b',$stage.'/'.$rel))!==sprintf('%08x',$stat['crc'])){throw new Problem('ZIP corrupted.');}
                $files[]=$rel;
            }
            foreach(self::REQUIRED as $required){if(!in_array($required,$files,true)){throw new Problem('This is not a DirectWeb webserver package (%s missing).',422,[$required]);}}
            return $files;
        }finally{$archive->close();}
    }
    private function version(string $file): string
    {
        if(!preg_match("~'version'\\s*=>\\s*'([0-9]+(?:\\.[0-9]+)*)'~",(string)file_get_contents($file),$m)){throw new Problem('Package version missing.');}return $m[1];
    }
    private function backup(string $root,string $backup): array
    {
        $saved=[];Files::mkdir($backup);
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS)) as $f){
            if($f->isLink()||!$f->isFile()){continue;}$rel=str_replace('\\','/',substr($f->getPathname(),strlen($root)+1));
            if(str_starts_with($rel,'storage/')||$rel==='config/local.php'){continue;}
            Files::mkdir(dirname($backup.'/'.$rel));if(!copy($f->getPathname(),$backup.'/'.$rel)){throw new Problem('Backup failed.',503);}$saved[]=$rel;
        }
        return $saved;
    }
    private function place(string $from,string $root,array $files): void
    {
        foreach($files as $rel){
            $target=$root.'/'.$rel;$dir=dirname($target);if(!is_dir($dir)){Files::mkdir($dir);}@chmod($dir,0755);
            $tmp=$target.'.'.Files::id().'.tmp';if(!copy($from.'/'.$rel,$tmp)||!@chmod($tmp,0644)||!rename($tmp,$target)){@unlink($tmp);throw new Problem('Cannot write %s.',503,[$rel]);}
        }
    }
    private function reset(): void
    {
        if(function_exists('opcache_reset')){@opcache_reset();}clearstatcache(true);
    }
    private function health(): array
    {
        if(empty($this->app->config['secure'])){return ['ok'=>true,'detail'=>'not checked (local installation)'];}
        $url='https://'.$this->app->config['admin_host'].$this->app->url('/admin');
        try{$response=\DirectWeb\Http\Client::request('GET',$url,['Accept'=>'text/html'],'',1048576);}catch(\Throwable $e){return ['ok'=>true,'detail'=>'not checked: '.$e->getMessage()];}
        return $response['status']<500?['ok'=>true,'detail'=>'panel answered HTTP '.$response['status']]:['ok'=>false,'detail'=>'panel answered HTTP '.$response['status']];
    }
    private function prune(): void
    {
        $dirs=glob($this->app->storage('code-backups/*'),GLOB_ONLYDIR)?:[];usort($dirs,fn($a,$b)=>filemtime($b)<=>filemtime($a));
        foreach(array_slice($dirs,self::KEEP) as $dir){Files::remove($dir);}
    }
}
