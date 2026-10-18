<?php
declare(strict_types=1);
namespace DirectWeb\Hosting;
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Ui\Surface;
final class Setup
{
    public function __construct(private App $app){}
    public function local(): bool {return !$this->app->config['secure']&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true);}
    public function prepare(): void
    {
        if($this->app->auth->installed()||$this->local()){return;}$this->app->lock(function(){$file=$this->app->storage('database/setup.key');if(!is_file($file)){Files::write($file,bin2hex(random_bytes(24)));chmod($file,0600);}});
    }
    public function authorize(string $key): void
    {
        if($this->app->auth->installed()){throw new Problem('Already installed.',409);}if($this->local()){return;}$this->app->auth->limit('setup:'.hash('sha256',$_SERVER['REMOTE_ADDR']??''),8,900);$file=$this->app->storage('database/setup.key');if(!is_file($file)||!hash_equals(trim(file_get_contents($file)),trim($key))){throw new Problem('Invalid installation key.',403);}
    }
    public function complete(): void
    {
        Files::write($this->app->storage('database/installation.json'),Files::json(['admin_host'=>$this->app->config['admin_host'],'base_path'=>$this->app->config['base_path']??'']));$file=$this->app->storage('database/setup.key');if(is_file($file)){unlink($file);}
    }
    public function page(array $u): array
    {
        $this->app->auth->requireOwner($u);$p=new Surface($this->app,'Hosting','admin.hosting',$u);$rows=[['name'=>'PHP','value'=>PHP_VERSION],['name'=>'Server','value'=>$_SERVER['SERVER_SOFTWARE']??PHP_SAPI],['name'=>'URL','value'=>($this->app->config['secure']?'https':'http').'://'.$this->app->config['admin_host'].$this->app->url('/admin')],['name'=>'Upload','value'=>ini_get('upload_max_filesize')],['name'=>'POST','value'=>ini_get('post_max_size')],['name'=>'Memory','value'=>ini_get('memory_limit')],['name'=>'Storage','value'=>is_writable($this->app->config['storage'])?'Writable':'Read only']];$p->section('Environment',[$p->table('Hosting',['name'=>'Setting','value'=>'Value'],$rows)],['icon'=>'server']);$p->section('Requirements',[$p->text('PHP 8.1 · SQLite · ZIP · Sodium · Mbstring · OpenSSL · Fileinfo'),$p->text('Apache rewrite rules and HTTPS are required for the upload package.'),$p->text('Additional domains must point to this installation.'),$p->text('Scheduled jobs require a hosting cron task.'),$p->node('codeBlock',['text'=>'php '.$this->app->root.'/bin/directweb.php worker'])],['icon'=>'gear']);return $p->build();
    }
}
