<?php
declare(strict_types=1);
require_once __DIR__.'/Server.php';
require_once __DIR__.'/OAuth.php';
require_once __DIR__.'/Pages.php';
return new class implements DirectWeb\Plugins\Plugin {
    public function register(DirectWeb\Core\App $app,DirectWeb\Plugins\Registry $registry): void
    {
        $server=new DirectWebMcp\Server($app);$oauth=new DirectWebMcp\OAuth($app);$registry->routes['mcp']=fn()=>$server->http();$registry->routes['oauth']=$oauth;
        $registry->route('mcp.http','~^/mcp$~D',['GET','POST','DELETE'],function()use($server){$server->http();},false);
        $registry->route('mcp.connection','~^/mcp/connect(?:/([^/]*))?$~D',['GET','POST','DELETE'],function($m)use($server){$server->http($m[1]??'');},false);
        $registry->route('mcp.oauth','~^/(?:oauth/|\.well-known/oauth-)~',['GET','POST'],function($m,$u,$uri)use($oauth){$oauth->http($uri);},false);
        $registry->tasks['mcp.cleanup']=function()use($app){
            $app->db->execute('DELETE FROM tokens WHERE id IN (SELECT token_id FROM oauth_refresh WHERE expires_at<?)',[time()]);
            $app->db->execute('DELETE FROM oauth_refresh WHERE expires_at<?',[time()]);
            $app->db->execute('DELETE FROM oauth_codes WHERE expires_at<?',[time()]);
        };
        $pages=new DirectWebMcp\Pages($app);$registry->addPage('mcp','MCP','code',fn($u)=>$pages->page($u,'home'),['group'=>'system']);
        foreach(['connection','access','access/new','access/token','access/duration','access/replace','access/revoke','clients','clients/new','clients/delete'] as $view){$registry->pages['mcp/'.$view]=fn($u)=>$pages->page($u,$view);}
        $registry->addAction('mcp.authorize',fn(array $u,array $i)=>$oauth->authorize($u,$i));
        $registry->addAction('mcp.issue',function(array $u,array $i)use($app){$scopes=!empty($i['write'])?['read','write']:['read'];foreach($app->plugins->tools as $name=>$tool){if(!empty($i['tool_'.$name])&&(!$tool['write']||!empty($i['write']))){$scopes[]=$tool['scope'];}}$token=$app->auth->issue($u,(string)($i['name']??''),[(string)($i['site']??'')],$scopes,self::days($i));$id=$app->db->one('SELECT id FROM tokens WHERE hash=?',[hash('sha256',$token)])['id'];return '/admin/mcp/access/token?id='.$id;});
        $registry->route('mcp.secret','~^/mcp/(token|connection-url)$~D',['POST'],function($m,$u)use($app){
            if(!$u){throw new \DirectWeb\Core\Problem('Sign in required.',401);}
            $app->auth->csrf((string)($_SERVER['HTTP_X_CSRF_TOKEN']??''));
            $input=json_decode(file_get_contents('php://input',false,null,0,2048),true);
            if(!is_array($input)||!is_string($input['id']??null)){throw new \DirectWeb\Core\Problem('Invalid request.');}
            $value=$app->auth->tokenValue($u,$input['id']);
            if($m[1]==='connection-url'){$value=($app->config['secure']?'https':'http').'://'.$app->config['admin_host'].$app->url('/mcp/connect/').rawurlencode($value);}
            header('Cache-Control: no-store');return ['value'=>$value];
        });
        $registry->addAction('mcp.duration',function(array $u,array $i)use($app){$id=(string)($i['token']??'');$app->auth->tokenDuration($u,$id,self::days($i));return '/admin/mcp/access/token?id='.$id;});
        $registry->addAction('mcp.replace',function(array $u,array $i)use($app){$id=(string)($i['token']??'');$app->auth->replaceToken($u,$id);return '/admin/mcp/access/token?id='.$id;});
        $registry->addAction('mcp.revoke',function(array $u,array $i)use($app){$app->db->execute('DELETE FROM tokens WHERE id=? AND user_id=?',[(string)($i['token']??''),$u['id']]);$app->audit($u['id'],'token.revoked');return '/admin/mcp';});
        $registry->addAction('mcp.client.delete',function($u,$i)use($app){$app->auth->requireOwner($u);$app->db->execute('DELETE FROM tokens WHERE id IN (SELECT token_id FROM oauth_refresh WHERE client_id=?)',[(string)($i['client']??'')]);$app->db->execute('DELETE FROM oauth_refresh WHERE client_id=?',[(string)($i['client']??'')]);$app->db->execute('DELETE FROM oauth_codes WHERE client_id=?',[(string)($i['client']??'')]);$app->db->execute('DELETE FROM oauth_clients WHERE id=?',[(string)($i['client']??'')]);$app->audit($u['id'],'oauth.client.deleted');return '/admin/mcp/clients';});
        $registry->addAction('mcp.client',function(array $u,array $i)use($app,$oauth){$app->auth->requireOwner($u);$oauth->register((string)($i['name']??''),(string)($i['redirect']??''));$app->audit($u['id'],'oauth.client');return '/admin/mcp';});
    }
    private static function days(array $input): int
    {
        if(!empty($input['never'])){return 0;}
        $days=filter_var($input['days']??30,FILTER_VALIDATE_INT);
        if($days===false||$days<1){throw new \DirectWeb\Core\Problem('Invalid duration.');}return $days;
    }
};
