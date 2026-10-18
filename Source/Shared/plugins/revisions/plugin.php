<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"revisions","Revisions") extends Module {

protected function fields(): array {return ['left'=>['label'=>'Earlier release','required'=>true],'right'=>['label'=>'Later release','required'=>true]];}
protected function operations(): array {return ['compare'=>'Compare'];}
protected function execute(string $operation,array $u,array $r,array $input): array
{
    $left=$this->app->sites->readManifest($r['site'],$r['data']['left']);$right=$this->app->sites->readManifest($r['site'],$r['data']['right']);$out=[];
    foreach(array_unique(array_merge(array_keys($left['files']),array_keys($right['files']))) as $file){$a=$left['files'][$file]??null;$b=$right['files'][$file]??null;if(($a['hash']??null)===($b['hash']??null)){continue;}$row=['file'=>$file,'change'=>!$a?'added':(!$b?'deleted':'changed'),'before'=>$a['size']??0,'after'=>$b['size']??0];
        if($a&&$b&&$a['size']+$b['size']<131072){$x=$this->app->sites->read($u,$r['site'],$r['data']['left'],$file);$y=$this->app->sites->read($u,$r['site'],$r['data']['right'],$file);if(mb_check_encoding($x.$y,'UTF-8')&&!str_contains($x.$y,"\0")){$old=explode("\n",$x);$new=explode("\n",$y);$lines=[];for($i=0,$count=max(count($old),count($new));$i<$count;$i++){if(($old[$i]??null)===($new[$i]??null)){continue;}$lines[]=['line'=>$i+1,'before'=>$old[$i]??null,'after'=>$new[$i]??null];if(count($lines)>=200){break;}}$row['lines']=$lines;}}$out[]=$row;
    }return ['files'=>$out,'size_change'=>$right['size']-$left['size']];
}

};$module->register($registry);
}
};
