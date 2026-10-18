<?php
declare(strict_types=1);
namespace DirectWeb\Http;
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Ui\{Pages,Surface};
final class Controller
{
    private ?array $user=null;
    public function __construct(private App $app){}
    public function run(): void
    {
        try{$this->dispatch();}catch(Problem $e){$this->error($this->message($e),$e->status);}catch(\Throwable $e){error_log('DirectWeb: '.$e);$this->error('Action failed.',500);}
    }
    private function dispatch(): void
    {
        $a=$this->app;$host=Files::host($_SERVER['HTTP_HOST']??'');$uri=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$uri=is_string($uri)?$uri:'/';$method=$_SERVER['REQUEST_METHOD']??'GET';
        if($host!==strtolower($a->config['admin_host'])){$this->website($host,$uri);return;}
        $base=$a->config['base_path']??'';if($base!==''){if($uri!==$base&&!str_starts_with($uri,$base.'/')){throw new Problem('Page not found.',404);}$uri=substr($uri,strlen($base))?:'/';$_SERVER['REQUEST_URI']=substr($_SERVER['REQUEST_URI'],strlen($base))?:'/';}
        if($a->config['secure']&&($_SERVER['HTTPS']??'')!=='on'){throw new Problem('HTTPS required.',400);}
        if(str_starts_with($uri,'/preview/')){(new \DirectWeb\Sites\Preview($a))->serve($uri);return;}
        if(str_starts_with($uri,'/plugin-assets/')){$this->pluginAsset($uri);return;}
        if(str_starts_with($uri,'/assets/')){$this->asset($uri);return;}
        $a->plugins->boot();
        if($a->plugins->dispatch($uri,$method,null,false)){return;}
        if($uri==='/'){$this->redirect('/admin');return;}
        $a->auth->start();$this->user=$a->auth->current();$a->translator->use($a->setting('locale','en'));foreach($a->plugins->all() as $plugin){if($plugin['enabled']){$a->translator->addDirectory($a->pluginRoot().'/'.$plugin['id'].'/locales');}}
        $this->headers();
        if($a->plugins->dispatch($uri,$method,$this->user,true)){return;}
        if($uri==='/.well-known/lumui'){$this->json($a->publicDocument(['lumui_discovery'=>'1.0','id'=>'directweb','descriptor'=>['href'=>'/admin/descriptor','type'=>'application/lumui+json'],'fallback'=>['href'=>'/admin','type'=>'text/html']]));return;}
        if($uri==='/admin/descriptor'){if(!$this->user){throw new Problem('Sign in required.',401);}$descriptor=$a->publicDocument(\DirectWeb\Ui\Publisher::description());(new \Lumui\Protocol\DescriptorValidator())->validate($descriptor);$this->json($descriptor,'application/lumui+json');return;}
        if($uri==='/admin/actions'){
            if($method!=='POST'){throw new Problem('Use POST.',405);}
            if(str_contains($_SERVER['CONTENT_TYPE']??'','json')){$this->lumuiAction();return;}
            $a->auth->csrf((string)($_POST['_csrf']??''));
            try{$target=$this->action((string)($_POST['_action']??''),$_POST);$_SESSION['flash']??='Saved.';$this->redirect($target);}
            catch(Problem $e){$_SESSION['flash']=$this->message($e);$_SESSION['flash_kind']='error';$this->redirect($this->returnPath((string)($_POST['_return']??'/admin')));}return;
        }
        if($uri==='/admin/preview'){$u=$this->requireUser();$url=(new \DirectWeb\Sites\Preview($a))->url($u,(string)($_GET['site']??''),(string)($_GET['release']??''));$this->redirect($url);return;}
        if(preg_match('~^/admin/backup/([a-f0-9]{32})$~D',$uri,$m)){$u=$this->requireUser();$a->auth->requireOwner($u);$a->backups->verify($m[1]);$this->download($a->storage('backups/'.$m[1].'.zip'),'directweb-backup-'.$m[1].'.zip');return;}
        if(!in_array($method,['GET','HEAD'],true)){throw new Problem('Method not allowed.',405);}
        if(!str_starts_with($uri,'/admin')){throw new Problem('Page not found.',404);}
        $route=trim(substr($uri,6),'/');
        if(!$a->auth->installed()){$route='setup';}
        elseif(!$this->user){$route='login';}
        elseif(in_array($route,['login','setup'],true)){$this->redirect('/admin');return;}
        $this->surface((new Pages($a))->page($route,$this->user));
    }
    private function loginTarget(): string
    {
        $u=$this->app->auth->current();if($u){$next=$_SESSION['after_login']??'/admin';unset($_SESSION['after_login']);return $next;}return '/admin';
    }
    public function application(): App {return $this->app;}
    public function message(Problem $error): string {return $this->app->translator->get($error->key,...$error->parameters);}
    private function requireUser(): array {$u=$this->user;if(!$u){throw new Problem('Sign in required.',401);}return $u;}
    public function action(string $action,array $i): string
    {
        $a=$this->app;$wordpress=\DirectWebWordPress\Bridge::action($a,$action,$i);if($wordpress!==null){return $wordpress;}$s=fn(string $k,string $default='')=>isset($i[$k])&&is_scalar($i[$k])?(string)$i[$k]:$default;
        if($action==='setup'){(new \DirectWeb\Hosting\Setup($a))->authorize($s('setup_key'));$a->auth->install($s('name'),$s('username'),$s('password'));(new \DirectWeb\Hosting\Setup($a))->complete();$a->auth->login($s('username'),$s('password'),'',$_SERVER['REMOTE_ADDR']??'');$_SESSION['flash']='Account created.';return '/admin';}
        if($action==='login'){$a->auth->login($s('username'),$s('password'),'',$_SERVER['REMOTE_ADDR']??'');unset($_SESSION['flash']);return $this->loginTarget();}
        if($action==='login.verify'){$a->auth->finish($s('method'),$s('code'));unset($_SESSION['flash']);return $this->loginTarget();}
        if($action==='login.email'){$pending=$a->auth->pending()??throw new Problem('Session expired.',403);$a->auth->sendEmail($pending,'login');$_SESSION['flash']='Code sent.';return '/admin';}
        if($action==='login.cancel'){$a->auth->logout();unset($_SESSION['flash']);return '/admin';}
        if($action==='logout'){$a->auth->logout();unset($_SESSION['flash']);return '/admin';}
        if(isset($a->plugins->publicActions[$action])){return ($a->plugins->publicActions[$action])($this->user,$i);}
        $u=$this->user??throw new Problem('Sign in required.',401);
        if($action==='2fa.enroll'){$a->auth->limit('enroll:'.$u['id']);$secret=$_SESSION['totp_setup']??throw new Problem('Reopen Account.');$codes=$a->auth->enroll($u,$secret,$s('code'),$s('password'),$s('proof'));if($codes){$_SESSION['recovery_codes']=$codes;}unset($_SESSION['totp_setup']);return '/admin/account';}
        if($action==='2fa.email.send'){$a->auth->beginEmail($u,$s('email'),$s('password'),$s('proof'));$_SESSION['flash']='Code sent.';return '/admin/account?security=email';}
        if($action==='2fa.email.verify'){$codes=$a->auth->enrollEmail($u,$s('code'));if($codes){$_SESSION['recovery_codes']=$codes;}return '/admin/account';}
        if($action==='2fa.proof.send'){$a->auth->password($u,$s('password'));$a->auth->sendEmail($u,'proof');$_SESSION['flash']='Code sent.';return '/admin/account';}
        if($action==='2fa.remove'){$a->auth->removeFactor($u,$s('method'),$s('password'),$s('proof'));return '/admin/account';}
        $this->requireUser();
        if(isset($a->plugins->actions[$action])){return ($a->plugins->actions[$action])($u,$i);}
        if($action==='site.create'){$site=$a->sites->create($u,$s('name'),$s('host'));return '/admin/site?id='.$site['id'];}
        if($action==='site.upload'){$upload=self::uploads('website')[0]??throw new Problem('Choose a file.');if($upload['error']!==UPLOAD_ERR_OK||!is_uploaded_file($upload['tmp_name'])){throw new Problem('Upload failed.');}$result=$a->sites->import($u,$s('site'),$upload['tmp_name'],$s('name'),$s('base')?:null,(bool)$a->sites->get($s('site'))['auto_publish']);return '/admin/release?id='.$s('site').'&release='.$result['release_id'];}
        if(in_array($action,['file.save','file.delete'],true)){$c=$a->sites->begin($u,$s('site'),$s('base')?:null);try{$a->sites->apply($u,$c['change_id'],$action==='file.save'?[$s('path')=>$s('content')]:[],$action==='file.delete'?[$s('path')]:[]);$result=$a->sites->commit($u,$c['change_id'],$s('name'),$s('_request',Files::id()),(bool)$a->sites->get($s('site'))['auto_publish']);}catch(\Throwable $e){$a->sites->abort($u,$c['change_id']);throw $e;}return '/admin/release?id='.$s('site').'&release='.$result['release_id'];}
        if($action==='release.restore'){$a->sites->restore($u,$s('site'),$s('release'),(int)$s('revision'));return '/admin/site?id='.$s('site');}
        if($action==='site.configure'){$a->sites->configure($u,$s('site'),$i,(int)$s('revision'));return '/admin/site?id='.$s('site');}
        if($action==='release.pin'){$a->sites->pin($u,$s('site'),$s('release'),!empty($i['pinned']));return '/admin/site?id='.$s('site');}
        if($action==='release.prune'){$a->sites->prune($u,$s('site'));return '/admin/site?id='.$s('site');}
        if($action==='sessions.revoke'){$a->db->execute('DELETE FROM sessions WHERE user_id=? AND id<>?',[$u['id'],hash('sha256',session_id())]);$a->audit($u['id'],'sessions.revoked');return '/admin/account';}
        if($action==='password.change'){
            if(!password_verify($s('current'),$u['password'])||strlen($s('password'))<12||strlen($s('password'))>1024){throw new Problem('Check the password (12+ characters).');}$a->db->execute('UPDATE users SET password=?,session_version=session_version+1 WHERE id=?',[password_hash($s('password'),PASSWORD_ARGON2ID),$u['id']]);$a->db->execute('DELETE FROM tokens WHERE user_id=?',[$u['id']]);$a->audit($u['id'],'password.changed');$a->auth->logout();return '/admin';
        }
        $a->auth->requireOwner($u);
        if($action==='user.create'){if(!in_array($s('role'),['admin','editor','reader'],true)){throw new Problem('Invalid role.');}$new=$a->auth->create($s('name'),$s('username'),$s('password'),$s('role'));$a->audit($u['id'],'user.created',$new['id']);return '/admin/user?id='.$new['id'];}
        if($action==='user.access'){$target=$a->db->one('SELECT * FROM users WHERE id=?',[$s('user')]);if(!$target||$target['role']==='owner'||!in_array($s('role'),['admin','editor','reader'],true)){throw new Problem('This account cannot be changed.');}$a->db->transaction(function()use($a,$target,$i,$u){if(!empty($i['enabled'])&&!empty($target['email_mfa'])&&empty($target['totp'])&&!$a->mail->ready()){throw new Problem('Email is not configured.');}$a->db->execute('UPDATE users SET enabled=?,role=?,session_version=session_version+1 WHERE id=?',[(int)!empty($i['enabled']),$i['role'],$target['id']]);$a->db->execute('DELETE FROM user_sites WHERE user_id=?',[$target['id']]);$a->db->execute('DELETE FROM tokens WHERE user_id=?',[$target['id']]);foreach($a->sites->list($u) as $site){if(!empty($i['site_'.$site['id']])){$a->db->execute('INSERT INTO user_sites VALUES(?,?)',[$target['id'],$site['id']]);}}$a->audit($u['id'],'user.access',$target['id']);});return '/admin/users';}
        if($action==='plugin.data'){$a->plugins->deleteData($u,$s('plugin'));return '/admin/plugins/detail?id='.rawurlencode($s('plugin'));}
        if($action==='plugin.remove'){$a->plugins->remove($u,$s('plugin'));return '/admin/plugins';}
        if($action==='plugin.toggle'){$a->plugins->toggle($u,$s('plugin'),$s('enabled')==='1');return '/admin/plugins';}
        if($action==='plugin.install'){$file=self::uploads('package')[0]??throw new Problem('Choose a file.');if($file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])){throw new Problem('Upload failed.');}$id=$a->plugins->install($u,$file['tmp_name']);if(!empty($i['activate'])){$a->plugins->toggle($u,$id,true);}return '/admin/plugins/detail?id='.rawurlencode($id);}
        if($action==='renderer.select'){$a->plugins->selectRenderer($u,$s('plugin'));return '/admin/plugins';}
        if($action==='backup.delete'){$a->backups->delete($u,$s('backup'));return '/admin/backups';}
        if($action==='backup.restore'){if($s('confirm')!=='1'){throw new Problem('Confirm restoration.');}$a->auth->authorizeChange($u,$s('password'),$s('proof'));$a->lock(function()use($a,$u,$s){$a->backups->verify($s('backup'));if(!$a->jobs->usesBackup($s('backup'))){$a->jobs->add($u,'backup.restore',null,['backup'=>$s('backup')]);}});$_SESSION['flash']='Restore queued. Sign in again after completion.';return '/admin/backups/jobs';}
        if($action==='backup.import'){$file=self::uploads('backup')[0]??throw new Problem('Choose a file.');if($file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||$file['size']>$a->config['max_upload']){throw new Problem('Upload failed.');}\DirectWeb\Backups\Backups::inspect($file['tmp_name']);$id=Files::id();$target=$a->storage('backups/'.$id.'.zip');if(!move_uploaded_file($file['tmp_name'],$target)){throw new Problem('Upload failed.');}$a->db->execute('INSERT INTO backups VALUES(?,?,?,?)',[$id,filesize($target),hash_file('sha256',$target),gmdate('c')]);return '/admin/backups/detail?id='.$id;}
        if($action==='backup.create'){$a->jobs->add($u,'backup');$_SESSION['flash']='Backup queued.';return '/admin/backups';}
        if($action==='backup.settings'){$a->set('backup_daily',!empty($i['automatic'])?'1':'0');$a->jobs->scheduleBackups();$a->audit($u['id'],'backup.settings');return '/admin/backups';}
        if($action==='settings.save'){$retention=(int)$s('retention');if($retention<2||$retention>1000||!isset(\DirectWeb\Ui\Translator::LANGUAGES[$s('locale')])){throw new Problem('Invalid setting.');}$a->set('locale',$s('locale'));$a->set('retention',(string)$retention);$a->audit($u['id'],'settings.changed');return '/admin/general';}
        throw new Problem('Unknown action.',404);
    }
    private function lumuiAction(): void
    {
        $a=$this->app;$a->auth->csrf($_SERVER['HTTP_X_CSRF_TOKEN']??'');$raw=file_get_contents('php://input',false,null,0,1048577);if(strlen($raw)>1048576){throw new Problem('Message too large.',413);}try{$m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new Problem('Invalid message.');}if(!is_array($m)){throw new Problem('Invalid message.');}
        $entry=$_SESSION['lumui_views'][$m['surface_id']??'']??null;if(!$entry||$entry['expires']<time()){throw new Problem('Page expired.',409);}$view=$entry['surface'];
        $validator=new \Lumui\Protocol\SurfaceValidator($a->root.'/packages/lumui/resources/component-catalog.json',$a->root.'/packages/lumui/resources/schemas/surface.schema.json');
        $publisher=new \Lumui\Http\WebPublisher(new \DirectWeb\Ui\Publisher($a,$view),$validator,$a->plugins->renderer());
        $controller=$this;$handler=new class($controller) implements \Lumui\Contracts\ActionHandler {
            public function __construct(private Controller $controller){}
            public function invoke(array $message,array $surface,array $definition): array
            {
                try{$redirect=$this->controller->action($definition['callback'],$message['input']);return ['status'=>'completed','status_code'=>1000,'redirect'=>$this->controller->application()->url($redirect)];}
                catch(Problem $e){return ['status'=>$e->status===403?'requires_permission':'rejected','status_code'=>$e->status===409?4009:5003,'result'=>['message'=>$this->controller->message($e)]];}
            }
        };
        $store=new \Lumui\Http\FileIdempotencyStore($a->storage('cache/actions/'.hash('sha256',session_id())));$confirm=new \Lumui\Http\HmacConfirmationService(hash_hmac('sha256',session_id(),file_get_contents($a->storage('database/key'))));
        (new \Lumui\Http\ActionDispatcher($publisher,$handler,1048576,$store,$confirm))->handleCurrentRequest();
    }
    public static function uploads(string $name): array
    {
        $f=$_FILES[$name]??null;if(!$f){return [];}if(!is_array($f['name'])){return [$f];}$out=[];foreach($f['name'] as $i=>$n){$out[]=['name'=>$n,'tmp_name'=>$f['tmp_name'][$i],'error'=>$f['error'][$i],'size'=>$f['size'][$i]];}return $out;
    }
    private function website(string $host,string $uri): void
    {
        $a=$this->app;if(str_starts_with($uri,'/admin')){$this->redirect(($a->config['secure']?'https':'http').'://'.$a->config['admin_host'].$a->url('/admin'));return;}
        $s=(new \DirectWeb\Sites\Preview($a))->resolve($host)??$a->db->one('SELECT * FROM sites WHERE host=?',[$host]);if(!$s){throw new Problem('Website not found.',404);}$this->serveSite($s,$uri);
    }
    public function serveSite(array $s,string $uri): void
    {
        $a=$this->app;if(!$s['enabled']||!$s['active_release']){throw new Problem('Website unavailable.',503);}
        $a->plugins->boot();foreach($a->plugins->siteRoutes as $handler){if($handler($s,$uri)){return;}}
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){throw new Problem('Method not allowed.',405);}
        $rel=rawurldecode(ltrim($uri,'/'));if($rel!==''){Files::path(rtrim($rel,'/'));}$m=$a->sites->readManifest($s['id'],$s['active_release']);$candidates=$rel===''?[$m['start']]:[$rel,rtrim($rel,'/').'/index.html',rtrim($rel,'/').'/index.htm',rtrim($rel,'/').'.html'];$file=null;foreach($candidates as $candidate){if(isset($m['files'][$candidate])){$file=$candidate;break;}}
        if(!$file&&$s['spa']&&pathinfo($rel,PATHINFO_EXTENSION)===''){$file=$m['start'];}if(!$file){if(isset($m['files']['404.html'])){$file='404.html';http_response_code(404);}else{throw new Problem('Page not found.',404);}}
        $entry=$m['files'][$file];$etag='"'.$entry['hash'].'"';header('Content-Type: '.$entry['type']);header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');header("Content-Security-Policy: worker-src 'none'; object-src 'none'");header('ETag: '.$etag);header('Cache-Control: '.(!empty($s['_preview'])?'no-store':'no-cache'));if(!empty($s['_preview'])){header('X-Robots-Tag: noindex, nofollow, noarchive');header('Referrer-Policy: no-referrer');}
        if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);return;}header('Content-Length: '.$entry['size']);if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){readfile($a->sites->dir($s['id'],$s['active_release']).'/files/'.$file);}
    }
    private function pluginAsset(string $uri): void
    {
        if(!preg_match('~^/plugin-assets/([a-z][a-z0-9-]{1,40})/(.+)$~D',$uri,$m)||!$this->app->plugins->enabled($m[1])){throw new Problem('Not found.',404);}Files::path($m[2]);$base=realpath($this->app->pluginRoot().'/'.$m[1].'/assets');$file=realpath($this->app->pluginRoot().'/'.$m[1].'/assets/'.$m[2]);$types=['css'=>'text/css','js'=>'text/javascript','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2'];$ext=strtolower(pathinfo($m[2],PATHINFO_EXTENSION));if(!$base||!$file||!is_file($file)||!str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',$base).'/')||!isset($types[$ext])){throw new Problem('Not found.',404);}header('Content-Type: '.$types[$ext]);header('Cache-Control: public,max-age=300');header('X-Content-Type-Options: nosniff');readfile($file);
    }
    private function asset(string $uri): void
    {
        if(!preg_match('~^/assets/(?:(?:directweb\.(?:css|js)|qrcodegen\.js|secrets\.js)|icons/[a-z0-9-]+\.svg)$~D',$uri)){throw new Problem('Not found.',404);}$file=$this->app->root.'/public'.$uri;if(!is_file($file)){throw new Problem('Not found.',404);}header('Content-Type: '.match(pathinfo($file,PATHINFO_EXTENSION)){'css'=>'text/css','js'=>'text/javascript','svg'=>'image/svg+xml'});header('Cache-Control: public,max-age=3600');header('X-Content-Type-Options: nosniff');readfile($file);
    }
    private function surface(array $surface): void {(new \DirectWeb\Ui\Output($this->app))->send($surface);}
    private function headers(): void {header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: SAMEORIGIN');header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'self'; worker-src 'none'");if($this->app->config['secure']){header('Strict-Transport-Security: max-age=31536000');}}
    private function download(string $file,string $name): void {header('Content-Type: application/octet-stream');header("Content-Disposition: attachment; filename=\"download\"; filename*=UTF-8''".rawurlencode($name));header('Content-Length: '.filesize($file));header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox');readfile($file);}
    private function json(array $value,string $type='application/json'): void {header('Content-Type: '.$type);echo Files::json($value);}
    private function returnPath(string $p): string {return str_starts_with($p,'/')&&!str_starts_with($p,'//')&&!preg_match('~[\\\\\x00-\x20\x7f]~',$p)?$p:'/admin';}
    private function redirect(string $url): void {header('Location: '.$this->app->url($url),true,303);}
    private function error(string $message,int $status): void
    {
        $this->headers();$p=new Surface($this->app,'DirectWeb','system.error',$this->user);$p->section('Error',[$p->node('alert',['title'=>(string)$status,'message'=>$message,'severity'=>'error','metadata'=>['literal'=>true]]),$p->link('Dashboard','/admin','house')]);(new \DirectWeb\Ui\Output($this->app))->send($p->build($this->user!==null),$status);
    }
}
