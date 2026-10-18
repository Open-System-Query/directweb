<?php
declare(strict_types=1);
namespace DirectWeb\Plugins;
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Ui\Surface;
abstract class Module
{
    protected string $icon='plug';
    protected bool $siteScoped=true;
    protected bool $editable=true;
    public function __construct(protected App $app,public readonly string $id,public readonly string $title){$this->icon=match($id){'redirects'=>'arrow-right-from-bracket','revisions'=>'clock-rotate-left','content'=>'code','staging'=>'globe','seo'=>'globe','search'=>'folder','media'=>'folder','forms'=>'envelope','deploy'=>'upload','monitor'=>'server','tasks'=>'clock-rotate-left','statistics'=>'server','notifications'=>'envelope','remote-backups'=>'hard-drive','dns'=>'globe','cdn'=>'globe','databases'=>'server','hosting-connector'=>'server',default=>'plug'};}
    abstract protected function fields(): array;
    protected function operations(): array {return [];}
    protected function operationFields(string $operation,array $record): array {return [];}
    protected function validate(array $data,?array $old): array {return $data;}
    protected function execute(string $operation,array $user,array $record,array $input): array {throw new Problem('Action unavailable.');}
    public function register(Registry $registry): void
    {
        $registry->addPage($this->id,$this->title,$this->icon,fn($u)=>$this->page($u,'list'),['roles'=>$this->siteScoped?['owner','admin','editor','reader']:['owner'],'group'=>$this->siteScoped?'websites':'system']);
        foreach(['new','detail','edit','delete','run','export','import','jobs','job','versions','restore'] as $view){$registry->pages[$this->id.'/'.$view]=fn($u)=>$this->page($u,$view);}
        $registry->addAction($this->id.'.save',fn($u,$i)=>$this->saveAction($u,$i));
        $registry->addAction($this->id.'.restore',function($u,$i){$r=$this->record($u,(string)($i['id']??''),true);$row=$this->app->db->one('SELECT data FROM extension_versions WHERE record=? AND revision=?',[$r['id'],(int)($i['version']??0)])??throw new Problem('Version missing.',404);$old=json_decode($row['data'],true);$this->authorize($u,$old['site'],true);$this->app->extensions->save($this->id,$old['name'],$old['site'],$old['data'],$r['id'],(int)($i['revision']??0));$this->app->audit($u['id'],$this->id.'.restored',$r['id']);return $this->url('detail',['id'=>$r['id']]);});
        $registry->addAction($this->id.'.delete',function($u,$i){$r=$this->record($u,(string)($i['id']??''),true);if($r['revision']!==(int)($i['revision']??0)){throw new Problem('Record changed.',409);}$this->app->db->execute('UPDATE extension_records SET deleted=1,revision=revision+1 WHERE id=?',[$r['id']]);$this->app->audit($u['id'],$this->id.'.deleted',$r['id']);return $this->url();});
        $registry->addAction($this->id.'.run',function($u,$i){$r=$this->record($u,(string)($i['id']??''),true);$op=(string)($i['operation']??'');if(!isset($this->operations()[$op])){throw new Problem('Action unavailable.');}if($r['revision']!==(int)($i['revision']??0)){throw new Problem('Record changed.',409);}$this->app->jobs->add($u,$this->id.'.run',null,['id'=>$r['id'],'operation'=>$op,'input'=>$i,'revision'=>$r['revision']]);return $this->url('jobs');});
        $registry->addAction($this->id.'.import',function($u,$i){$this->authorize($u,'',true);$text=(string)($i['data']??'');if(strlen($text)>1048576){throw new Problem('Import too large.');}$rows=json_decode($text,true,32,JSON_THROW_ON_ERROR);if(!is_array($rows)||count($rows)>500){throw new Problem('Invalid import.');}foreach($rows as $row){$this->save($u,(array)$row);}return $this->url();});
        $registry->job($this->id.'.run',function($u,$payload){$r=$this->record($u,$payload['id'],true);if($r['revision']!==($payload['revision']??$r['revision'])){throw new Problem('Record changed.',409);}$out=$this->execute($payload['operation'],$u,$r,$payload['input']??[]);$this->app->audit($u['id'],$this->id.'.'.$payload['operation'],$r['id']);return $out;});
        if($this->siteScoped){$registry->tool($this->id.'_list','List '.$this->title,['type'=>'object','properties'=>['site_id'=>['type'=>'string']],'required'=>['site_id'],'additionalProperties'=>false],fn($u,$i)=>['records'=>array_map($this->safe(...),array_values(array_filter($this->visible($u),fn($r)=>$r['site']===$i['site_id'])))]);}
        if($this->editable&&$this->siteScoped){$registry->tool($this->id.'_save','Save '.$this->title,['type'=>'object','properties'=>['site_id'=>['type'=>'string'],'name'=>['type'=>'string'],'id'=>['type'=>'string'],'revision'=>['type'=>'integer'],'data'=>['type'=>'object']],'required'=>['site_id','name','data'],'additionalProperties'=>false],fn($u,$i)=>$this->safe($this->save($u,['site'=>$i['site_id'],'name'=>$i['name'],'id'=>$i['id']??'','revision'=>$i['revision']??0]+$i['data'])),true);}
        if($this->siteScoped){foreach($this->operations() as $operation=>$label){$registry->tool($this->id.'_run_'.$operation,$label,['type'=>'object','properties'=>['site_id'=>['type'=>'string'],'record_id'=>['type'=>'string'],'revision'=>['type'=>'integer'],'input'=>['type'=>'object']],'required'=>['site_id','record_id','revision'],'additionalProperties'=>false],function($u,$i)use($operation){$r=$this->record($u,$i['record_id'],true);if($r['site']!==$i['site_id']||$r['revision']!==$i['revision']){throw new Problem('Record changed.',409);}return ['job'=>$this->app->jobs->add($u,$this->id.'.run',null,['id'=>$r['id'],'operation'=>$operation,'input'=>$i['input']??[],'revision'=>$r['revision']])];},true);}}
        $this->extend($registry);
    }
    protected function publish(array $u,string $site,array $files,string $name): array
    {
        $s=$this->app->sites->get($site);$c=$this->app->sites->begin($u,$site,$s['active_release']);
        try{$this->app->sites->apply($u,$c['change_id'],$files);return $this->app->sites->commit($u,$c['change_id'],$name,Files::id());}
        catch(\Throwable $e){$this->app->sites->abort($u,$c['change_id']);throw $e;}
    }
    protected function documents(array $site): array
    {
        if(!class_exists(\DOMDocument::class)){throw new Problem('Enable the DOM extension.');}
        $manifest=$this->app->sites->readManifest($site['id'],$site['active_release']);$out=[];$previous=libxml_use_internal_errors(true);
        try{foreach($manifest['files'] as $path=>$file){if(!preg_match('/\.html?$/iD',$path)||$file['size']>2097152){continue;}$html=file_get_contents($this->app->sites->dir($site['id'],$site['active_release']).'/files/'.$path);$dom=new \DOMDocument();$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$out[$path]=$dom;libxml_clear_errors();}}finally{libxml_use_internal_errors($previous);}return $out;
    }
    protected function detailLinks(Surface $p,array $u,array $r): array {return [];}
    protected function extend(Registry $registry): void {}
    protected function authorize(array $u,string $site='',bool $write=false): void
    {
        if(!$this->siteScoped){$this->app->auth->requireOwner($u);if(isset($u['_token'])){throw new Problem('Interactive account required.',403);}return;}
        if($write&&!in_array($u['role'],['owner','admin','editor'],true)){throw new Problem('Access denied.',403);}
        if($site!==''){$this->app->auth->access($u,$site,$write?'write':'read');}
    }
    protected function record(array $u,string $id,bool $write=false): array {$r=$this->app->extensions->get($this->id,$id);$this->authorize($u,$r['site'],$write);return $r;}
    protected function visible(array $u): array
    {
        $this->authorize($u);return array_values(array_filter($this->app->extensions->records($this->id),function($r)use($u){try{$this->authorize($u,$r['site']);return true;}catch(Problem){return false;}}));
    }
    protected function safe(array $r): array {foreach($this->fields() as $key=>$field){if(($field['kind']??'')==='passwordField'){unset($r['data'][$key]);}}return $r;}
    protected function secret(array $record,string $key): string {return empty($record['data'][$key])?'':$this->app->unseal($record['data'][$key]);}
    protected function url(string $view='',array $query=[]): string {return '/admin/'.$this->id.($view!==''?'/'.$view:'').($query?'?'.http_build_query($query):'');}
    protected function save(array $u,array $input): array
    {
        return $this->app->lock(function()use($u,$input){
        if(!$this->editable){throw new Problem('Read only.',403);}$id=(string)($input['id']??'');$old=$id!==''?$this->record($u,$id,true):null;$site=$this->siteScoped?(string)($input['site']??''):'';
        if($this->siteScoped&&$site===''){throw new Problem('Choose a website.');}$this->authorize($u,$site,true);
        $data=[];foreach($this->fields() as $key=>$field){
            $kind=$field['kind']??'textField';$value=$input[$key]??($kind==='checkBox'?false:($field['value']??''));
            if($kind==='checkBox'){$value=!empty($value);}elseif($kind==='numberField'){if(!is_numeric($value)){throw new Problem('Enter a number.');}$value=0+$value;if(isset($field['min'])&&$value<$field['min']||isset($field['max'])&&$value>$field['max']){throw new Problem('Value out of range.');}}
            else{if(!is_scalar($value)){throw new Problem('Invalid value.');}$value=(string)$value;if(strlen($value)>1048576){throw new Problem('Value too large.');}}
            if($kind==='choice'&&!in_array($value,array_column($field['options'],'value'),true)){throw new Problem('Invalid choice.');}
            if($kind==='passwordField'){$value=$value!==''?$this->app->seal($value):($old['data'][$key]??'');}
            if(!empty($field['required'])&&$value===''){throw new Problem('Complete required fields.');}$data[$key]=$value;
        }
        $data=$this->validate($data,array_replace($old??['id'=>''],['site'=>$site]));if($site!==''){foreach(['left','right','release'] as $releaseKey){if(!empty($data[$releaseKey])){$this->app->sites->release($site,$data[$releaseKey]);}}}$r=$this->app->extensions->save($this->id,(string)($input['name']??''),$site,$data,$id?:null,(int)($input['revision']??0));
        $this->app->audit($u['id'],$this->id.'.saved',$r['id']);return $r;
        });
    }
    private function saveAction(array $u,array $input): string {$r=$this->save($u,$input);return $this->url('detail',['id'=>$r['id']]);}
    private function editFields(array $u,?array $record): array
    {
        $fields=$this->fields();$sites=$this->app->sites->list($u);
        if(isset($fields['target'])&&$this->id==='staging'){$fields['target']=['label'=>'Target website','kind'=>'choice','options'=>array_map(fn($s)=>['value'=>$s['id'],'label'=>$s['name'],'literal'=>true],$sites),'value'=>$sites[0]['id']??'','required'=>true];}
        foreach(['left','right','release'] as $key){if(!isset($fields[$key])){continue;}$options=$key==='release'?[['value'=>'','label'=>'Current release']]:[];
            foreach($sites as $site){foreach($this->app->sites->releases($u,$site['id']) as $release){$options[]=['value'=>$release['id'],'label'=>$site['name'].' · '.$release['name'],'literal'=>true];}}
            if($options){$fields[$key]=['label'=>$fields[$key]['label'],'kind'=>'choice','options'=>$options,'value'=>$options[0]['value'],'required'=>$key!=='release'];}
        }
        return $fields;
    }
    public function page(array $u,string $view): array
    {
        $this->authorize($u);$p=new Surface($this->app,$this->title,'plugin.'.$this->id.'.'.$view,$u);$id=(string)($_GET['id']??'');$r=$id!==''&&in_array($view,['detail','edit','delete','run'],true)?$this->record($u,$id):null;
        if($view!=='list'){$p->section('',[$p->link($this->title,$this->url(),'arrow-rotate-left')],['directory'=>true]);}
        if($view==='list'){
            $links=[];if($this->editable&&$u['role']!=='reader'){$links[]=$p->link('Add',$this->url('new'),'plus');$links[]=$p->link('Import',$this->url('import'),'upload');}$links[]=$p->link('Export',$this->url('export'),'download');if($this->operations()){$links[]=$p->link('Jobs',$this->url('jobs'),'clock-rotate-left');}
            $p->section('Actions',$links,['directory'=>true]);$rows=[];foreach($this->visible($u) as $record){$rows[]=['name'=>['label'=>$record['name'],'literal'=>true,'href'=>$this->url('detail',['id'=>$record['id']])],'site'=>$record['site']!==''?$this->app->sites->get($record['site'])['name']:'—','updated'=>$record['updated_at']];}
            $p->section('Overview',[$p->table($this->title,['name'=>'Name','site'=>'Website','updated'=>'Updated'],$rows)]);
        }elseif($view==='new'||$view==='edit'){
            $this->authorize($u,$r['site']??'',true);if(!$this->editable){throw new Problem('Read only.',403);}$p->title=$view==='new'?'Add':'Edit';
            $fields=['name'=>['label'=>'Name','required'=>true,'value'=>$r['name']??'']];
            if($this->siteScoped){$sites=$this->app->sites->list($u);if(!$sites){throw new Problem('Add a website first.');}$fields['site']=['label'=>'Website','kind'=>'choice','value'=>$r['site']??$sites[0]['id'],'options'=>array_map(fn($s)=>['value'=>$s['id'],'label'=>$s['name'],'literal'=>true],$sites)];}
            foreach($this->editFields($u,$r) as $key=>$field){if(($field['kind']??'')!=='passwordField'){$field['value']=$r['data'][$key]??$field['value']??'';}elseif($r){$field['required']=false;}$fields[$key]=$field;}
            $p->section($this->title,[$p->form($this->id.'.save','',$fields,['id'=>$r['id']??'','revision'=>(string)($r['revision']??0)])]);
        }elseif($view==='detail'){
            if(!$r){throw new Problem('Record not found.',404);}$p->title=$r['name'];$p->literalTitle=true;$items=[];
            if($u['role']!=='reader'){if($this->editable){$items[]=$p->link('Edit',$this->url('edit',['id'=>$r['id']]),'gear');}foreach($this->operations() as $op=>$label){$items[]=$p->link($label,$this->url('run',['id'=>$r['id'],'operation'=>$op]));}$items[]=$p->link('Delete',$this->url('delete',['id'=>$r['id']]),'trash');}
            $items[]=$p->link('Versions',$this->url('versions',['id'=>$r['id']]),'clock-rotate-left');$items=array_merge($items,$this->detailLinks($p,$u,$r));if(in_array($this->id,['forms','search'],true)){$items[]=$p->link('Open portal','/'.$this->id.'/'.$r['id'],'globe');}if($this->id==='deploy'){$endpoint=($this->app->config['secure']?'https':'http').'://'.$this->app->config['admin_host'].$this->app->url('/hooks/deploy/'.$r['id']);$p->section('Webhook',[$p->node('codeBlock',['text'=>$endpoint,'metadata'=>['copy'=>true]])]);}$p->section('Actions',$items,['directory'=>true]);$rows=[];foreach($this->safe($r)['data'] as $key=>$value){$rows[]=['field'=>$this->app->translator->get($this->fields()[$key]['label']??$key),'value'=>is_scalar($value)?(is_bool($value)?($value?'Enabled':'Disabled'):(string)$value):Files::json($value)];}$p->section('Details',[$p->table('Details',['field'=>'Field','value'=>'Value'],$rows)]);
        }elseif($view==='delete'){
            if(!$r){throw new Problem('Record not found.',404);}$this->authorize($u,$r['site'],true);$p->section('Delete',[$p->text($r['name'],false),$p->form($this->id.'.delete','',[],['id'=>$r['id'],'revision'=>(string)$r['revision']],'Delete',true)]);
        }elseif($view==='run'){
            if(!$r){throw new Problem('Record not found.',404);}$this->authorize($u,$r['site'],true);$op=(string)($_GET['operation']??'');$label=$this->operations()[$op]??throw new Problem('Action unavailable.');$p->title=$label;
            $p->section($label,[$p->form($this->id.'.run','',$this->operationFields($op,$r),['id'=>$r['id'],'operation'=>$op,'revision'=>(string)$r['revision']],$label,in_array($op,['delete','import','purge','rule','settings-save'],true))]);
        }elseif($view==='import'){$this->authorize($u,'',true);$p->section('Import',[$p->form($this->id.'.import','',['data'=>['label'=>'JSON','kind'=>'textArea','required'=>true]],[],'Import')]);}
        elseif($view==='export'){$rows=[];foreach($this->visible($u) as $row){$safe=$this->safe($row);$rows[]=['name'=>$safe['name'],'site'=>$safe['site']]+$safe['data'];}$p->section('Export',[$p->node('codeBlock',['text'=>json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),'metadata'=>['copy'=>true]])]);}
        elseif($view==='jobs'){$rows=$this->app->db->all('SELECT id,actor,status,error,created_at FROM jobs WHERE type=? ORDER BY created_at DESC LIMIT 100',[$this->id.'.run']);$items=[];foreach($rows as $job){if($u['role']!=='owner'&&$job['actor']!==$u['id']){continue;}$items[]=['date'=>['label'=>$job['created_at'],'literal'=>true,'href'=>$this->url('job',['id'=>$job['id']])],'status'=>$job['status'],'error'=>$job['error']??''];}$p->section('Jobs',[$p->table('Jobs',['date'=>'Date','status'=>'Status','error'=>'Error'],$items)]);}
        elseif($view==='versions'){
            $r=$this->record($u,$id);$rows=[];foreach($this->app->db->all('SELECT revision,created_at FROM extension_versions WHERE record=? ORDER BY revision DESC',[$id]) as $old){$rows[]=['revision'=>['label'=>(string)$old['revision'],'href'=>$this->url('restore',['id'=>$id,'version'=>$old['revision']])],'date'=>$old['created_at']];}$p->section('Versions',[$p->table('Versions',['revision'=>'Version','date'=>'Date'],$rows)]);
        }elseif($view==='restore'){
            $r=$this->record($u,$id,true);$version=(int)($_GET['version']??0);$old=$this->app->db->one('SELECT data FROM extension_versions WHERE record=? AND revision=?',[$id,$version])??throw new Problem('Version missing.',404);$p->section('Restore',[$p->form($this->id.'.restore','',[],['id'=>$id,'revision'=>(string)$r['revision'],'version'=>(string)$version],'Restore',true)]);
        }
        elseif($view==='job'){$job=$this->app->jobs->get($u,$id);if($job['type']!==$this->id.'.run'){throw new Problem('Job not found.',404);}$p->section('Status',[$p->text(ucfirst($job['status']))]);if($job['error']){$p->section('Error',[$p->text($job['error'])]);}if($job['result']){\DirectWeb\Ui\Result::sections($p,json_decode($job['result'],true));}}
        return $p->build();
    }
}
