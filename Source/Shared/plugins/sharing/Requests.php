<?php
declare(strict_types=1);
namespace DirectWebSharing;
use DirectWeb\Core\{App,Problem};
use DirectWeb\Ui\Surface;
final class Requests
{
    public function __construct(private App $app,private Sharing $sharing,private Portal $portal){}
    public function page(array $u,string $view): array
    {
        $this->sharing->requireUpload($u);$p=new Surface($this->app,'File requests','sharing.requests.'.$view,$u);$p->section('',[$p->link('Sharing','/admin/sharing','arrow-rotate-left')],['directory'=>true]);
        if($view==='new'){$p->section('Request',[$p->form('sharing.request.create','',['name'=>['label'=>'Name','required'=>true],'hours'=>['label'=>'Expires in (hours)','kind'=>'numberField','value'=>48,'min'=>1,'max'=>$this->sharing->settings()['max_hours']]],[],'Create')]);}
        else{$rows=$u['role']==='owner'?$this->app->db->all('SELECT * FROM share_requests'):$this->app->db->all('SELECT * FROM share_requests WHERE owner=?',[$u['id']]);
            if($view==='list'){$p->section('Actions',[$p->link('Add request','/admin/sharing/requests/new','plus')],['directory'=>true]);$p->section('Requests',[$p->table('Requests',['name'=>'Name','code'=>'Code','expires'=>'Expires'],array_map(fn($r)=>['name'=>['label'=>$r['name'],'literal'=>true,'href'=>'/admin/sharing/requests/detail?id='.$r['id']],'code'=>$r['code'],'expires'=>gmdate('Y-m-d H:i',$r['expires_at'])],$rows))]);}
            else{$record=null;foreach($rows as $r){if($r['id']===(string)($_GET['id']??'')){$record=$r;break;}}if(!$record){throw new Problem('Request missing.',404);}$p->section('Link',[$p->node('codeBlock',['text'=>$this->sharing->url($record['code']),'metadata'=>['copy'=>true]])]);$p->section('Close',[$p->form('sharing.request.close','',[],['id'=>$record['id']],'Close',true)]);}
        }
        return $p->build();
    }
}
