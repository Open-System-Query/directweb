<?php
declare(strict_types=1);
namespace DirectWebMcp;
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Ui\Surface;
final class OAuth
{
    public function __construct(private App $app){}
    public function base(): string {return ($this->app->config['secure']?'https':'http').'://'.$this->app->config['admin_host'].($this->app->config['base_path']??'');}
    public function register(string $name,string $uri): string
    {
        $p=parse_url($uri);if(trim($name)===''||strlen($name)>100||strlen($uri)>2048||preg_match('/[\x00-\x20\x7f]/',$uri)||!$p||isset($p['fragment'],$p['user'])||isset($p['user'])||isset($p['pass'])||!isset($p['host'])||!in_array($p['scheme']??'',['http','https'],true)||($p['scheme']==='http'&&!in_array($p['host'],['127.0.0.1','localhost','[::1]'],true))){throw new Problem('Invalid redirect URL.');}
        if(isset($p['fragment'])){throw new Problem('Invalid redirect URL.');}$id=Files::id();$this->app->db->execute('INSERT INTO oauth_clients VALUES(?,?,?)',[$id,$name,$uri]);return $id;
    }
    public function http(string $path): void
    {
        header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
        if($path==='/.well-known/oauth-protected-resource/mcp'){$this->json(['resource'=>$this->base().'/mcp','authorization_servers'=>[$this->base()],'scopes_supported'=>array_merge(['read','write'],array_column($this->app->plugins->tools,'scope')),'bearer_methods_supported'=>['header']]);return;}
        if($path==='/.well-known/oauth-authorization-server'){$this->json(['issuer'=>$this->base(),'authorization_endpoint'=>$this->base().'/oauth/authorize','token_endpoint'=>$this->base().'/oauth/token','response_types_supported'=>['code'],'registration_endpoint'=>$this->base().'/oauth/register','grant_types_supported'=>['authorization_code','refresh_token'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],'scopes_supported'=>array_merge(['read','write'],array_column($this->app->plugins->tools,'scope')),'authorization_response_iss_parameter_supported'=>true]);return;}
        if($path==='/oauth/register'){$this->registrationHttp();return;}
        if($path==='/oauth/token'){$this->token();return;}
        if($path!=='/oauth/authorize'||($_SERVER['REQUEST_METHOD']??'')!=='GET'){throw new Problem('Not found.',404);}
        $q=$_GET;$client=$this->app->db->one('SELECT * FROM oauth_clients WHERE id=?',[(string)($q['client_id']??'')]);
        if(!$client||(($q['redirect_uri']??'')!==$client['redirect_uri']&&!$this->app->db->one('SELECT uri FROM oauth_client_redirects WHERE client_id=? AND uri=?',[$client['id'],(string)($q['redirect_uri']??'')]))){throw new Problem('Unknown OAuth client.');}
        $client['redirect_uri']=$q['redirect_uri'];
        if(($q['response_type']??'')!=='code'||($q['code_challenge_method']??'')!=='S256'||!preg_match('/^[A-Za-z0-9_-]{43}$/D',(string)($q['code_challenge']??''))||($q['resource']??'')!==$this->base().'/mcp'){throw new Problem('Invalid OAuth request.');}
        $scopes=array_values(array_unique(explode(' ',trim((string)($q['scope']??'read write')))));if(array_diff($scopes,array_merge(['read','write'],array_column($this->app->plugins->tools,'scope')))||!in_array('read',$scopes,true)||strlen((string)($q['state']??''))>1024){throw new Problem('Invalid permissions.');}
        foreach($this->app->plugins->tools as $tool){if($tool['write']&&in_array($tool['scope'],$scopes,true)&&!in_array('write',$scopes,true)){throw new Problem('Invalid permissions.');}}
        $this->app->auth->start();$u=$this->app->auth->current();if(!$u){$_SESSION['after_login']='/oauth/authorize?'.http_build_query($q,'','&',PHP_QUERY_RFC3986);header('Location: '.$this->app->url('/admin'),true,303);return;}
        $pending=Files::id();$_SESSION['oauth_pending'][$pending]=['client'=>$client,'challenge'=>$q['code_challenge'],'scopes'=>$scopes,'state'=>(string)($q['state']??''),'expires'=>time()+600];
        $sites=$this->app->sites->list($u);if(!$sites){throw new Problem('No websites available.',403);}$p=new Surface($this->app,'Grant access','oauth.authorize',$u);$p->section($client['name'],[$p->text(in_array('write',$scopes,true)?'Read and publish websites.':'Read websites.'),$p->node('codeBlock',['text'=>implode("\n",$scopes)]),$p->form('mcp.authorize','',['site'=>['label'=>'Website','kind'=>'choice','value'=>$sites[0]['id'],'options'=>array_map(fn($s)=>['value'=>$s['id'],'label'=>$s['name'],'literal'=>true],$sites)]],['pending'=>$pending],'Allow',true),$p->link('Cancel','/admin')],['literal'=>true]);
        header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self' ".parse_url($client['redirect_uri'],PHP_URL_SCHEME).'://'.parse_url($client['redirect_uri'],PHP_URL_HOST).(parse_url($client['redirect_uri'],PHP_URL_PORT)?':'.parse_url($client['redirect_uri'],PHP_URL_PORT):''));(new \DirectWeb\Ui\Output($this->app))->send($p->build());
    }
    public function authorize(array $u,array $i): string
    {
        $key=(string)($i['pending']??'');$pending=$_SESSION['oauth_pending'][$key]??null;unset($_SESSION['oauth_pending'][$key]);if(!$pending||$pending['expires']<time()){throw new Problem('Request expired.');}$site=(string)($i['site']??'');$this->app->auth->access($u,$site,in_array('write',$pending['scopes'],true)?'write':'read');
        $code=bin2hex(random_bytes(32));$client=$pending['client'];$this->app->db->execute('INSERT INTO oauth_codes VALUES(?,?,?,?,?,?,?,?,?)',[hash('sha256',$code),$client['id'],$u['id'],$u['session_version'],$client['redirect_uri'],$pending['challenge'],Files::json($pending['scopes']),Files::json([$site]),time()+60]);$this->app->audit($u['id'],'oauth.authorized',$client['id']);
        return $client['redirect_uri'].(str_contains($client['redirect_uri'],'?')?'&':'?').http_build_query(['code'=>$code,'state'=>$pending['state'],'iss'=>$this->base()],'','&',PHP_QUERY_RFC3986);
    }
    public function registration(array $input): array
    {
        if(($input['token_endpoint_auth_method']??'none')!=='none'||array_diff($input['grant_types']??['authorization_code','refresh_token'],['authorization_code','refresh_token'])||($input['response_types']??['code'])!==['code']){throw new Problem('invalid_client_metadata');}
        $redirects=$input['redirect_uris']??[];if(!is_array($redirects)||!$redirects||count($redirects)>5){throw new Problem('invalid_redirect_uri');}
        $name=$input['client_name']??'MCP connector';if(!is_string($name)){throw new Problem('invalid_client_metadata');}
        return $this->app->db->transaction(function()use($redirects,$name){
            if((int)$this->app->db->one('SELECT COUNT(*) AS total FROM oauth_clients')['total']>=200){throw new Problem('Registration limit reached.',429);}
            $id=null;
            foreach(array_unique($redirects) as $uri){if(!is_string($uri)){throw new Problem('invalid_redirect_uri');}$candidate=$this->register($name,$uri);if($id===null){$id=$candidate;}else{$this->app->db->execute('DELETE FROM oauth_clients WHERE id=?',[$candidate]);}$this->app->db->execute('INSERT INTO oauth_client_redirects VALUES(?,?)',[$id,$uri]);}
            return ['client_id'=>$id,'client_name'=>$name,'redirect_uris'=>array_values(array_unique($redirects)),'grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code'],'token_endpoint_auth_method'=>'none','client_id_issued_at'=>time()];
        });
    }
    private function registrationHttp(): void
    {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);$this->json(['error'=>'invalid_request']);return;}
        try{
            $this->app->auth->limit('oauth-register:'.hash('sha256',$_SERVER['REMOTE_ADDR']??''),10,3600);
            if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')){throw new Problem('invalid_client_metadata');}
            $raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192){throw new Problem('invalid_client_metadata');}
            $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input)){throw new Problem('invalid_client_metadata');}
            $result=$this->registration($input);http_response_code(201);$this->json($result);
        }catch(\Throwable $e){http_response_code($e instanceof Problem&&$e->status===429?429:400);$this->json(['error'=>'invalid_client_metadata']);}
    }
    private function grant(array $u,string $client,array $scopes,array $sites,?string $family=null,?int $expires=null): array
    {
        $token='dw_'.bin2hex(random_bytes(32));$refresh='dwr_'.bin2hex(random_bytes(32));$id=Files::id();$family??=Files::id();$expires??=time()+7776000;
        $this->app->db->execute('INSERT INTO tokens(id,user_id,name,hash,scopes,sites,expires_at) VALUES(?,?,?,?,?,?,?)',[$id,$u['id'],'OAuth '.$client,hash('sha256',$token),Files::json($scopes),Files::json($sites),time()+3600]);
        $this->app->db->execute('INSERT INTO oauth_refresh(hash,family,client_id,user_id,session_version,scopes,sites,token_id,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',[hash('sha256',$refresh),$family,$client,$u['id'],$u['session_version'],Files::json($scopes),Files::json($sites),$id,$expires]);
        return ['access_token'=>$token,'refresh_token'=>$refresh,'token_type'=>'Bearer','expires_in'=>3600,'scope'=>implode(' ',$scopes)];
    }
    public function exchange(array $i): array
    {
        if(($i['resource']??'')!==$this->base().'/mcp'||!in_array($i['grant_type']??'',['authorization_code','refresh_token'],true)){throw new Problem('invalid_request');}
        $result=$this->app->db->transaction(function()use($i){
            if($i['grant_type']==='refresh_token'){
                $r=$this->app->db->one('SELECT * FROM oauth_refresh WHERE hash=?',[hash('sha256',(string)($i['refresh_token']??''))]);
                if(!$r||$r['client_id']!==($i['client_id']??'')||$r['expires_at']<=time()||!$this->app->db->one('SELECT id FROM oauth_clients WHERE id=?',[$r['client_id']])){throw new Problem('invalid_grant');}
                if($r['used']){
                    $this->app->db->execute('DELETE FROM tokens WHERE id IN (SELECT token_id FROM oauth_refresh WHERE family=?)',[$r['family']]);
                    $this->app->db->execute('DELETE FROM oauth_refresh WHERE family=?',[$r['family']]);return ['error'=>'invalid_grant'];
                }
                if(!$this->app->db->one('SELECT id FROM tokens WHERE id=?',[$r['token_id']])){throw new Problem('invalid_grant');}
                $u=$this->app->auth->user($r['user_id']);if($u['session_version']!==$r['session_version']){throw new Problem('invalid_grant');}
                $scopes=json_decode($r['scopes'],true);if(isset($i['scope'])){$requested=array_values(array_unique(explode(' ',trim($i['scope']))));if(array_diff($requested,$scopes)||!in_array('read',$requested,true)){throw new Problem('invalid_request');}$scopes=$requested;}
                $sites=json_decode($r['sites'],true);foreach($sites as $site){$this->app->auth->access($u,$site,in_array('write',$scopes,true)?'write':'read');}
                $this->app->db->execute('UPDATE oauth_refresh SET used=1 WHERE hash=?',[$r['hash']]);$this->app->db->execute('DELETE FROM tokens WHERE id=?',[$r['token_id']]);
                return $this->grant($u,$r['client_id'],$scopes,$sites,$r['family'],$r['expires_at']);
            }
            if(!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',(string)($i['code_verifier']??''))){throw new Problem('invalid_request');}
            $code=$this->app->db->one('SELECT * FROM oauth_codes WHERE hash=?',[hash('sha256',(string)($i['code']??''))]);$challenge=rtrim(strtr(base64_encode(hash('sha256',$i['code_verifier'],true)),'+/','-_'),'=');
            if(!$code||$code['expires_at']<=time()||($i['client_id']??'')!==$code['client_id']||($i['redirect_uri']??'')!==$code['redirect_uri']||!hash_equals($code['challenge'],$challenge)||!$this->app->db->one('SELECT id FROM oauth_clients WHERE id=?',[$code['client_id']])){throw new Problem('invalid_grant');}
            $u=$this->app->auth->user($code['user_id']);if($u['session_version']!==$code['session_version']){throw new Problem('invalid_grant');}
            $scopes=json_decode($code['scopes'],true);$sites=json_decode($code['sites'],true);foreach($sites as $site){$this->app->auth->access($u,$site,in_array('write',$scopes,true)?'write':'read');}
            $this->app->db->execute('DELETE FROM oauth_codes WHERE hash=?',[$code['hash']]);
            return $this->grant($u,$code['client_id'],$scopes,$sites);
        });
        if(isset($result['error'])){throw new Problem($result['error']);}return $result;
    }
    private function token(): void
    {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);$this->json(['error'=>'invalid_request']);return;}
        if((int)($_SERVER['CONTENT_LENGTH']??0)>8192||!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/x-www-form-urlencoded')){http_response_code(400);$this->json(['error'=>'invalid_request']);return;}
        try{$this->app->auth->limit('oauth-token:'.hash('sha256',$_SERVER['REMOTE_ADDR']??''),30,60);$this->json($this->exchange($_POST));}
        catch(\Throwable $e){http_response_code($e instanceof Problem&&$e->status===429?429:400);$this->json(['error'=>$e instanceof Problem&&in_array($e->key,['invalid_request','invalid_grant'],true)?$e->key:'invalid_grant']);}
    }
    private function json(array $value): void {header('Content-Type: application/json');echo Files::json($value);}
}
