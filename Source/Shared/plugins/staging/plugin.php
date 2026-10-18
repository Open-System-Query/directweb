<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"staging","Staging") extends Module {

protected function fields(): array {return ['target'=>['label'=>'Target website ID','required'=>true],'release'=>['label'=>'Source release'],'publish'=>['label'=>'Publish target','kind'=>'checkBox','value'=>false]];}
protected function operations(): array {return ['clone'=>'Copy release'];}
protected function execute(string $op,array $u,array $r,array $i): array
{
    $source=$this->app->sites->get($r['site']);$target=$this->app->sites->get($r['data']['target']);$this->app->auth->access($u,$target['id'],'manage');if($source['id']===$target['id']){throw new Problem('Choose another website.');}
    $release=$r['data']['release']?:$source['active_release'];$this->app->sites->release($source['id'],$release);$c=$this->app->sites->begin($u,$target['id'],$target['active_release']);
    try{$dir=$this->app->storage('staging/'.$c['change_id'].'/files');Files::remove($dir);Files::copy($this->app->sites->dir($source['id'],$release).'/files',$dir);
        return $this->app->sites->commit($u,$c['change_id'],$r['name'],Files::id(),(bool)$r['data']['publish']);
    }catch(\Throwable $e){$this->app->sites->abort($u,$c['change_id']);throw $e;}
}

};$module->register($registry);
}
};
