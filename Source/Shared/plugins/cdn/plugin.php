<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"cdn","CDN") extends Module {

protected function fields(): array {return ['zone'=>['label'=>'Cloudflare zone ID','required'=>true],'token'=>['label'=>'API token','kind'=>'passwordField','required'=>true],'automatic'=>['label'=>'Purge after publish','kind'=>'checkBox','value'=>true]];}
protected function validate(array $d,?array $old): array {if(!preg_match('/^[a-f0-9]{32}$/D',$d['zone'])){throw new Problem('Invalid zone ID.');}return $d;}
protected function operations(): array {return ['purge'=>'Purge cache','rules'=>'Cache rules','rule'=>'Save cache rule'];}
protected function operationFields(string $op,array $r): array {return $op==='purge'?['urls'=>['label'=>'URLs (empty = all)','kind'=>'textArea']]:($op==='rule'?['rule'=>['label'=>'Rule (JSON)','kind'=>'textArea','required'=>true,'value'=>'{"action":"set_cache_settings","expression":"true","description":"DirectWeb","enabled":true,"action_parameters":{"cache":true}}']]:[]);}
private function api(array $r,string $method,string $path,?array $data=null): array {$response=Client::request($method,'https://api.cloudflare.com/client/v4/zones/'.$r['data']['zone'].'/'.$path,['Authorization'=>'Bearer '.$this->secret($r,'token'),'Content-Type'=>'application/json'],$data===null?'':Files::json($data));if($method==='GET'&&$response['status']===404&&str_starts_with($path,'rulesets/')){return ['success'=>true,'result'=>['rules'=>[]]];}if($response['status']<200||$response['status']>=300){throw new Problem('Provider returned HTTP %s.',422,[(string)$response['status']]);}return json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);}
protected function execute(string $op,array $u,array $r,array $i): array
{
    if($op==='purge'){$urls=array_values(array_filter(array_map('trim',preg_split('/\R/',(string)($i['urls']??'')))));if(count($urls)>100){throw new Problem('Use at most 100 URLs.');}return $this->api($r,'POST','purge_cache',$urls?['files'=>$urls]:['purge_everything'=>true]);}
    $path='rulesets/phases/http_request_cache_settings/entrypoint';if($op==='rules'){return $this->api($r,'GET',$path);}
    $rule=json_decode((string)($i['rule']??''),true,32,JSON_THROW_ON_ERROR);if(($rule['action']??'')!=='set_cache_settings'||empty($rule['expression'])){throw new Problem('Invalid cache rule.');}
    $old=$this->api($r,'GET',$path);$this->app->extensions->save('cdn-history','Before update',$r['id'],$old);$rules=$old['result']['rules']??[];$ref='directweb_'.$r['id'];$rules=array_values(array_filter($rules,fn($v)=>($v['ref']??'')!==$ref));$rule['ref']=$ref;$rules[]=$rule;foreach($rules as &$v){unset($v['version'],$v['last_updated']);}unset($v);return $this->api($r,'PUT',$path,['rules'=>$rules]);
}
protected function extend(Registry $registry): void {$registry->on('release.published','cdn.purge',function($u,$data){foreach($this->app->extensions->records($this->id,$data['site']) as $r){if($r['data']['automatic']){$this->execute('purge',$u,$r,[]);}}return ['purged'=>true];});}

};$module->register($registry);
}
};
