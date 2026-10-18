<?php
declare(strict_types=1);
namespace DirectWebFolders;
use DirectWeb\Core\{App,Files,Problem};
final class Folders
{
    public function __construct(private App $app){}
    private function normalize(string $path): string {return rtrim(str_replace('\\','/',$path),'/');}
    private function within(string $path,string $root): bool {if(PHP_OS_FAMILY==='Windows'){$path=strtolower($path);$root=strtolower($root);}return $path===$root||str_starts_with($path,$root.'/');}
    public function roots(): array {return $this->app->db->all('SELECT * FROM folder_roots ORDER BY name');}
    public function add(array $u,string $name,string $path): void
    {
        $this->app->auth->requireOwner($u);$real=realpath($path);if(trim($name)===''||strlen($name)>100||!$real||!is_dir($real)||!is_readable($real)||is_link($path)||preg_match('/[\x00-\x1f\x7f]/',$path)){throw new Problem('Choose a readable folder.');}$real=$this->normalize($real);if($real===''||preg_match('~^[A-Za-z]:$~',$real)){throw new Problem('Choose a hosting folder.');}if($this->app->db->one('SELECT id FROM folder_roots WHERE path=?',[$real])){throw new Problem('Folder already added.');}$id=Files::id();$this->app->db->execute('INSERT INTO folder_roots VALUES(?,?,?)',[$id,trim($name),$real]);$this->app->audit($u['id'],'folder.added',$id);
    }
    public function directory(string $root,string $relative): string
    {
        $r=$this->app->db->one('SELECT * FROM folder_roots WHERE id=?',[$root])??throw new Problem('Folder missing.',404);if($relative!==''){Files::path($relative);}$base=$this->normalize((string)realpath($r['path']));if($base!==$r['path']||is_link($r['path'])){throw new Problem('Folder changed.',409);}$path=$base;if($relative!==''){foreach(explode('/',$relative) as $part){$path.='/'.$part;if(is_link($path)){throw new Problem('Symbolic links are not supported.');}}}$real=realpath($path);if(!$real||!is_dir($real)||!$this->within($this->normalize($real),$base)){throw new Problem('Folder missing.',404);}return $this->normalize($real);
    }
    private function writable(string $path): void
    {
        foreach([$this->app->root,$this->app->config['storage']] as $private){$private=$this->normalize((string)realpath($private));if($private!==''&&($this->within($path,$private)||$this->within($private,$path))){throw new Problem('DirectWeb folders are protected.');}}
        if(!is_writable($path)){throw new Problem('Folder is not writable.');}$file=$path.'/.htaccess';if(is_link($file)||(file_exists($file)&&(!is_file($file)||!is_readable($file)||!is_writable($file)||filesize($file)>1048576))){throw new Problem('Cannot manage this .htaccess file.');}
    }
    private function state(string $directory): array
    {
        $file=$directory.'/.htaccess';$exists=is_file($file);$text=$exists?file_get_contents($file):'';if($text===false){throw new Problem('File unavailable.');}return ['exists'=>$exists,'text'=>$text,'hash'=>hash('sha256',($exists?'1':'0').$text)];
    }
    private function rules(array $i): array
    {
        $listing=(string)($i['listing']??'inherit');$access=(string)($i['access']??'inherit');if(!in_array($listing,['inherit','off','on'],true)||!in_array($access,['inherit','blocked','ip'],true)){throw new Problem('Invalid setting.');}
        $listingText='';$text='';if($listing!=='inherit'){$listingText.='Options '.($listing==='on'?'+':'-')."Indexes\n";}$ips=[];
        if($access==='ip'){$ips=preg_split('/[\s,]+/',trim((string)($i['ips']??'')),-1,PREG_SPLIT_NO_EMPTY);if(!$ips||count($ips)>32){throw new Problem('Check IP addresses.');}foreach($ips as $ip){if(!filter_var($ip,FILTER_VALIDATE_IP)){throw new Problem('Check IP addresses.');}}}
        if($access!=='inherit'){$text.="RewriteEngine On\n";if($access==='ip'){$text.='RewriteCond %{REMOTE_ADDR} !^(?:'.implode('|',array_map(fn($ip)=>preg_quote($ip,'~'),$ips)).")$\n";}$text.="RewriteRule ^ - [F,END]\n";}
        $index=trim((string)($i['index']??''));if($index!==''){foreach(preg_split('/\s+/',$index) as $file){Files::path($file);if(str_contains($file,'/')){throw new Problem('Use index filenames.');}}$listingText.='DirectoryIndex '.$index."\n";}
        $errors=[];foreach(['403','404','500'] as $status){$path=trim((string)($i['error_'.$status]??''));if($path!==''){if(!preg_match('~^/[a-zA-Z0-9/_-]+\.(?:html?|php)$~D',$path)){throw new Problem('Use a local error page.');}$listingText.='ErrorDocument '.$status.' '.$path."\n";}$errors['error_'.$status]=$path;}
        $cache=(string)($i['cache']??'inherit');if(!in_array($cache,['inherit','off','hour','day','week'],true)){throw new Problem('Invalid cache setting.');}if($cache!=='inherit'){$value=$cache==='off'?'no-store':'public, max-age='.match($cache){'hour'=>3600,'day'=>86400,default=>604800};$listingText.="<IfModule mod_headers.c>\nHeader set Cache-Control \"".$value."\"\n</IfModule>\n";}
        $protect=!empty($i['protect']);$user=trim((string)($i['username']??''));$credentials=(string)($i['credentials']??'');$password=(string)($i['password']??'');
        if($protect){if(!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$user)){throw new Problem('Invalid username.');}if($password!==''){if(strlen($password)<12||strlen($password)>72){throw new Problem('Use 12 to 72 password characters.');}$credentials=$user.':'.password_hash($password,PASSWORD_BCRYPT)."\n";}if(!preg_match('/^'.preg_quote($user,'/').':\$2[aby]\$\d{2}\$[.\/a-zA-Z0-9]{53}\n$/D',$credentials)){throw new Problem('Enter a password.');}$authFile=$this->app->storage('plugins/folders/access/'.hash('sha256',$credentials).'.passwd');if(str_contains($authFile,'"')||preg_match('/[\r\n]/',$authFile)){throw new Problem('Invalid storage path.');}$listingText.="<FilesMatch \".*\">\nAuthMerging Off\nAuthType Basic\nAuthName \"Protected\"\nAuthUserFile \"".str_replace('\\','/',$authFile)."\"\nRequire valid-user\n</FilesMatch>\n";}
        return ['managed'=>$text,'listing_rules'=>$listingText,'options'=>['listing'=>$listing,'access'=>$access,'ips'=>implode(', ',$ips),'index'=>$index,'cache'=>$cache,'protect'=>$protect,'username'=>$user,'credentials'=>$credentials]+$errors];
    }
    public function preview(array $u,array $input): array
    {
        $this->app->auth->requireOwner($u);$root=(string)($input['root']??'');$relative=trim((string)($input['path']??''));$directory=$this->directory($root,$relative);$this->writable($directory);$current=$this->state($directory);$saved=$this->app->db->one('SELECT * FROM folder_rules WHERE root=? AND path=?',[$root,$relative]);if($saved&&!hash_equals($saved['hash'],$current['hash'])){throw new Problem('File changed outside DirectWeb.',409);}$base=$saved?$saved['base']:$current['text'];if(empty($input['password'])&&!isset($input['credentials'])&&$saved){$stored=json_decode($saved['options'],true);$input['credentials']=$stored['credentials']??($input['credentials']??'');}$rules=$this->rules($input);return ['root'=>$root,'path'=>$relative,'directory'=>$directory,'before'=>$current,'base'=>$base,'after'=>$rules['managed'].$base.($base!==''&&!str_ends_with($base,"\n")?"\n":'').$rules['listing_rules']]+$rules;
    }
    public function apply(array $u,array $preview): string
    {
        $this->app->auth->requireOwner($u);return $this->app->lock(function()use($u,$preview){$next=$this->preview($u,['root'=>$preview['root'],'path'=>$preview['path']]+$preview['options']);if(!hash_equals($preview['before']['hash'],$next['before']['hash'])||$preview['after']!==$next['after']){throw new Problem('File changed outside DirectWeb.',409);}$id=Files::id();$snapshot=$this->app->storage('plugins/folders/history/'.$id.'.json');$previous=$this->app->db->one('SELECT * FROM folder_rules WHERE root=? AND path=?',[$next['root'],$next['path']]);Files::write($snapshot,Files::json(['before'=>$next['before'],'rules'=>$previous]));$hash=hash('sha256','1'.$next['after']);$this->app->db->execute('INSERT INTO folder_changes VALUES(?,?,?,?,?,?,?)',[$id,$next['root'],$next['path'],$next['before']['hash'],$hash,$id.'.json',gmdate('c')]);if(!empty($next['options']['protect'])){$credentials=$next['options']['credentials'];Files::write($this->app->storage('plugins/folders/access/'.hash('sha256',$credentials).'.passwd'),$credentials);}$this->write($next['directory'],$next['after']);$this->app->db->execute('INSERT INTO folder_rules VALUES(?,?,?,?,?,?) ON CONFLICT(root,path) DO UPDATE SET base=excluded.base,managed=excluded.managed,hash=excluded.hash,options=excluded.options',[$next['root'],$next['path'],$next['base'],$next['managed'],$hash,Files::json($next['options'])]);$this->app->audit($u['id'],'folder.configured',$id);return $id;});
    }
    private function write(string $directory,string $text): void
    {
        $file=$directory.'/.htaccess';$mode=is_file($file)?fileperms($file)&0777:0644;$temp=$directory.'/.directweb-'.Files::id();try{if(file_put_contents($temp,$text,LOCK_EX)!==strlen($text)||!chmod($temp,$mode)||!rename($temp,$file)){throw new Problem('Save failed.');}}finally{if(is_file($temp)){unlink($temp);}}
    }
    public function restore(array $u,string $id): void
    {
        $this->app->auth->requireOwner($u);$this->app->lock(function()use($u,$id){$change=$this->app->db->one('SELECT * FROM folder_changes WHERE id=?',[$id])??throw new Problem('Version missing.',404);$directory=$this->directory($change['root'],$change['path']);$this->writable($directory);$current=$this->state($directory);if(!hash_equals($change['after_hash'],$current['hash'])){throw new Problem('File changed outside DirectWeb.',409);}$saved=json_decode(file_get_contents($this->app->storage('plugins/folders/history/'.$change['snapshot'])),true,32,JSON_THROW_ON_ERROR);$backup=Files::id();Files::write($this->app->storage('plugins/folders/history/'.$backup.'.json'),Files::json(['before'=>$current,'rules'=>$this->app->db->one('SELECT * FROM folder_rules WHERE root=? AND path=?',[$change['root'],$change['path']])]));$this->app->db->execute('INSERT INTO folder_changes VALUES(?,?,?,?,?,?,?)',[$backup,$change['root'],$change['path'],$current['hash'],$saved['before']['hash'],$backup.'.json',gmdate('c')]);if($saved['before']['exists']){$this->write($directory,$saved['before']['text']);}elseif(!unlink($directory.'/.htaccess')){throw new Problem('Save failed.');}$this->app->db->execute('DELETE FROM folder_rules WHERE root=? AND path=?',[$change['root'],$change['path']]);if($saved['rules']){$r=$saved['rules'];$this->app->db->execute('INSERT INTO folder_rules VALUES(?,?,?,?,?,?)',[$r['root'],$r['path'],$r['base'],$r['managed'],$r['hash'],$r['options']]);}$this->app->audit($u['id'],'folder.restored',$id);});
    }
}
