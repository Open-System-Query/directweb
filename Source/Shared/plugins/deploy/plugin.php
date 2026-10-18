<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"deploy","Deploy") extends Module {

protected function fields(): array {return ['artifact'=>['label'=>'Artifact URL','required'=>true],'token'=>['label'=>'Artifact token','kind'=>'passwordField'],'provider'=>['label'=>'Provider','kind'=>'choice','value'=>'github','options'=>[['value'=>'github','label'=>'GitHub'],['value'=>'gitlab','label'=>'GitLab']]],'secret'=>['label'=>'Webhook secret','kind'=>'passwordField','required'=>true],'branch'=>['label'=>'Branch','value'=>'main'],'enabled'=>['label'=>'Webhooks','kind'=>'checkBox','value'=>false]];}
protected function validate(array $d,?array $old): array {if(!filter_var($d['artifact'],FILTER_VALIDATE_URL)||!str_starts_with($d['artifact'],'https://')){throw new Problem('Use an HTTPS artifact URL.');}return $d;}
protected function operations(): array {return ['deploy'=>'Deploy'];}
protected function execute(string $op,array $u,array $r,array $i): array
{
    $headers=[];$token=$this->secret($r,'token');if($token!==''){if($r['data']['provider']==='gitlab'){$headers['PRIVATE-TOKEN']=$token;}else{$headers['Authorization']='Bearer '.$token;}}$response=Client::fetch($r['data']['artifact'],$headers,$this->app->config['max_upload']);if($response['status']!==200){throw new Problem('Artifact unavailable.');}$file=$this->app->storage('staging/artifact-'.Files::id().'.zip');Files::write($file,$response['body']);
    try{$s=$this->app->sites->get($r['site']);return $this->app->sites->import($u,$r['site'],$file,$r['name'],$s['active_release']);}finally{Files::remove($file);}
}
protected function extend(Registry $registry): void
{
    $registry->route('deploy.webhook','~^/hooks/deploy/([a-f0-9]{32})$~D',['POST'],function($m){
        $r=$this->app->extensions->get($this->id,$m[1]);if(!$r['data']['enabled']){throw new Problem('Unavailable.',404);}$raw=file_get_contents('php://input',false,null,0,1048577);if(strlen($raw)>1048576){throw new Problem('Payload too large.',413);}
        $secret=$this->secret($r,'secret');$valid=$r['data']['provider']==='github'?hash_equals('sha256='.hash_hmac('sha256',$raw,$secret),(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??'')):hash_equals($secret,(string)($_SERVER['HTTP_X_GITLAB_TOKEN']??''));if(!$valid||$secret===''){throw new Problem('Access denied.',403);}
        $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$ref=$payload['ref']??$payload['object_attributes']['ref']??'';if($r['data']['branch']!==''&&!in_array($ref,[$r['data']['branch'],'refs/heads/'.$r['data']['branch']],true)){return ['ignored'=>true];}
        $actor=$this->app->db->one("SELECT * FROM users WHERE role='owner' AND enabled=1 LIMIT 1")??throw new Problem('Unavailable.',503);$id='deploy-'.hash('sha256',$r['id'].$raw);
        $this->app->jobs->add($actor,'deploy.run',$id,['id'=>$r['id'],'operation'=>'deploy','revision'=>$r['revision']]);http_response_code(202);return ['job'=>$id];
    },false);
}

};$module->register($registry);
}
};
