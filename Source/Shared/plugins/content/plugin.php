<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"content","Content") extends Module {

protected function fields(): array {return ['path'=>['label'=>'Content file','required'=>true,'value'=>'content.json'],'content'=>['label'=>'Fields (JSON)','kind'=>'textArea','value'=>'{}','required'=>true],'template'=>['label'=>'Template','kind'=>'textArea'],'output'=>['label'=>'Output file','value'=>'index.html']];}
protected function validate(array $data,?array $old): array {Files::path($data['path']);if(!str_ends_with($data['path'],'.json')){throw new Problem('Use a JSON file.');}$values=json_decode($data['content'],true,32,JSON_THROW_ON_ERROR);if(!is_array($values)){throw new Problem('Use a JSON object.');}foreach($values as $key=>$value){if(!preg_match('/^[a-z][a-z0-9_]*$/iD',(string)$key)||!is_scalar($value)){throw new Problem('Use named text fields.');}}if($data['template']!==''){Files::path($data['output']);if(!str_ends_with($data['output'],'.html')){throw new Problem('Use an HTML output file.');}}return $data;}
protected function operations(): array {return ['publish'=>'Publish'];}
protected function execute(string $op,array $u,array $r,array $i): array {$d=$r['data'];$files=[$d['path']=>$d['content']];if($d['template']!==''){$values=json_decode($d['content'],true);$files[$d['output']]=preg_replace_callback('/\{\{([a-z][a-z0-9_]*)\}\}/i',fn($m)=>htmlspecialchars((string)($values[$m[1]]??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$d['template']);}$site=$this->app->sites->get($r['site']);$c=$this->app->sites->begin($u,$r['site'],$site['active_release']);try{$this->app->sites->apply($u,$c['change_id'],$files);return $this->app->sites->commit($u,$c['change_id'],$r['name'],Files::id());}catch(\Throwable $e){$this->app->sites->abort($u,$c['change_id']);throw $e;}}


protected function detailLinks(Surface $p,array $u,array $r): array {return [$p->link('Edit content','/admin/content/fields?id='.$r['id'],'code')];}
protected function extend(Registry $registry): void
{
    $registry->pages['content/fields']=function($u){$r=$this->record($u,(string)($_GET['id']??''),true);$p=new Surface($this->app,'Edit content','content.fields',$u);$fields=[];foreach(json_decode($r['data']['content'],true) as $key=>$value){$fields['field_'.$key]=['label'=>ucfirst(str_replace('_',' ',$key)),'literal'=>true,'kind'=>is_bool($value)?'checkBox':(is_numeric($value)&&!is_string($value)?'numberField':(strlen((string)$value)>150?'textArea':'textField')),'value'=>$value];}$p->section('',[$p->link('Content','/admin/content','arrow-rotate-left')],['directory'=>true]);$p->section('Fields',[$p->form('content.fields.save','',$fields,['id'=>$r['id'],'revision'=>(string)$r['revision']],'Publish')]);return $p->build();};
    $registry->addAction('content.fields.save',function($u,$i){$r=$this->record($u,(string)($i['id']??''),true);$values=json_decode($r['data']['content'],true);foreach($values as $key=>$old){$value=$i['field_'.$key]??(is_bool($old)?false:'');if(!is_scalar($value)||strlen((string)$value)>100000){throw new Problem('Invalid value.');}$values[$key]=is_bool($old)?!empty($value):(is_int($old)||is_float($old)?(is_numeric($value)?0+$value:throw new Problem('Enter a number.')):(string)$value);}
        $r=$this->save($u,['id'=>$r['id'],'revision'=>(int)($i['revision']??0),'site'=>$r['site'],'name'=>$r['name']]+array_replace($r['data'],['content'=>Files::json($values)]));$this->execute('publish',$u,$r,[]);return $this->url('detail',['id'=>$r['id']]);});
}

};$module->register($registry);
}
};
