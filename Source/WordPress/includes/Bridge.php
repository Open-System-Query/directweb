<?php
declare(strict_types=1);
namespace DirectWebWordPress;
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Ui\Surface;
final class Bridge
{
    private static ?App $app=null;
    public static function register(): void
    {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_enqueue_scripts',[self::class,'assets']);
        add_action('plugins_loaded',[self::class,'route'],-10000);
        add_action('init',static function(): void {add_rewrite_rule('^directweb(?:/.*)?$','index.php','top');},0);
        add_action('directweb_worker',[self::class,'worker']);
        add_filter('cron_schedules',static function(array $s): array {$s['directweb_minute']=['interval'=>60,'display'=>'DirectWeb'];return $s;});
        add_filter('plugin_action_links_'.plugin_basename(dirname(__DIR__).'/directweb.php'),static fn(array $links): array=>array_merge(['<a href="'.esc_url(admin_url('admin.php?page=directweb')).'">DirectWeb</a>'],$links));
    }
    public static function allowed(?\WP_User $u=null): bool
    {
        $u??=wp_get_current_user();
        return $u->exists()&&user_can($u,'manage_options')&&user_can($u,'unfiltered_html');
    }
    public static function activate(): void
    {
        try {
            if(is_multisite()){throw new \RuntimeException('DirectWeb requires a single-site WordPress installation.');}
            if(!self::allowed()){throw new \RuntimeException('Administrator access is required.');}
            $permalinks=(string)get_option('permalink_structure','');
            if($permalinks===''||str_starts_with($permalinks,'/index.php')){throw new \RuntimeException('Choose Post name in Settings > Permalinks, then activate DirectWeb.');}
            if(PHP_VERSION_ID<80100){throw new \RuntimeException('PHP 8.1 is required.');}
            foreach(['pdo_sqlite','sqlite3','zip','sodium','mbstring','openssl','fileinfo','dom'] as $ext){if(!extension_loaded($ext)){throw new \RuntimeException('PHP extension required: '.$ext);}}
            $home=wp_parse_url(home_url('/'));if(($home['scheme']??'')!=='https'&&!in_array($home['host']??'',['localhost','127.0.0.1','[::1]'],true)){throw new \RuntimeException('Enable HTTPS in WordPress before activating DirectWeb.');}
            self::storage();
            if(!get_option('directweb_installed')){
                $a=self::app();self::sync($a,wp_get_current_user());$a->plugins->boot();
                update_option('directweb_installed',1,false);
                add_option('directweb_public_site','', '',false);
            }
            add_rewrite_rule('^directweb(?:/.*)?$','index.php','top');flush_rewrite_rules();
            if(!wp_next_scheduled('directweb_worker')){wp_schedule_event(time()+60,'directweb_minute','directweb_worker');}
        } catch(\Throwable $e){wp_die(esc_html($e->getMessage()),'DirectWeb',['back_link'=>true]);}
    }
    public static function deactivate(): void {wp_clear_scheduled_hook('directweb_worker');}
    public static function storage(): string
    {
        $saved=(string)get_option('directweb_storage','');
        $doc=realpath($_SERVER['DOCUMENT_ROOT']??'')?:realpath(ABSPATH);
        if(!$doc){throw new \RuntimeException('The document root could not be determined.');}
        $path=defined('DIRECTWEB_STORAGE_PATH')?(string)DIRECTWEB_STORAGE_PATH:($saved?:dirname($doc).'/directweb-data-'.substr(hash('sha256',ABSPATH.'|'.get_current_blog_id()),0,16));
        $parent=realpath(dirname($path));
        if(!$parent){throw new \RuntimeException('Create the parent directory for DIRECTWEB_STORAGE_PATH.');}
        $path=$parent.'/'.basename($path);
        $normalize=static fn(string $v): string=>strtolower(rtrim(str_replace('\\','/',$v),'/'));
        foreach(array_filter([$doc,realpath(ABSPATH),realpath(WP_CONTENT_DIR)]) as $public){
            $p=$normalize($path);$r=$normalize($public);
            if($p===$r||str_starts_with($p,$r.'/')){throw new \RuntimeException('Set DIRECTWEB_STORAGE_PATH in wp-config.php to a writable directory outside the public web root.');}
        }
        if(is_link($path)||(!is_dir($path)&&!@mkdir($path,0700))||!is_writable($path)){throw new \RuntimeException('Set DIRECTWEB_STORAGE_PATH in wp-config.php to a writable directory outside the public web root.');}
        if($saved!==$path){update_option('directweb_storage',$path,false);}
        return $path;
    }
    public static function plugins(string $storage): string
    {
        $target=$storage.'/plugins/installed';
        if(!is_dir($target)&&!mkdir($target,0700,true)){throw new \RuntimeException('Plugin storage is not writable.');}
        $registry=$storage.'/plugins/registry';if(!is_dir($registry)){mkdir($registry,0700,true);}
        $lock=fopen($registry.'/bundle.lock','c');if(!$lock||!flock($lock,LOCK_EX)){throw new \RuntimeException('Plugin storage is busy.');}
        try{
            $marker=$registry.'/bundled.json';$old=is_file($marker)?json_decode(file_get_contents($marker),true):[];$next=$old;
            foreach(glob(dirname(__DIR__).'/runtime/plugins/*/plugin.json') as $file){
                $m=json_decode(file_get_contents($file),true);$id=$m['id'];$dest=$target.'/'.$id;
                $installed=is_file($dest.'/plugin.json')?json_decode(file_get_contents($dest.'/plugin.json'),true):null;
                $upgrade=isset($old[$id],$installed['version'])&&$installed['version']===$old[$id]&&version_compare($m['version'],$installed['version'],'>');
                if((!isset($old[$id])&&!is_dir($dest))||$upgrade){
                    $temp=$target.'/install-'.bin2hex(random_bytes(8));mkdir($temp,0700);$previous=null;
                    try{
                        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($file),\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST) as $entry){
                            if($entry->isLink()){throw new \RuntimeException('Invalid plugin file.');}
                            $out=$temp.'/'.substr($entry->getPathname(),strlen(dirname($file))+1);
                            if($entry->isDir()){mkdir($out,0700);}elseif(!copy($entry->getPathname(),$out)){throw new \RuntimeException('Plugin installation failed.');}
                        }
                        if($upgrade){$previous=$registry.'/packages/'.$id.'/'.bin2hex(random_bytes(16));if(!is_dir(dirname($previous))){mkdir(dirname($previous),0700,true);}if(!rename($dest,$previous)){throw new \RuntimeException('Plugin update failed.');}}
                        if(!rename($temp,$dest)){if($previous){rename($previous,$dest);}throw new \RuntimeException('Plugin installation failed.');}
                    }finally{
                        if(is_dir($temp)){foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temp,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($temp);}
                    }
                }
                $next[$id]=$m['version'];
            }
            file_put_contents($marker,json_encode($next,JSON_THROW_ON_ERROR),LOCK_EX);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return $target;
    }
    public static function base(): string {return rtrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/').'/directweb';}
    public static function config(): array
    {
        $url=wp_parse_url(home_url('/'));$storage=self::storage();
        return ['storage'=>$storage,'plugin_root'=>self::plugins($storage),'admin_host'=>$url['host'].(isset($url['port'])?':'.$url['port']:''),'base_path'=>self::base(),'secure'=>($url['scheme']??'https')==='https','initial_plugins'=>[],'allow_admin_host_site'=>true,'wordpress'=>true,'wordpress_worker'=>wp_doing_cron(),'version'=>'1.0'];
    }
    public static function app(): App
    {
        if(self::$app){return self::$app;}
        if(!defined('DIRECTWEB_WORDPRESS')){define('DIRECTWEB_WORDPRESS',true);}
        self::$app=require dirname(__DIR__).'/runtime/app/bootstrap.php';
        return self::$app;
    }
    public static function sync(App $a,\WP_User $wp): array
    {
        if(!self::allowed($wp)){throw new Problem('Access denied.',403);}
        $name='wp-'.$wp->ID;$stamp=hash_hmac('sha256',$wp->user_pass,wp_salt('auth'));
        $u=$a->db->one('SELECT * FROM users WHERE username=?',[$name]);
        if(!$u){
            $id=Files::id();
            $a->db->execute('INSERT INTO users(id,name,username,password,role,created_at) VALUES(?,?,?,?,?,?)',[$id,$wp->display_name?:$wp->user_login,$name,'!wordpress','owner',gmdate('c')]);
            $a->set('wordpress.user.'.$wp->ID,$stamp);
        } else {
            $id=$u['id'];
            if(!hash_equals($a->setting('wordpress.user.'.$wp->ID),$stamp)){
                $a->db->execute('UPDATE users SET session_version=session_version+1 WHERE id=?',[$id]);
                $a->db->execute('DELETE FROM tokens WHERE user_id=?',[$id]);
                $a->set('wordpress.user.'.$wp->ID,$stamp);
            }
            $a->db->execute('UPDATE users SET name=?,role=?,enabled=1 WHERE id=?',[$wp->display_name?:$wp->user_login,'owner',$id]);
        }
        return $a->db->one('SELECT * FROM users WHERE id=?',[$id]);
    }
    public static function current(App $a): ?array
    {
        if(!self::allowed()){return null;}
        $u=self::sync($a,wp_get_current_user());
        $session=hash('sha256',wp_get_session_token().'|'.$u['id']);
        if(($_SESSION['wordpress_identity']??'')!==$session){
            session_regenerate_id(true);$_SESSION=['wordpress_identity'=>$session,'csrf'=>bin2hex(random_bytes(32))];
        }
        return $u;
    }
    public static function validate(App $a,array $u): void
    {
        if(!preg_match('/^wp-([0-9]+)$/D',$u['username'],$m)){throw new Problem('Access denied.',403);}
        $wp=get_user_by('id',(int)$m[1]);
        if(!$wp||!self::allowed($wp)||!hash_equals($a->setting('wordpress.user.'.$m[1]),hash_hmac('sha256',$wp->user_pass,wp_salt('auth')))){
            $a->db->execute('DELETE FROM tokens WHERE user_id=?',[$u['id']]);
            throw new Problem('Access denied.',403);
        }
    }
    public static function password(App $a,array $u,string $password): void
    {
        $a->auth->limit('reauth:'.$u['id']);self::validate($a,$u);
        $wp=get_user_by('id',(int)substr($u['username'],3));
        if(!$wp||!wp_check_password($password,$wp->user_pass,$wp->ID)){throw new Problem('Invalid password.',403);}
    }
    public static function menu(): void
    {
        $icon='data:image/svg+xml;base64,'.base64_encode(file_get_contents(dirname(__DIR__).'/runtime/public/assets/icons/globe.svg'));
        add_menu_page('DirectWeb','DirectWeb','manage_options','directweb',[self::class,'panel'],$icon,26);
    }
    public static function assets(string $hook): void
    {
        if($hook!=='toplevel_page_directweb'){return;}
        wp_enqueue_style('directweb-admin',plugins_url('assets/admin.css',dirname(__DIR__).'/directweb.php'),[],'1.0');
        wp_enqueue_script('directweb-admin',plugins_url('assets/admin.js',dirname(__DIR__).'/directweb.php'),[],'1.0',true);
    }
    public static function panel(): void
    {
        if(!self::allowed()){wp_die(esc_html__('You do not have permission to access this page.'));}
        echo '<iframe class="directweb-frame" title="DirectWeb" src="'.esc_url(home_url('/directweb/admin')).'"></iframe>';
    }
    public static function selectSite(App $a,array $u,string $id): void
    {
        $a->auth->requireOwner($u);
        if($id!==''){
            $site=$a->sites->get($id);$a->auth->access($u,$id,'manage');
            if(!$site['enabled']||!$site['active_release']||$site['host']!==$a->config['admin_host']){throw new Problem('Choose a published website on this domain.');}
        }
        $legacy=(string)get_option('hwb_active_site','');
        if($legacy!==''){update_option('directweb_legacy_active',$legacy,false);update_option('hwb_active_site','',false);}
        update_option('directweb_public_site',$id,false);$a->audit($u['id'],'wordpress.website.selected',$id);
        self::clearCache();
    }
    public static function clearCache(): void
    {
        wp_cache_flush();
        foreach(['wp_cache_clear_cache','w3tc_flush_all','rocket_clean_domain'] as $callback){if(function_exists($callback)){try{$callback();}catch(\Throwable $e){error_log('DirectWeb cache: '.$e->getMessage());}}}
        do_action('litespeed_purge_all');do_action('directweb_cache_purge');
    }
    private static function response(int $status,string $message): never
    {
        while(ob_get_level()>0){ob_end_clean();}
        status_header($status);nocache_headers();header('Content-Type: text/plain; charset=utf-8');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){echo $message;}exit;
    }
    public static function route(): void
    {
        if(PHP_SAPI==='cli'||is_multisite()||(defined('WP_CLI')&&WP_CLI)||wp_doing_cron()){return;}
        $path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        $base=self::base();$root=rtrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');
        $discovery=['/.well-known/oauth-authorization-server'.$base=>$base.'/.well-known/oauth-authorization-server','/.well-known/oauth-protected-resource'.$base.'/mcp'=>$base.'/.well-known/oauth-protected-resource/mcp'];
        $internal=$path===$base||str_starts_with($path,$base.'/')||isset($discovery[$path]);
        $public=(string)get_option('directweb_public_site','');
        $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
        if(!$internal){
            if($script==='wp-login.php'){return;}
            if(is_admin()){
                if($public===''||self::allowed()){return;}
                if(!is_user_logged_in()&&!wp_doing_ajax()&&$script!=='admin-post.php'){wp_safe_redirect(wp_login_url(admin_url()),303);exit;}
                self::response(403,'WordPress administration is restricted.');
            }
            if($root!==''&&$path!==$root&&!str_starts_with($path,$root.'/')){return;}
            if($public===''){
                if(isset($_GET['directweb_file'])){self::response(404,'Website unavailable.');}
                return;
            }
            if(preg_match('~/(?:wp-json)(?:/|$)~',$path)||isset($_GET['rest_route'])){
                if(self::allowed()){return;}self::response(404,'Page not found.');
            }
            if((defined('XMLRPC_REQUEST')&&XMLRPC_REQUEST)||!in_array($script,['index.php',''],true)||preg_match('~/(?:wp-content|wp-includes)(?:/|$)|/wp-[^/]+\.php$~',$path)){self::response(404,'Page not found.');}
            foreach(['feed','s','p','page_id','author','attachment_id','preview'] as $key){if(isset($_GET[$key])){self::response(404,'Page not found.');}}
        }
        try {
            if(!defined('DONOTCACHEPAGE')){define('DONOTCACHEPAGE',true);}
            if(!defined('DONOTCACHEDB')){define('DONOTCACHEDB',true);}
            nocache_headers();
            $a=self::app();
            if(strtolower($_SERVER['HTTP_HOST']??'')!==strtolower($a->config['admin_host'])){self::response(404,'Page not found.');}
            if(isset($discovery[$path])){$_SERVER['REQUEST_URI']=$discovery[$path];$path=$discovery[$path];}
            if($internal){
                $relative=substr($path,strlen($base));
                if(preg_match('~^/admin(?:/|$)~',$relative)||$relative==='/oauth/authorize'||$relative===''||$relative==='/'){
                    if(!is_user_logged_in()){wp_safe_redirect(wp_login_url(home_url('/directweb').$relative.(isset($_SERVER['QUERY_STRING'])&&$_SERVER['QUERY_STRING']!==''?'?'.$_SERVER['QUERY_STRING']:'')),303);exit;}
                    if(!self::allowed()){self::response(403,'Access denied.');}
                }
                $_POST=wp_unslash($_POST);$_GET=wp_unslash($_GET);
                if(empty($_SERVER['HTTP_AUTHORIZATION'])&&!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])){$_SERVER['HTTP_AUTHORIZATION']=$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];}
                (new \DirectWeb\Http\Controller($a))->run();exit;
            }
            $a->plugins->boot();$relative=substr($path,strlen($root))?:'/';
            if($relative==='/index.php'){$relative='/';}elseif(str_starts_with($relative,'/index.php/')){$relative=substr($relative,10);}
            if(isset($_GET['directweb_file'])){
                if(!is_string($_GET['directweb_file'])){throw new Problem('Invalid path.',400);}
                $relative='/'.implode('/',array_map('rawurlencode',explode('/',wp_unslash($_GET['directweb_file']))));
            }
            (new Website($a))->serve($a->sites->get($public),$relative);exit;
        } catch(Problem $e){self::response($e->status,$e->getMessage());}
        catch(\Throwable $e){error_log('DirectWeb WordPress: '.$e->getMessage());self::response(503,'DirectWeb is temporarily unavailable.');}
    }
    public static function action(App $a,string $action,array $i): ?string
    {
        if(preg_match('/^(?:setup|login(?:\.|$)|user\.|password\.|2fa\.|sessions\.)/',$action)){throw new Problem('Manage accounts and security in WordPress.',403);}
        if($action==='logout'){wp_logout();$a->auth->logout();return wp_login_url(admin_url('admin.php?page=directweb'));}
        if(str_starts_with($action,'plugin.')||$action==='renderer.select'){
            if(!current_user_can('install_plugins')||(defined('DISALLOW_FILE_MODS')&&DISALLOW_FILE_MODS)){throw new Problem('Plugin management is disabled in WordPress.',403);}
        }
        if($action==='wordpress.publish'){
            $u=$a->auth->current()??throw new Problem('Sign in required.',401);
            self::selectSite($a,$u,(string)($i['site']??''));return '/admin/wordpress';
        }
        if($action==='wordpress.import'){self::importLegacy($a);return '/admin/sites';}
        return null;
    }
    public static function page(App $a,string $route,array $u): ?array
    {
        if(preg_match('~^(?:account|users?)(?:/|$)~',$route)){
            $p=new Surface($a,$route==='account'?'Account':'Users','wordpress.accounts',$u);
            $p->section('WordPress',[$p->text('Manage accounts and security in WordPress.'),$p->link('Account',admin_url('profile.php'),'user'),$p->link('Users',admin_url('users.php'),'users')],['directory'=>true,'icon'=>'shield-halved']);return $p->build();
        }
        if($route==='hosting'){
            $p=new Surface($a,'Hosting','wordpress.hosting',$u);
            $p->section('WordPress',[$p->table('Hosting',['name'=>'Setting','value'=>'Value'],[['name'=>'PHP','value'=>PHP_VERSION],['name'=>'URL','value'=>home_url('/')],['name'=>'Worker','value'=>$a->setting('worker_last','Pending')]]),$p->text('Background jobs use WordPress cron.'),$p->text(rtrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/')!==''?'OAuth discovery requires routing from the domain root.':'')]);return $p->build();
        }
        if($route==='wordpress'){
            $p=new Surface($a,'Publishing','wordpress.publishing',$u);
            $current=(string)get_option('directweb_public_site','');
            $name=$current!==''?($a->db->one('SELECT name FROM sites WHERE id=?',[$current])['name']??'DirectWeb'):'WordPress';
            $p->section('Website',[$p->text($name,false),$p->link('Open website',home_url('/'),'globe'),$p->link('Change website','/admin/wordpress/publish','arrows-rotate')],['directory'=>true,'icon'=>'globe']);
            if(get_option('hwb_sites',[])){$p->section('Import',[$p->link('HTML website manager','/admin/wordpress/import','file-import')],['directory'=>true,'icon'=>'folder']);}
            return $p->build();
        }
        if($route==='wordpress/publish'){
            $p=new Surface($a,'Change website','wordpress.publish',$u);$options=[['value'=>'','label'=>'WordPress','literal'=>true]];
            foreach($a->sites->list($u) as $s){if($s['active_release']&&$s['host']===$a->config['admin_host']){$options[]=['value'=>$s['id'],'label'=>$s['name'],'literal'=>true];}}
            $p->section('Website',[$p->form('wordpress.publish','',['site'=>['label'=>'Website','kind'=>'choice','value'=>(string)get_option('directweb_public_site',''),'options'=>$options]],[],'Apply',true)]);return $p->build();
        }
        if($route==='wordpress/import'){
            $p=new Surface($a,'Import','wordpress.import',$u);
            $p->section('HTML website manager',[$p->text('Import existing versions. Original files remain available.'),$p->form('wordpress.import','',[],[],'Import',true)]);return $p->build();
        }
        return null;
    }
    public static function importLegacy(App $a): void
    {
        $u=$a->auth->current()??throw new Problem('Sign in required.',401);$a->auth->requireOwner($u);
        $versions=get_option('hwb_sites',[]);
        if(!is_array($versions)||!$versions){throw new Problem('No websites available.');}
        $site=$a->db->one('SELECT * FROM sites WHERE host=?',[$a->config['admin_host']])??$a->sites->create($u,'Website',$a->config['admin_host']);
        $a->backups->create($u);
        foreach($versions as $id=>$version){
            if(!preg_match('/^[a-f0-9]{32}$/D',(string)$id)){throw new Problem('Invalid website.');}
            if($a->setting('wordpress.import.'.$id)!==''){continue;}
            $dir=WP_CONTENT_DIR.'/html-website-beheer-opslag/'.$id;
            $read=static function(string $path)use($dir): string{
                $file=$dir.'/'.hash('sha256',$path).'.php';$prefix="<?php http_response_code(404); exit; ?>\n";
                if(is_link($dir)||is_link($file)||!is_file($file)){throw new Problem('Invalid website file.');}
                $raw=file_get_contents($file);if(!str_starts_with($raw,$prefix)){throw new Problem('Invalid website file.');}
                $data=base64_decode(substr($raw,strlen($prefix)),true);if($data===false){throw new Problem('Invalid website file.');}return $data;
            };
            $manifest=json_decode($read('@manifest'),true,32,JSON_THROW_ON_ERROR);
            $zip=$a->storage('staging/wordpress-import-'.Files::id().'.zip');$z=new \ZipArchive();
            if($z->open($zip,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true){throw new Problem('Import failed.');}
            try{
                foreach($manifest['files'] as $path=>$entry){Files::path($path);$data=$read($path);if(strlen($data)!==(int)$entry['size']){throw new Problem('Invalid website file.');}$z->addFromString($path,$data);}
                $z->close();$release=$a->sites->import($u,$site['id'],$zip,(string)($version['name']??'Imported website'),$a->sites->get($site['id'])['active_release'],false);
                $a->sites->pin($u,$site['id'],$release['release_id'],true);$a->set('wordpress.import.'.$id,$release['release_id']);
            }finally{if(is_file($zip)){unlink($zip);}}
        }
        $a->audit($u['id'],'wordpress.versions.imported',$site['id']);
    }
    public static function worker(): void
    {
        try{$a=self::app();$a->plugins->boot();$a->extensions->dispatch();$a->jobs->work();if($a->setting('worker_restart')==='1'){$a->set('worker_restart','0');return;}foreach($a->plugins->tasks as $task){$task();}$a->backups->prune();}catch(\Throwable $e){error_log('DirectWeb worker: '.$e->getMessage());}
    }
}
