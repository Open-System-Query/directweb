<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"forms","Forms") extends Module {

protected function fields(): array {return ['fields'=>['label'=>'Fields','kind'=>'textArea','value'=>"name:text:required\nemail:email:required\nmessage:text:required",'required'=>true],'enabled'=>['label'=>'Public form','kind'=>'checkBox','value'=>true],'rate'=>['label'=>'Submissions per hour','kind'=>'numberField','value'=>10,'min'=>1,'max'=>100],'days'=>['label'=>'Retention (days)','kind'=>'numberField','value'=>90,'min'=>1,'max'=>3650]];}
private function schema(string $text): array {$fields=[];foreach(preg_split('/\R/',$text) as $line){if(trim($line)===''){continue;}$parts=explode(':',trim($line));$key=$parts[0];if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$key)||in_array($key,['website','record','_csrf','_action'],true)||!in_array($parts[1]??'text',['text','email','number'],true)){throw new Problem('Invalid field.');}$fields[$key]=['type'=>$parts[1]??'text','required'=>($parts[2]??'')==='required'];}if(!$fields||count($fields)>30){throw new Problem('Use 1 to 30 fields.');}return $fields;}
protected function validate(array $d,?array $old): array {$this->schema($d['fields']);return $d;}
protected function operations(): array {return ['entries'=>'View submissions'];}
protected function execute(string $op,array $u,array $r,array $i): array {return ['submissions'=>array_map(fn($e)=>['date'=>$e['created_at'],'data'=>$e['data']],$this->app->extensions->records('forms-entry',$r['id']))];}
private function submit(array $r,array $input): array
{
    if(!$r['data']['enabled']||!$this->app->sites->get($r['site'])['enabled']){throw new Problem('Unavailable.',404);}$this->app->auth->limit('form:'.$r['id'].':'.hash('sha256',$_SERVER['REMOTE_ADDR']??''),(int)$r['data']['rate'],3600);
    if((string)($input['website']??'')!==''){throw new Problem('Submission rejected.');}$values=[];
    foreach($this->schema($r['data']['fields']) as $key=>$field){$value=$input[$key]??'';if(!is_scalar($value)||strlen((string)$value)>10000){throw new Problem('Invalid value.');}$value=trim((string)$value);if($field['required']&&$value===''){throw new Problem('Complete required fields.');}if($value!==''&&($field['type']==='email'&&!filter_var($value,FILTER_VALIDATE_EMAIL)||$field['type']==='number'&&!is_numeric($value))){throw new Problem('Invalid value.');}$values[$key]=$value;}
    $entry=$this->app->extensions->save('forms-entry','Submission',$r['id'],$values);$owner=$this->app->db->one("SELECT id FROM users WHERE role='owner' AND enabled=1 LIMIT 1");$this->app->extensions->emit('form.received',$owner['id'],['site'=>$r['site'],'form'=>$r['id'],'entry'=>$entry['id']]);return ['received'=>true];
}
protected function extend(Registry $registry): void
{
    $registry->route('forms.public','~^/forms/([a-f0-9]{32})$~D',['GET','HEAD'],function($m,$u){$r=$this->app->extensions->get($this->id,$m[1]);if(!$r['data']['enabled']){throw new Problem('Unavailable.',404);}$p=new Surface($this->app,$r['name'],'forms.public');$p->literalTitle=true;$fields=[];foreach($this->schema($r['data']['fields']) as $key=>$field){$fields[$key]=['label'=>ucfirst(str_replace('_',' ',$key)),'required'=>$field['required'],'kind'=>$field['type']==='number'?'numberField':'textField'];}$p->section('Form',[$p->form('forms.submit','',$fields,['record'=>$r['id']],'Send')]);return $p->build(false);});
    $registry->addPublicAction('forms.submit',function($u,$i){$r=$this->app->extensions->get($this->id,(string)($i['record']??''));$this->submit($r,$i);$_SESSION['flash']='Received.';return '/forms/'.$r['id'];});
    $registry->siteRoutes[$this->id]=function($site,$uri){if(!preg_match('~^/forms/([a-f0-9]{32})$~D',$uri,$m)){return false;}if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){throw new Problem('Use POST.',405);}$r=$this->app->extensions->get($this->id,$m[1]);if($r['site']!==$site['id']){throw new Problem('Form missing.',404);}if((int)($_SERVER['CONTENT_LENGTH']??0)>65536){throw new Problem('Submission too large.',413);}$out=$this->submit($r,$_POST);header('Content-Type: application/json');echo Files::json($out);return true;};
    $registry->tasks['forms.retention']=function(){foreach($this->app->extensions->records($this->id) as $r){$this->app->db->execute('DELETE FROM extension_records WHERE plugin=? AND site=? AND created_at<?',['forms-entry',$r['id'],gmdate('c',time()-86400*$r['data']['days'])]);}};
}

};$module->register($registry);
}
};
