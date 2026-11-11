<?php
declare(strict_types=1);
namespace DirectWebMcp;
use DirectWeb\Core\{App,Files,Problem};
final class Server
{
    public function __construct(private App $app){}
    public function tools(): array
    {
        $str=['type'=>'string'];$site=['site_id'=>$str];$release=$site+['release_id'=>$str];$change=['change_id'=>$str];
        $defs=[
            ['sites_list','List websites',[],[]],
            ['site_get','Get website',$site,['site_id']],
            ['files_list','List files',$release+['offset'=>['type'=>'integer','minimum'=>0],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200]],['site_id','release_id']],
            ['file_read','Read text file',$release+['path'=>$str],['site_id','release_id','path']],
            ['changes_create','Start change',$site+['base_release'=>['type'=>['string','null']]],['site_id','base_release']],
            ['changes_apply','Update files',$change+['files'=>['type'=>'object','additionalProperties'=>$str],'delete'=>['type'=>'array','items'=>$str]],['change_id']],
            ['changes_upload','Add small images or other binary files to an open change. files maps each path to base64 content; urls maps each path to a public HTTPS URL that the server downloads. A file with the same path replaces the existing one. For larger local files use changes_upload_link and upload them yourself with curl; for text files use changes_apply.',$change+['files'=>['type'=>'object','additionalProperties'=>$str],'urls'=>['type'=>'object','additionalProperties'=>$str]],['change_id']],
            ['changes_upload_link','Get a private upload URL for an open change. If you can run shell commands and the files are on this computer, upload them yourself with the returned upload_command (curl, any size, many files at once) instead of asking the user. Otherwise share the URL so the user can upload from their device without the hosting panel. Names are kept and same-name files are replaced. Call changes_validate afterwards.',$change+['folder'=>$str,'expires_in'=>['type'=>'integer','minimum'=>300,'maximum'=>86400,'default'=>3600]],['change_id']],
            ['changes_validate','Validate change',$change,['change_id']],
            ['changes_commit','Save a draft. Does not publish. After saving, get a preview URL for the user to test before offering publication.',$change+['name'=>$str,'request_id'=>['type'=>'string','minLength'=>16,'maxLength'=>100]],['change_id','request_id']],
            ['changes_abort','Discard draft',$change,['change_id']],
            ['jobs_get','Get own job status',['job_id'=>$str],['job_id']],
            ['releases_list','List releases',$site,['site_id']],
            ['release_preview','Get a shareable preview URL without publishing',$release+['expires_in'=>['type'=>'integer','minimum'=>60,'default'=>86400]],['site_id','release_id']],
            ['release_publish','Publish a saved release only when the user asks to make it live',$release+['revision'=>['type'=>'integer','minimum'=>1]],['site_id','release_id','revision']],
            ['release_restore','Restore a previous live release only when the user asks',$release+['revision'=>['type'=>'integer','minimum'=>1]],['site_id','release_id','revision']],
            ['release_pin','Pin or unpin a release so retention keeps it',$release+['pinned'=>['type'=>'boolean']],['site_id','release_id','pinned']],
            ['releases_prune','Delete old releases beyond the retention setting. Live, pinned and open-draft bases are kept. Only when the user asks.',$site,['site_id']],
            ['site_create','Create a website for a domain (owner only). The new website is added to this connection. runtime static is served by DirectWeb; runtime server publishes the files into a folder the web server runs directly, so it can be PHP or anything else the hosting supports.',['name'=>$str,'host'=>['type'=>'string','description'=>'Domain, for example example.com'],'runtime'=>['type'=>'string','enum'=>['static','server']]],['name','host']],
            ['site_update','Change website settings. Only pass the fields to change: name, host (domain, owner only), runtime (static or server, owner only), enabled, auto_publish (publish uploads immediately), spa (route unknown paths to index.html). Read site_get for the current revision first.',$site+['revision'=>['type'=>'integer','minimum'=>1],'name'=>$str,'host'=>$str,'runtime'=>['type'=>'string','enum'=>['static','server']],'enabled'=>['type'=>'boolean'],'auto_publish'=>['type'=>'boolean'],'spa'=>['type'=>'boolean']],['site_id','revision']],
            ['site_delete','Permanently delete a website with all releases and files (owner only). Only when the user explicitly asks. confirm_name must equal the website name. Read site_get for the current revision first.',$site+['revision'=>['type'=>'integer','minimum'=>1],'confirm_name'=>$str],['site_id','revision','confirm_name']],
            ['mcp_approve','Approve changes for this connection with the current 6-digit code from the user\'s authenticator app. Only needed when a tool reports that approval is required. Ask the user for the code; never guess it. The user can also approve the request in DirectWeb instead. How long an approval lasts is set per connection.',['code'=>['type'=>'string','pattern'=>'^[0-9]{6}$']],['code']],
        ];
        $defs[]=['directweb_info','Show the DirectWeb version, edition and PHP version, and every website with its domain, type and whether it is live. Needs the DirectWeb read right.',[],[]];
        if(empty($this->app->config['wordpress'])){
            $defs[]=['directweb_update','Update DirectWeb itself. Needs administration access with the update right. Pass the new webserver package as package (base64 ZIP) or url (HTTPS link to the ZIP). DirectWeb checks the package, keeps a backup of the current version and restores it automatically when the update fails. Storage, websites and accounts are not touched. Only when the user asks.',['package'=>$str,'url'=>$str],[]];
        }
        if(!empty($this->app->config['wordpress'])){
            $defs[]=['wordpress_homepage_get','Show which website replaces the WordPress front end on this domain, and which websites can or cannot be chosen and why',[],[]];
            $defs[]=['wordpress_homepage_set','Choose the website shown on the WordPress domain. Pass an empty site_id to show WordPress again. The website must be enabled, published and use the WordPress domain. Only when the user asks.',$site,['site_id']];
        }
        $out=[];foreach($defs as [$name,$description,$properties,$required]){$read=in_array($name,['sites_list','site_get','files_list','file_read','changes_validate','releases_list','jobs_get','release_preview','wordpress_homepage_get','directweb_info'],true);$out[]=['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>$properties?:new \stdClass(),'required'=>$required,'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>in_array($name,['changes_abort','release_publish','release_restore','releases_prune','site_update','site_delete','wordpress_homepage_set'],true),'idempotentHint'=>$read||in_array($name,['changes_commit','release_pin','wordpress_homepage_set'],true),'openWorldHint'=>false]];}foreach($this->app->plugins->tools as $tool){$out[]=$tool['definition'];}return $out;
    }
    public function invoke(array $u,string $tool,array $i): array
    {
        $definition=null;foreach($this->tools() as $d){if($d['name']===$tool){$definition=$d;break;}}if(!$definition){throw new Problem('Unknown tool.',404);}
        $schema=$definition['inputSchema'];$schema['properties']=(array)$schema['properties'];(new \Lumui\Protocol\JsonSchemaValidator($schema))->validate($i,'Input');
        $a=$this->app;$site=$i['site_id']??'';if($site!==''){$a->auth->access($u,$site);}
        if($tool==='mcp_approve'){return $this->approve($u,$i['code']);}
        if(isset(self::RIGHTS[$tool])&&!$this->has($u,self::RIGHTS[$tool])){throw new Problem('This connection needs administration access with the right "'.self::RIGHTS[$tool].'" for '.$tool.'.',403);}
        $this->approved($u,$tool);
        if(isset($a->plugins->tools[$tool])){$extension=$a->plugins->tools[$tool];if(isset($u['_token'])&&!in_array($extension['scope'],json_decode($u['_token']['scopes'],true),true)){throw new Problem('Operation not granted.',403);}if($site!==''){$a->auth->access($u,$site,$extension['write']?'write':'read');}return ($extension['handler'])($u,$i);}
        return match($tool){
            'sites_list'=>['sites'=>$a->sites->list($u)],
            'site_get'=>['site'=>$a->sites->get($site)]+($a->sites->server($a->sites->get($site))?['deployment'=>array_diff_key($a->sites->deployment($a->sites->get($site)),['folder'=>1])]:[]),
            'files_list'=>$this->files($site,$i['release_id'],(int)($i['offset']??0),(int)($i['limit']??100)),
            'file_read'=>['path'=>$i['path'],'content'=>$this->text($a->sites->read($u,$site,$i['release_id'],$i['path']))],
            'changes_create'=>$a->sites->begin($u,$site,$i['base_release']),
            'changes_apply'=>$a->sites->apply($u,$i['change_id'],$i['files']??[],$i['delete']??[]),
            'changes_upload'=>(new Uploads($a))->store($u,$i['change_id'],$i['files']??[],$i['urls']??[]),
            'changes_upload_link'=>(new Uploads($a))->link($u,$i['change_id'],$i['folder']??'',$i['expires_in']??3600),
            'changes_validate'=>$a->sites->validate($u,$i['change_id']),
            'changes_commit'=>$this->commit($u,$i),
            'changes_abort'=>$this->abort($u,$i['change_id']),
            'jobs_get'=>$this->job($u,$i['job_id']),
            'releases_list'=>['releases'=>$a->sites->releases($u,$site)],
            'release_preview'=>(new \DirectWeb\Sites\Preview($a))->link($u,$site,$i['release_id'],$i['expires_in']??86400),
            'release_publish'=>$a->sites->publish($u,$site,$i['release_id'],$i['revision']),
            'release_restore'=>$a->sites->restore($u,$site,$i['release_id'],$i['revision']),
            'release_pin'=>$this->pin($u,$site,$i['release_id'],$i['pinned']),
            'releases_prune'=>['site_id'=>$site,'deleted'=>$a->sites->prune($u,$site)],
            'site_create'=>$this->create($u,$i['name'],$i['host'],$i['runtime']??'static'),
            'site_update'=>['site'=>$a->sites->update($u,$site,array_intersect_key($i,array_flip(['name','host','runtime','enabled','auto_publish','spa'])),$i['revision'])],
            'site_delete'=>$a->sites->delete($u,$site,$i['revision'],$i['confirm_name']),
            'wordpress_homepage_get'=>\DirectWebWordPress\Bridge::homepage($a,$u),
            'wordpress_homepage_set'=>$this->homepage($u,$site),
            'directweb_update'=>(new Updater($a))->update($u,(string)($i['package']??''),(string)($i['url']??'')),
            'directweb_info'=>['version'=>$a->config['version'],'edition'=>empty($a->config['wordpress'])?'webserver':'wordpress','php'=>PHP_VERSION,'websites'=>array_map(fn($s)=>['id'=>$s['id'],'name'=>$s['name'],'host'=>$s['host'],'runtime'=>$s['runtime']??'static','enabled'=>(bool)$s['enabled'],'live'=>$s['active_release']!==null],$a->db->all('SELECT * FROM sites ORDER BY name'))],
        };
    }
    private const RIGHTS=['site_create'=>'admin.manage','site_update'=>'admin.manage','site_delete'=>'admin.manage','release_pin'=>'admin.manage','releases_prune'=>'admin.manage','wordpress_homepage_set'=>'admin.manage','directweb_update'=>'admin.update','directweb_info'=>'admin.read'];
    private function has(array $u,string $scope): bool
    {
        return $this->app->auth->can($u,$scope);
    }
    public function approvalRequired(array $u): bool
    {
        if(!isset($u['_token'])){return false;}
        return (int)$u['_token']['expires_at']===0||\DirectWeb\Identity\Auth::elevated(json_decode($u['_token']['scopes'],true)?:[])||(bool)$this->app->db->one('SELECT token_id FROM mcp_approvals WHERE token_id=?',[$u['_token']['id']]);
    }
    public static function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR']??''),0,64); }
    private function approved(array $u,string $tool): void
    {
        if(!$this->approvalRequired($u)){return;}$token=$u['_token']['id'];$ip=self::ip();
        $row=$this->app->db->one('SELECT * FROM mcp_approvals WHERE token_id=?',[$token]);
        if($row&&(int)$row['approved_until']>=time()&&(!(int)$row['bind_ip']||hash_equals((string)$row['approved_ip'],$ip))){return;}
        $this->app->db->transaction(function()use($u,$token,$ip,$tool){
            $open=$this->app->db->one("SELECT id FROM mcp_requests WHERE token_id=? AND ip=? AND status='pending' AND created_at>?",[$token,$ip,time()-3600]);
            if($open){$this->app->db->execute('UPDATE mcp_requests SET created_at=?,tool=? WHERE id=?',[time(),$tool,$open['id']]);}
            else{$this->app->db->execute('INSERT INTO mcp_requests(id,token_id,user_id,tool,ip,created_at) VALUES(?,?,?,?,?,?)',[Files::id(),$token,$u['id'],$tool,$ip,time()]);}
        });
        throw new Problem('Approval required. Ask the user for the current 6-digit code from their authenticator app and call mcp_approve with it, or ask them to approve the request in DirectWeb under Account, Security, Messages. Then try again.',403);
    }
    public function grant(string $token,string $ip): int
    {
        $row=$this->app->db->one('SELECT interval_seconds FROM mcp_approvals WHERE token_id=?',[$token]);$until=time()+(int)($row['interval_seconds']??86400);
        $this->app->db->execute('INSERT INTO mcp_approvals(token_id,approved_until,interval_seconds,approved_ip) VALUES(?,?,86400,?) ON CONFLICT(token_id) DO UPDATE SET approved_until=excluded.approved_until,approved_ip=excluded.approved_ip',[$token,$until,$ip]);
        $this->app->db->execute("UPDATE mcp_requests SET status='approved',decided_at=? WHERE token_id=? AND status='pending'",[time(),$token]);
        return $until;
    }
    private function approve(array $u,string $code): array
    {
        if(!$this->approvalRequired($u)){return ['approved'=>true,'required'=>false];}
        $token=$u['_token']['id'];$this->app->auth->limit('mcp-approve:'.$token,5,300);
        $until=$this->app->db->transaction(function()use($u,$code,$token){
            $fresh=$this->app->db->one('SELECT totp,totp_step FROM users WHERE id=?',[$u['id']]);if(!$fresh||!$fresh['totp']){throw new Problem('No authenticator app is set up for this account.',403);}
            $step=\DirectWeb\Identity\Totp::verify($this->app->unseal($fresh['totp']),$code,(int)$fresh['totp_step']);if($step===null){return null;}
            $this->app->db->execute('UPDATE users SET totp_step=? WHERE id=?',[$step,$u['id']]);return $this->grant($token,self::ip());
        });
        if($until===null){$this->app->audit($u['id'],'mcp.approval.failed',$token);throw new Problem('Invalid or already used code. Ask the user for the next code.',403);}
        $this->app->audit($u['id'],'mcp.approved',$token);return ['approved'=>true,'expires_at'=>gmdate('c',$until)];
    }
    private function writable(array $u): void
    {
        if(!$this->app->auth->can($u,'write')){throw new Problem('Operation not granted.',403);}
    }
    private function pin(array $u,string $site,string $release,bool $pinned): array
    {
        $this->writable($u);$this->app->sites->pin($u,$site,$release,$pinned);return ['site_id'=>$site,'release_id'=>$release,'pinned'=>$pinned];
    }
    private function create(array $u,string $name,string $host,string $runtime): array
    {
        $s=$this->app->sites->create($u,$name,$host,$runtime);
        return ['site'=>$s,'next_action'=>$this->app->sites->server($s)?'Create a change with changes_create (base_release null), add the application files, commit, and publish when the user asks. Server applications have no preview.':'Create a change with changes_create (base_release null), add index.html, commit and preview it. The website shows as Off until a release is published.'];
    }
    private function homepage(array $u,string $site): array
    {
        $this->writable($u);$current=\DirectWebWordPress\Bridge::currentSite();if($current!==''&&$current!==$site){$this->app->auth->access($u,$current,'manage');}
        \DirectWebWordPress\Bridge::selectSite($this->app,$u,$site);return \DirectWebWordPress\Bridge::homepage($this->app,$u);
    }
    private function commit(array $u,array $input): array
    {
        $result=$this->app->sites->commit($u,$input['change_id'],$input['name']??'',$input['request_id'],false);
        $result['next_action']=$result['status']==='draft'?'Call release_preview for this draft and share its URL so the user can test it. Offer release_publish when they are ready, unless they asked to keep it as a draft. Do not publish without user approval.':'No new draft was created. Report that the files are unchanged.';
        return $result;
    }
    private function files(string $site,string $release,int $offset,int $limit): array
    {
        $files=$this->app->sites->readManifest($site,$release)['files'];$rows=[];foreach(array_slice($files,$offset,$limit,true) as $path=>$entry){$rows[]=['path'=>$path]+$entry;}return ['files'=>$rows,'total'=>count($files),'next_offset'=>$offset+$limit<count($files)?$offset+$limit:null];
    }
    private function job(array $u,string $id): array
    {
        $job=$this->app->jobs->get($u,$id);if($job['actor']!==$u['id']){throw new Problem('Access denied.',403);}return ['job_id'=>$job['id'],'status'=>$job['status'],'updated_at'=>$job['updated_at']];
    }
    private function text(string $content): string {if(strlen($content)>262144){throw new Problem('File too large for MCP (256 KB).');}if(!mb_check_encoding($content,'UTF-8')||str_contains($content,"\0")){throw new Problem('Not a text file.');}return $content;}
    private function abort(array $u,string $id): array {$this->app->sites->abort($u,$id);return ['aborted'=>true];}
    public function http(?string $connectionToken=null): void
    {
        header('Cache-Control: no-store');header('Content-Type: application/json');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
        $origin=$_SERVER['HTTP_ORIGIN']??'';$expected=($this->app->config['secure']?'https':'http').'://'.$this->app->config['admin_host'];
        if($origin!==''&&$origin!==$expected){http_response_code(403);echo Files::json(['error'=>'origin']);return;}
        $auth=$_SERVER['HTTP_AUTHORIZATION']??'';
        try{
            if($connectionToken!==null){if(!preg_match('/^dw_[a-f0-9]{64}$/D',$connectionToken)){throw new Problem('Access denied.',403);}$token=$connectionToken;}
            else{if(!preg_match('/^Bearer (\S+)$/D',$auth,$match)){throw new Problem('Access denied.',401);}$token=$match[1];}
            $u=$this->app->auth->bearer($token);$this->app->auth->limit('mcp:'.$u['_token']['id'],120,60);
        }catch(Problem $e){http_response_code($connectionToken!==null&&$e->status===401?403:$e->status);if($connectionToken===null){header('WWW-Authenticate: Bearer resource_metadata="'.$expected.$this->app->url('/.well-known/oauth-protected-resource/mcp').'", scope="read write"');}echo Files::json(['error'=>$e->getMessage()]);return;}
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');echo Files::json(['error'=>'POST required']);return;}
        if(!str_contains($_SERVER['CONTENT_TYPE']??'','application/json')){http_response_code(415);return;}
        $raw=file_get_contents('php://input',false,null,0,4194305);if(strlen($raw)>4194304){http_response_code(413);return;}
        try{$m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){$this->rpcError(null,-32700,'Parse error');return;}
        if(!is_array($m)||($m['jsonrpc']??null)!=='2.0'||!is_string($m['method']??null)||isset($m['id'])&&(!is_string($m['id'])&&!is_int($m['id']))){$this->rpcError(null,-32600,'Invalid Request');return;}
        if(!array_key_exists('id',$m)){http_response_code(202);return;}$id=$m['id'];
        $version=$_SERVER['HTTP_MCP_PROTOCOL_VERSION']??'';if($version!==''&&!in_array($version,['2025-03-26','2025-06-18','2025-11-25'],true)){http_response_code(400);$this->rpcError($id,-32600,'Unsupported protocol version');return;}
        $params=$m['params']??[];
        if($m['method']==='initialize'){$result=['protocolVersion'=>in_array($params['protocolVersion']??'',['2025-03-26','2025-06-18','2025-11-25'],true)?$params['protocolVersion']:'2025-11-25','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'DirectWeb','version'=>$this->app->config['version']],'instructions'=>'Read the current website and relevant files before editing. Preserve its existing design unless asked to change it. Group related changes, validate them, then commit once with a unique request_id. A commit saves a draft and leaves the live website unchanged. Use releases_list to find the latest draft and continue editing that draft when appropriate. Put HTML, CSS, JavaScript and other text files through changes_apply. For images, fonts and other binary files: when you can run shell commands and the files are on this computer, call changes_upload_link and upload them yourself with its upload_command instead of asking the user; for small files you may use changes_upload with base64, and for public images changes_upload with urls. Only when the files are on the own device of the user or attached in chat, share the changes_upload_link URL and wait until they confirm. Always call changes_validate to see the exact paths before referencing them. Do this before committing, while the change is open. After committing, call release_preview and share the returned URL so the user can test the draft without publishing. Include its expiry when relevant and generate a fresh link if needed. Summarize the changes and offer to publish with release_publish when they are ready. Publish only when the user requests it. If the user says not to go live yet, keep the draft and do not ask again until they indicate readiness. Read site_get for the current revision before publishing. Use release_restore only when asked to restore a previous live version. A website shows as Off until it is enabled and has a published release. Manage websites with site_create, site_update (name, domain, enabled, auto_publish, spa) and site_delete; read site_get for the current revision first. Delete websites, prune releases or change a domain only when the user asks, and confirm the exact website first. Websites with runtime server are published into a folder the web server runs directly (PHP or anything else the hosting supports): there is no preview, so read the code carefully, never publish untested changes without the user agreeing, and never add secrets such as config.local.php to a release; files the application creates itself are kept on every publish. If a tool reports that approval is required, ask the user for the current 6-digit code from their authenticator app and call mcp_approve with it; never guess or reuse codes. Each connection has read, write and execute rights per website: write changes and publishes files, execute is needed to publish server applications. Managing websites and updating DirectWeb are separate DirectWeb rights. Execute and DirectWeb rights always require approval.'.(!empty($this->app->config['wordpress'])?' On WordPress, wordpress_homepage_get shows which website replaces the WordPress front end and why other websites are unavailable; wordpress_homepage_set changes it when the user asks.':'')];}
        elseif($m['method']==='ping'){$result=new \stdClass();}
        elseif($m['method']==='tools/list'){$scopes=json_decode($u['_token']['scopes'],true);$approval=$this->approvalRequired($u);$result=['tools'=>array_values(array_filter($this->tools(),fn($t)=>$t['name']==='mcp_approve'?$approval:(isset(self::RIGHTS[$t['name']])&&!$this->has($u,self::RIGHTS[$t['name']])?false:(isset($this->app->plugins->tools[$t['name']])?in_array('tool:'.$t['name'],$scopes,true):(!empty($t['annotations']['readOnlyHint'])||isset(self::RIGHTS[$t['name']])||$this->app->auth->can($u,'write'))))))];}
        elseif($m['method']==='tools/call'){
            try{$out=$this->invoke($u,(string)($params['name']??''),$params['arguments']??[]);$result=['content'=>[['type'=>'text','text'=>Files::json($out)]],'structuredContent'=>$out,'isError'=>false];}
            catch(Problem $e){$result=['content'=>[['type'=>'text','text'=>$e->getMessage()]],'isError'=>true];}
            catch(\Throwable $e){error_log('DirectWeb MCP: '.$e->getMessage());$result=['content'=>[['type'=>'text','text'=>'Invalid input or action failed.']],'isError'=>true];}
        }else{$this->rpcError($id,-32601,'Method not found');return;}
        echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    private function rpcError(mixed $id,int $code,string $message): void {echo Files::json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]]);}
}
