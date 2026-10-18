<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"dns","DNS") extends Module {

protected bool $siteScoped=false;
protected function fields(): array {return ['zone'=>['label'=>'Cloudflare zone ID','required'=>true],'token'=>['label'=>'API token','kind'=>'passwordField','required'=>true]];}
protected function validate(array $d,?array $old): array {if(!preg_match('/^[a-f0-9]{32}$/D',$d['zone'])){throw new Problem('Invalid zone ID.');}return $d;}
protected function operations(): array {return ['records'=>'Records','save'=>'Save record','delete'=>'Delete record','import'=>'Import records'];}
protected function operationFields(string $op,array $r): array
{
    if($op==='import'){return ['records'=>['label'=>'Records (JSON)','kind'=>'textArea','required'=>true]];}
    if($op==='delete'){return ['record'=>['label'=>'Record ID','required'=>true]];}
    if($op!=='save'){return [];}return ['record'=>['label'=>'Record ID (optional)'],'type'=>['label'=>'Type','kind'=>'choice','value'=>'A','options'=>array_map(fn($s)=>['value'=>$s,'label'=>$s],['A','AAAA','CNAME','MX','TXT','CAA','NS'])],'name'=>['label'=>'Name','required'=>true],'content'=>['label'=>'Content','required'=>true],'ttl'=>['label'=>'TTL','kind'=>'numberField','value'=>1,'min'=>1,'max'=>86400],'priority'=>['label'=>'Priority','kind'=>'numberField','value'=>10,'min'=>0,'max'=>65535],'proxied'=>['label'=>'Proxy','kind'=>'checkBox','value'=>false]];
}
private function api(array $r,string $method,string $suffix,?array $body=null): array {$result=Client::json($method,'https://api.cloudflare.com/client/v4/zones/'.$r['data']['zone'].'/dns_records'.$suffix,['Authorization'=>'Bearer '.$this->secret($r,'token')],$body);if(empty($result['success'])){throw new Problem('DNS operation failed.');}return $result;}
protected function execute(string $op,array $u,array $r,array $i): array
{
    if($op==='records'){$rows=[];$page=1;do{$result=$this->api($r,'GET','?per_page=100&page='.$page);$rows=array_merge($rows,$result['result']);$page++;}while($page<=min(100,(int)($result['result_info']['total_pages']??1)));return ['records'=>$rows];}
    $id=(string)($i['record']??$i['id']??'');if($id!==''&&!preg_match('/^[a-f0-9]{32}$/D',$id)){throw new Problem('Invalid record ID.');}
    if($op==='delete'){if($id===''){throw new Problem('Choose a record.');}$snapshot=$this->api($r,'GET','/'.$id);$this->app->extensions->save('dns-history','Before delete',$r['id'],$snapshot);return $this->api($r,'DELETE','/'.$id);}
    if($op==='import'){$rows=json_decode((string)($i['records']??''),true,32,JSON_THROW_ON_ERROR);if(!is_array($rows)||count($rows)>500){throw new Problem('Invalid records.');}$count=0;foreach($rows as $row){$this->execute('save',$u,$r,$row);$count++;}return ['saved'=>$count];}
    $body=['type'=>(string)($i['type']??''),'name'=>(string)($i['name']??''),'content'=>(string)($i['content']??''),'ttl'=>(int)($i['ttl']??1)];if(in_array($body['type'],['A','AAAA','CNAME'],true)){$body['proxied']=!empty($i['proxied']);}if($body['type']==='MX'){$body['priority']=(int)($i['priority']??10);}if(!in_array($body['type'],['A','AAAA','CNAME','MX','TXT','CAA','NS'],true)||$body['name']===''||$body['content']===''){throw new Problem('Check DNS record.');}
    if($id!==''){$this->app->extensions->save('dns-history','Before update',$r['id'],$this->api($r,'GET','/'.$id));}return $this->api($r,$id===''?'POST':'PUT',$id===''?'':'/'.$id,$body);
}

};$module->register($registry);
}
};
