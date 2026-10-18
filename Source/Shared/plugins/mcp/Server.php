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
            ['changes_validate','Validate change',$change,['change_id']],
            ['changes_commit','Save a draft. Does not publish. After saving, get a preview URL for the user to test before offering publication.',$change+['name'=>$str,'request_id'=>['type'=>'string','minLength'=>16,'maxLength'=>100]],['change_id','request_id']],
            ['changes_abort','Discard draft',$change,['change_id']],
            ['jobs_get','Get own job status',['job_id'=>$str],['job_id']],
            ['releases_list','List releases',$site,['site_id']],
            ['release_preview','Get a shareable preview URL without publishing',$release+['expires_in'=>['type'=>'integer','minimum'=>60,'default'=>86400]],['site_id','release_id']],
            ['release_publish','Publish a saved release only when the user asks to make it live',$release+['revision'=>['type'=>'integer','minimum'=>1]],['site_id','release_id','revision']],
            ['release_restore','Restore a previous live release only when the user asks',$release+['revision'=>['type'=>'integer','minimum'=>1]],['site_id','release_id','revision']],
        ];$out=[];foreach($defs as [$name,$description,$properties,$required]){$read=in_array($name,['sites_list','site_get','files_list','file_read','changes_validate','releases_list','jobs_get','release_preview'],true);$out[]=['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>$properties?:new \stdClass(),'required'=>$required,'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>in_array($name,['changes_abort','release_publish','release_restore'],true),'idempotentHint'=>$read||$name==='changes_commit','openWorldHint'=>false]];}foreach($this->app->plugins->tools as $tool){$out[]=$tool['definition'];}return $out;
    }
    public function invoke(array $u,string $tool,array $i): array
    {
        $definition=null;foreach($this->tools() as $d){if($d['name']===$tool){$definition=$d;break;}}if(!$definition){throw new Problem('Unknown tool.',404);}
        $schema=$definition['inputSchema'];$schema['properties']=(array)$schema['properties'];(new \Lumui\Protocol\JsonSchemaValidator($schema))->validate($i,'Input');
        $a=$this->app;$site=$i['site_id']??'';if($site!==''){$a->auth->access($u,$site);}
        if(isset($a->plugins->tools[$tool])){$extension=$a->plugins->tools[$tool];if(isset($u['_token'])&&!in_array($extension['scope'],json_decode($u['_token']['scopes'],true),true)){throw new Problem('Operation not granted.',403);}if($site!==''){$a->auth->access($u,$site,$extension['write']?'write':'read');}return ($extension['handler'])($u,$i);}
        return match($tool){
            'sites_list'=>['sites'=>$a->sites->list($u)],
            'site_get'=>['site'=>$a->sites->get($site)],
            'files_list'=>$this->files($site,$i['release_id'],(int)($i['offset']??0),(int)($i['limit']??100)),
            'file_read'=>['path'=>$i['path'],'content'=>$this->text($a->sites->read($u,$site,$i['release_id'],$i['path']))],
            'changes_create'=>$a->sites->begin($u,$site,$i['base_release']),
            'changes_apply'=>$a->sites->apply($u,$i['change_id'],$i['files']??[],$i['delete']??[]),
            'changes_validate'=>$a->sites->validate($u,$i['change_id']),
            'changes_commit'=>$this->commit($u,$i),
            'changes_abort'=>$this->abort($u,$i['change_id']),
            'jobs_get'=>$this->job($u,$i['job_id']),
            'releases_list'=>['releases'=>$a->sites->releases($u,$site)],
            'release_preview'=>(new \DirectWeb\Sites\Preview($a))->link($u,$site,$i['release_id'],$i['expires_in']??86400),
            'release_publish'=>$a->sites->publish($u,$site,$i['release_id'],$i['revision']),
            'release_restore'=>$a->sites->restore($u,$site,$i['release_id'],$i['revision']),
        };
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
        if($m['method']==='initialize'){$result=['protocolVersion'=>in_array($params['protocolVersion']??'',['2025-03-26','2025-06-18','2025-11-25'],true)?$params['protocolVersion']:'2025-11-25','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'DirectWeb','version'=>$this->app->config['version']],'instructions'=>'Read the current website and relevant files before editing. Preserve its existing design unless asked to change it. Group related changes, validate them, then commit once with a unique request_id. A commit saves a draft and leaves the live website unchanged. Use releases_list to find the latest draft and continue editing that draft when appropriate. After committing, call release_preview and share the returned URL so the user can test the draft without publishing. Include its expiry when relevant and generate a fresh link if needed. Summarize the changes and offer to publish with release_publish when they are ready. Publish only when the user requests it. If the user says not to go live yet, keep the draft and do not ask again until they indicate readiness. Read site_get for the current revision before publishing. Use release_restore only when asked to restore a previous live version.'];}
        elseif($m['method']==='ping'){$result=new \stdClass();}
        elseif($m['method']==='tools/list'){$scopes=json_decode($u['_token']['scopes'],true);$result=['tools'=>array_values(array_filter($this->tools(),fn($t)=>isset($this->app->plugins->tools[$t['name']])?in_array('tool:'.$t['name'],$scopes,true):(!empty($t['annotations']['readOnlyHint'])||in_array('write',$scopes,true))))];}
        elseif($m['method']==='tools/call'){
            try{$out=$this->invoke($u,(string)($params['name']??''),$params['arguments']??[]);$result=['content'=>[['type'=>'text','text'=>Files::json($out)]],'structuredContent'=>$out,'isError'=>false];}
            catch(Problem $e){$result=['content'=>[['type'=>'text','text'=>$e->getMessage()]],'isError'=>true];}
            catch(\Throwable $e){error_log('DirectWeb MCP: '.$e->getMessage());$result=['content'=>[['type'=>'text','text'=>'Invalid input or action failed.']],'isError'=>true];}
        }else{$this->rpcError($id,-32601,'Method not found');return;}
        echo json_encode(['jsonrpc'=>'2.0','id'=>$id,'result'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
    private function rpcError(mixed $id,int $code,string $message): void {echo Files::json(['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]]);}
}
