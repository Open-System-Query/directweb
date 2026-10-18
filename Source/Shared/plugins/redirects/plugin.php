<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"redirects","Redirects") extends Module {

protected function fields(): array {return ['source'=>['label'=>'From','required'=>true,'value'=>'/old'],'target'=>['label'=>'To','required'=>true,'value'=>'/new'],'status'=>['label'=>'Status','kind'=>'choice','value'=>'301','options'=>[['value'=>'301','label'=>'301'],['value'=>'302','label'=>'302']]],'enabled'=>['label'=>'Enabled','kind'=>'checkBox','value'=>true]];}
protected function validate(array $data,?array $old): array
{
    if(!str_starts_with($data['source'],'/')||str_starts_with($data['source'],'//')||preg_match('/[\x00-\x20?#]/',$data['source'])){throw new Problem('Use a local path.');}
    if(preg_match('/[\x00-\x20]/',$data['target'])||str_starts_with($data['target'],'//')||(!str_starts_with($data['target'],'/')&&!preg_match('~^https://[a-z0-9.-]+(?:/|$)~i',$data['target']))){throw new Problem('Invalid target.');}
    $map=[];foreach($this->app->extensions->records($this->id,$old['site']??'') as $r){if($r['id']===($old['id']??'')||!$r['data']['enabled']){continue;}$map[$r['data']['source']]=$r['data']['target'];}
    if(isset($map[$data['source']])){throw new Problem('Redirect already exists.');}$map[$data['source']]=$data['target'];$seen=[];$path=$data['source'];
    while(isset($map[$path])){if(isset($seen[$path])){throw new Problem('Redirect loop.');}$seen[$path]=true;$path=parse_url($map[$path],PHP_URL_PATH)?:'';if(preg_match('~^https://~',$map[array_key_last($seen)]??'')){break;}}
    return $data;
}
protected function extend(Registry $r): void {$r->siteRoutes[$this->id]=function($site,$uri){foreach($this->app->extensions->records($this->id,$site['id']) as $rule){$d=$rule['data'];if($d['enabled']&&$d['source']===$uri){header('Location: '.$d['target'],true,(int)$d['status']);return true;}}return false;};}

};$module->register($registry);
}
};
