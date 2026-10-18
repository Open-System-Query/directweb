<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\App;
final class Surface
{
    public bool $literalTitle=false;
    public array $actions=[];
    public array $regions=[];
    private int $sequence=0;
    public function __construct(public App $app,public string $title,public string $id,public ?array $user=null){$query=[];foreach($_GET as $key=>$value){if(is_scalar($value)&&strlen((string)$value)<=2048){$query[$key]=(string)$value;}}ksort($query);if($query){$this->id.='.'.substr(hash('sha256',json_encode($query)),0,16);}}
    public function node(string $kind,array $props=[]): array
    {
        if(empty($props['metadata']['literal'])){foreach(['label','caption','title','message'] as $key){if(isset($props[$key])){$props[$key]=$this->app->translator->get($props[$key]);}}if($kind==='text'&&isset($props['text'])){$props['text']=$this->app->translator->get($props['text']);}}
        if(isset($props['options'])&&empty($props['metadata']['literal_options'])){foreach($props['options'] as &$option){if(empty($option['literal'])){$option['label']=$this->app->translator->get($option['label']);}unset($option['literal']);}unset($option);}
        return ['id'=>$this->id.'.n'.(++$this->sequence),'kind'=>$kind]+$props;
    }
    public function text(string $text,bool $translate=true): array {return $this->node('text',['text'=>$text,'metadata'=>['literal'=>!$translate]]);}
    public function link(string $label,string $href,string $icon='arrow-up-right-from-square'): array {return $this->node('link',['label'=>$label,'href'=>$href,'metadata'=>['icon'=>$icon]]);}
    public function table(string $label,array $columns,array $rows): array
    {
        $states=['queued'=>'Queued','running'=>'Running','done'=>'Completed','failed'=>'Failed','owner'=>'Owner','admin'=>'Administrator','editor'=>'Editor','reader'=>'Reader','backup'=>'Backup'];
        foreach($rows as &$row){foreach($row as $key=>&$value){if(in_array($key,['status','role','type'],true)&&is_string($value)){$value=$this->app->translator->get($states[$value]??$value);}elseif($key==='download'&&is_array($value)){$value['label']=$this->app->translator->get($value['label']);}}unset($value);}unset($row);
        return $this->node('table',['caption'=>$label,'columns'=>array_map(fn($id,$name)=>['id'=>$id,'label'=>$this->app->translator->get($name)],array_keys($columns),array_values($columns)),'rows'=>$rows]);
    }
    public function section(string $title,array $items,array $meta=[]): void {$props=['label'=>$title,'items'=>$items,'metadata'=>$meta];if(isset($meta['collapsible'])){$props['collapsible']=(bool)$meta['collapsible'];}$this->regions[]=$this->node('section',$props);}
    public function form(string $action,string $label,array $fields=[],array $context=[],string $button='Save',bool $danger=false): array
    {
        $aid=$action.'.'.(++$this->sequence);$items=[];$properties=[];$required=[];
        foreach($fields as $name=>$field){$kind=$field['kind']??'textField';$props=['label'=>$field['label']??$name,'action'=>$aid,'metadata'=>['name'=>$name,'literal'=>!empty($field['literal']),'literal_options'=>!empty($field['literal_options'])]];
            if($kind==='passwordField'){$props['value_present']=false;$props['autocomplete']=$field['autocomplete']??'new-password';}
            elseif($kind==='filePicker'){$props['selection_mode']=($field['multiple']??false)?'multiple':'single';if(isset($field['accept'])){$props['accept']=$field['accept'];}}
            else{$props['value']=$field['value']??($kind==='checkBox'?false:'');}
            foreach(['options','required','min_length','max_length','content_type','min','max','spellcheck'] as $p){if(isset($field[$p])){$props[$p]=$field[$p];}}
            if(!empty($field['required'])){$required[]=$name;}
            $properties[$name]=['type'=>match($kind){'checkBox'=>'boolean','numberField'=>'number',default=>'string'}];if($kind==='numberField'){$props['value']=(float)$props['value'];}$items[]=$this->node($kind,$props);
        }
        foreach($context as $key=>$value){$properties[$key]=['type'=>'string','const'=>(string)$value];$required[]=$key;}
        $this->actions[$aid]=['callback'=>$action,'input_schema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],'confirmation'=>$danger?'dangerous':'none','idempotent'=>false,'stale_revision_policy'=>'reject'];
        $items[]=$this->node('button',['label'=>$button,'action'=>$aid,'confirmation'=>$danger?'dangerous':'none']);
        return $this->node('form',['label'=>$label,'items'=>$items,'actions'=>[$aid],'submit_action'=>$aid,'metadata'=>['context'=>$context,'return'=>$_SERVER['REQUEST_URI']??'/admin','danger'=>$danger]]);
    }
    public function build(bool $navigation=true): array
    {
        $regions=Flow::apply($this,$this->regions);
        if($navigation&&$this->user){$account=$this->node('link',['label'=>$this->user['name'],'href'=>'/admin/account','metadata'=>['literal'=>true,'icon'=>'user']]);$regions[]=$this->node('section',['label'=>'Account','items'=>[$account,$this->form('logout','',[],[],'Sign out')],'metadata'=>['application_bar'=>true]]);}
        if(isset($_SESSION['flash'])){$regions[]=$this->node('section',['label'=>'Status','items'=>[$this->node('alert',['title'=>'Status','message'=>(string)$_SESSION['flash'],'severity'=>$_SESSION['flash_kind']??'info','live'=>'polite'])],'metadata'=>['notification'=>true]]);unset($_SESSION['flash'],$_SESSION['flash_kind']);}
        $used=[];$collect=function(array $nodes)use(&$collect,&$used){foreach($nodes as $node){if(isset($node['action'])){$used[$node['action']]=true;}if(isset($node['submit_action'])){$used[$node['submit_action']]=true;}if(isset($node['items'])){$collect($node['items']);}}};$collect($regions);$this->actions=array_intersect_key($this->actions,$used);
        $title=$this->literalTitle?$this->title:$this->app->translator->get($this->title);
        $rev=max(1,(int)($this->app->db->one('SELECT MAX(id) AS id FROM audit')['id']??1));
        return ['lumui_surface'=>'1.0','app_id'=>'directweb','surface_id'=>$this->id,'revision'=>$rev,'mode'=>'primary','title'=>$title,'locale'=>$this->app->translator->locale,'direction'=>'ltr','requested_page_id'=>$this->id.'.page','pages'=>[['id'=>$this->id.'.page','title'=>$title,'regions'=>$regions]],'actions'=>$this->actions,'links'=>[['rel'=>'actions','href'=>'/admin/actions'],['rel'=>'self','href'=>$_SERVER['REQUEST_URI']??'/admin']],'metadata'=>['user'=>$this->user?['name'=>$this->user['name'],'role'=>$this->user['role']]:null,'version'=>$this->app->config['version']]];
    }
}
