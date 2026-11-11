<?php
declare(strict_types=1);
require_once __DIR__.'/Uploads.php';
require_once __DIR__.'/Server.php';
require_once __DIR__.'/Updater.php';
require_once __DIR__.'/OAuth.php';
require_once __DIR__.'/Pages.php';
return new class implements DirectWeb\Plugins\Plugin {
    public function register(DirectWeb\Core\App $app,DirectWeb\Plugins\Registry $registry): void
    {
        $server=new DirectWebMcp\Server($app);$oauth=new DirectWebMcp\OAuth($app);$registry->routes['mcp']=fn()=>$server->http();$registry->routes['oauth']=$oauth;
        $registry->route('mcp.http','~^/mcp$~D',['GET','POST','DELETE'],function()use($server){$server->http();},false);
        $registry->route('mcp.connection','~^/mcp/connect(?:/([^/]*))?$~D',['GET','POST','DELETE'],function($m)use($server){$server->http($m[1]??'');},false);
        $registry->route('mcp.upload','~^/mcp/upload/([A-Za-z0-9_-]+\.[a-f0-9]{64})$~D',['GET','POST'],function($m)use($app){(new DirectWebMcp\Uploads($app))->http($m[1]);},false);
        $registry->route('mcp.oauth','~^/(?:oauth/|\.well-known/oauth-)~',['GET','POST'],function($m,$u,$uri)use($oauth){$oauth->http($uri);},false);
        $registry->tasks['mcp.cleanup']=function()use($app){
            $app->db->execute('DELETE FROM tokens WHERE id IN (SELECT token_id FROM oauth_refresh WHERE expires_at<?)',[time()]);
            $app->db->execute('DELETE FROM oauth_refresh WHERE expires_at<?',[time()]);
            $app->db->execute('DELETE FROM oauth_codes WHERE expires_at<?',[time()]);
            $app->db->execute('DELETE FROM mcp_approvals WHERE token_id NOT IN (SELECT id FROM tokens)');
            $app->db->execute('DELETE FROM mcp_requests WHERE created_at<? OR token_id NOT IN (SELECT id FROM tokens)',[time()-604800]);
        };
        $pages=new DirectWebMcp\Pages($app);$registry->addPage('mcp','MCP','code',fn($u)=>$pages->page($u,'home'),['group'=>'system']);
        foreach(['connection','access','access/new','access/token','access/duration','access/replace','access/revoke','requests','clients','clients/new','clients/delete'] as $view){$registry->pages['mcp/'.$view]=fn($u)=>$pages->page($u,$view);}
        $registry->addAction('mcp.authorize',fn(array $u,array $i)=>$oauth->authorize($u,$i));
        $registry->addAction('mcp.issue',function(array $u,array $i)use($app){$scopes=\DirectWeb\Identity\Auth::normalize(self::rights($app,$i));if(!$scopes){throw new \DirectWeb\Core\Problem('Choose at least one right.');}$write=(bool)array_filter($scopes,fn($scope)=>str_starts_with($scope,'write@'));foreach($app->plugins->tools as $name=>$tool){if(!empty($i['tool_'.$name])&&(!$tool['write']||$write)){$scopes[]=$tool['scope'];}}$hours=self::hours($i);$approval=self::approval($app,$u,$i,$scopes,$hours);$token=$app->auth->issue($u,(string)($i['name']??''),[],$scopes,$hours);$id=$app->db->one('SELECT id FROM tokens WHERE hash=?',[hash('sha256',$token)])['id'];if($approval){self::store($app,$id,$approval);}return '/admin/mcp/access/token?id='.$id;});
        $registry->route('mcp.secret','~^/mcp/(token|connection-url)$~D',['POST'],function($m,$u)use($app){
            if(!$u){throw new \DirectWeb\Core\Problem('Sign in required.',401);}
            $app->auth->csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
            $input=json_decode(file_get_contents('php://input',false,null,0,2048),true);
            if(!is_array($input)||!is_string($input['id']??null)){throw new \DirectWeb\Core\Problem('Invalid request.');}
            $value=$app->auth->tokenValue($u,$input['id']);
            if($m[1]==='connection-url'){$value=($app->config['secure']?'https':'http').'://'.$app->config['admin_host'].$app->url('/mcp/connect/').rawurlencode($value);}
            header('Cache-Control: no-store');return ['value'=>$value];
        });
        $registry->addAction('mcp.duration',function(array $u,array $i)use($app){$id=(string)($i['token']??'');$token=$app->auth->ownedToken($u,$id);$hours=self::hours($i);$approval=self::approval($app,$u,$i,json_decode($token['scopes'],true)?:[],$hours);$app->auth->tokenDuration($u,$id,$hours);if($approval){self::store($app,$id,$approval);}else{$app->db->execute('DELETE FROM mcp_approvals WHERE token_id=?',[$id]);}$app->audit($u['id'],'token.approval.changed',$id,['interval'=>$approval['interval']??0]);return '/admin/mcp/access/token?id='.$id;});
        $registry->addAction('mcp.request.approve',function(array $u,array $i)use($app,$server){$r=$app->db->one("SELECT * FROM mcp_requests WHERE id=? AND user_id=? AND status='pending'",[(string)($i['request']??''),$u['id']])??throw new \DirectWeb\Core\Problem('Request missing.',404);$server->grant($r['token_id'],$r['ip']);$app->audit($u['id'],'mcp.approved.panel',$r['token_id'],['ip'=>$r['ip']]);return '/admin/mcp/requests';});
        $registry->addAction('mcp.request.deny',function(array $u,array $i)use($app){$r=$app->db->one("SELECT * FROM mcp_requests WHERE id=? AND user_id=? AND status='pending'",[(string)($i['request']??''),$u['id']])??throw new \DirectWeb\Core\Problem('Request missing.',404);$app->db->execute("UPDATE mcp_requests SET status='denied',decided_at=? WHERE id=?",[time(),$r['id']]);$app->audit($u['id'],'mcp.denied.panel',$r['token_id'],['ip'=>$r['ip']]);return '/admin/mcp/requests';});
        $registry->addAction('mcp.replace',function(array $u,array $i)use($app){$id=(string)($i['token']??'');$app->auth->replaceToken($u,$id);return '/admin/mcp/access/token?id='.$id;});
        $registry->addAction('mcp.revoke',function(array $u,array $i)use($app){$app->db->execute('DELETE FROM tokens WHERE id=? AND user_id=?',[(string)($i['token']??''),$u['id']]);$app->audit($u['id'],'token.revoked');return '/admin/mcp';});
        $registry->addAction('mcp.client.delete',function($u,$i)use($app){$app->auth->requireOwner($u);$app->db->execute('DELETE FROM tokens WHERE id IN (SELECT token_id FROM oauth_refresh WHERE client_id=?)',[(string)($i['client']??'')]);$app->db->execute('DELETE FROM oauth_refresh WHERE client_id=?',[(string)($i['client']??'')]);$app->db->execute('DELETE FROM oauth_codes WHERE client_id=?',[(string)($i['client']??'')]);$app->db->execute('DELETE FROM oauth_clients WHERE id=?',[(string)($i['client']??'')]);$app->audit($u['id'],'oauth.client.deleted');return '/admin/mcp/clients';});
        $registry->addAction('mcp.client',function(array $u,array $i)use($app,$oauth){$app->auth->requireOwner($u);$oauth->register((string)($i['name']??''),(string)($i['redirect']??''));$app->audit($u['id'],'oauth.client');return '/admin/mcp';});
    }
    private static function hours(array $input): int
    {
        $hours=filter_var($input['duration']??1,FILTER_VALIDATE_INT);
        if($hours===false||($hours!==0&&!in_array($hours,\DirectWeb\Identity\Auth::TOKEN_HOURS,true))){throw new \DirectWeb\Core\Problem('Invalid duration.');}return $hours;
    }
    private static function rights(\DirectWeb\Core\App $app,array $i): array
    {
        $out=[];
        foreach($i as $key=>$value){
            if(empty($value)||!preg_match('/^r_(all|directweb|[a-f0-9]{32})_(read|write|execute)$/D',(string)$key,$m)){continue;}
            if($m[1]==='directweb'){if($m[2]==='read'){$out[]='admin.read';}elseif($m[2]==='write'){$out[]='admin.manage';}elseif(empty($app->config['wordpress'])){$out[]='admin.update';}continue;}
            $out[]=$m[2].'@'.($m[1]==='all'?'*':$m[1]);
        }
        return $out;
    }
    private static function approval(\DirectWeb\Core\App $app,array $u,array $i,array $scopes,int $hours): ?array
    {
        $interval=filter_var($i['approval']??0,FILTER_VALIDATE_INT);if($interval===false||($interval!==0&&!isset(\DirectWebMcp\Pages::INTERVALS[$interval]))){throw new \DirectWeb\Core\Problem('Invalid request.');}
        if($interval===0&&($hours===0||\DirectWeb\Identity\Auth::elevated($scopes))){$interval=86400;}
        if($interval===0){return null;}self::authenticator($app,$u);return ['interval'=>$interval,'bind'=>!empty($i['bind_ip'])?1:0];
    }
    private static function store(\DirectWeb\Core\App $app,string $token,array $approval): void
    {
        $app->db->execute("INSERT INTO mcp_approvals(token_id,approved_until,interval_seconds,bind_ip,approved_ip) VALUES(?,0,?,?,'') ON CONFLICT(token_id) DO UPDATE SET approved_until=0,interval_seconds=excluded.interval_seconds,bind_ip=excluded.bind_ip,approved_ip=''",[$token,$approval['interval'],$approval['bind']]);
    }
    private static function authenticator(\DirectWeb\Core\App $app,array $u): void
    {
        if(empty($app->db->one('SELECT totp FROM users WHERE id=?',[$u['id']])['totp'])){throw new \DirectWeb\Core\Problem('Set up the authenticator app under Account first.');}
    }
};
