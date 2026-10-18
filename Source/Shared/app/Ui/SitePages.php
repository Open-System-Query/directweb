<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\{App,Problem};
final class SitePages
{
    public function __construct(private App $app){}
    public function page(string $route,array $u): array
    {
        $a=$this->app;$p=new Surface($a,'Websites','admin.'.str_replace('/','.',$route),$u);$owner=$u['role']==='owner';$write=in_array($u['role'],['owner','admin','editor'],true);$manage=in_array($u['role'],['owner','admin'],true);
        if($route==='sites'){
            if($owner){$p->section('Actions',[$p->link('Add website','/admin/sites/new','plus')],['directory'=>true]);}
            $rows=[];foreach($a->sites->list($u) as $s){$rows[]=['name'=>['label'=>$s['name'],'literal'=>true,'href'=>'/admin/site?id='.$s['id']],'host'=>$s['host'],'version'=>$s['release_name']??'—','status'=>$s['enabled']&&$s['active_release']?'Live':'Off'];}
            $p->section('Websites',[$p->table('Websites',['name'=>'Website','host'=>'Domain','version'=>'Version','status'=>'Status'],$rows)]);return $p->build();
        }
        if($route==='sites/new'){$a->auth->requireOwner($u);$p->title='Add website';$p->section('',[$p->link('Websites','/admin/sites','arrow-rotate-left')],['directory'=>true]);$p->section('Website',[$p->form('site.create','',['name'=>['label'=>'Name','required'=>true],'host'=>['label'=>'Domain','required'=>true]],[],'Add')]);return $p->build();}
        $id=(string)($_GET['id']??'');$a->auth->access($u,$id);$s=$a->sites->get($id);$release=(string)($_GET['release']??$s['active_release']??'');$base='/admin/site?id='.$id;$context=['site'=>$id,'revision'=>(string)$s['revision']];$p->title=$s['name'];$p->literalTitle=in_array($route,['site','release','files/view'],true);
        $p->section('',[$p->link($route==='site'?'Websites':'Website',$route==='site'?'/admin/sites':$base,'arrow-rotate-left')],['directory'=>true]);
        if($route==='site'){
            $p->section('Website',[$p->text($s['host'],false),$p->node('badge',['label'=>$s['enabled']&&$s['active_release']?'Live':'Off'])],['icon'=>'globe']);
            $items=[$p->link('Open website',($a->config['secure']?'https':'http').'://'.$s['host'],'globe'),$p->link('Files','/admin/files?id='.$id,'folder'),$p->link('Versions','/admin/site/versions?id='.$id,'clock-rotate-left')];
            $latest=$a->sites->releases($u,$id)[0]??null;if($latest&&!$latest['published']){$items[]=$p->link('Draft','/admin/release?id='.$id.'&release='.$latest['id'],'code');}if($write){$items[]=$p->link('Upload','/admin/site/upload?id='.$id,'upload');}if($manage){$items[]=$p->link('Settings','/admin/site/settings?id='.$id,'gear');$items[]=$p->link('Retention','/admin/site/retention?id='.$id,'hard-drive');}$p->section('Manage',$items,['directory'=>true]);return $p->build();
        }
        if($route==='site/upload'){$a->auth->access($u,$id,'write');$p->title='Upload';$p->section('Upload',[$p->form('site.upload','',['name'=>['label'=>'Version name'],'website'=>['label'=>'Website file','kind'=>'filePicker','accept'=>'.zip','required'=>true]],['site'=>$id,'base'=>$s['active_release']??''],'Upload')]);}
        elseif($route==='site/settings'){$a->auth->access($u,$id,'manage');$p->title='Settings';$p->section('Website',[$p->form('site.configure','',['enabled'=>['label'=>'Website enabled','kind'=>'checkBox','value'=>(bool)$s['enabled']],'auto_publish'=>['label'=>'Publish automatically','kind'=>'checkBox','value'=>(bool)$s['auto_publish']],'spa'=>['label'=>'SPA routing','kind'=>'checkBox','value'=>(bool)$s['spa']]],$context)]);}
        elseif($route==='site/versions'){
            $rows=[];foreach($a->sites->releases($u,$id) as $r){$rows[]=['name'=>['label'=>$r['name'],'literal'=>true,'href'=>'/admin/release?id='.$id.'&release='.$r['id']],'created'=>$r['created_at'],'size'=>Pages::bytes($r['size']),'status'=>$r['id']===$s['active_release']?'Live':(!$r['published']?'Draft':($r['pinned']?'Pinned':'Retained'))];}$p->title='Versions';$p->section('Versions',[$p->table('Versions',['name'=>'Version','created'=>'Date','size'=>'Size','status'=>'Status'],$rows)]);
        }elseif($route==='release'){
            $r=$a->sites->release($id,$release);$p->title=$r['name'];$items=[$p->link('Files','/admin/files?id='.$id.'&release='.$release,'folder')];$items[]=$p->link('Preview','/admin/preview?site='.$id.'&release='.$release,'globe');if($write){$items[]=$p->link('Publish','/admin/site/restore?id='.$id.'&release='.$release,'upload');}if($manage){$items[]=$p->link('Retention','/admin/site/retention?id='.$id.'&release='.$release,'hard-drive');}$p->section('Version',[$p->node('badge',['label'=>$r['id']===$s['active_release']?'Live':(!$r['published']?'Draft':'Retained')]),$p->text($r['created_at'],false),$p->text(Pages::bytes($r['size']),false),$p->node('codeBlock',['text'=>$r['id'],'metadata'=>['copy'=>true]])]);$p->section('Actions',$items,['directory'=>true]);
        }elseif($route==='site/restore'){
            $a->auth->access($u,$id,'write');$r=$a->sites->release($id,$release);$p->title='Publish';$p->section('Version',[$p->text($r['name'],false),$p->form('release.restore','',[],$context+['release'=>$release],'Publish',true)]);
        }elseif($route==='site/retention'){
            $a->auth->access($u,$id,'manage');$p->title='Retention';$opts=array_map(fn($r)=>['value'=>$r['id'],'label'=>$r['name'],'literal'=>true],$a->sites->releases($u,$id));if($opts){$p->section('Pin',[$p->form('release.pin','',['release'=>['label'=>'Version','kind'=>'choice','options'=>$opts,'value'=>$release?:$opts[0]['value']],'pinned'=>['label'=>'Pin','kind'=>'checkBox','value'=>true]],['site'=>$id])]);}$p->section('Prune',[$p->form('release.prune','',[],['site'=>$id],'Prune old releases',true)]);
        }elseif(str_starts_with($route,'files')){
            $m=$release!==''?$a->sites->readManifest($id,$release):['files'=>[]];
            if($route==='files'){
                $p->title='Files';if($write){$p->section('Actions',[$p->link('Add file','/admin/files/new?id='.$id.'&release='.$release,'plus')],['directory'=>true]);}$rows=[];$offset=max(0,(int)($_GET['offset']??0));
                foreach(array_slice($m['files'],$offset,100,true) as $file=>$v){$rows[]=['name'=>['label'=>$file,'href'=>'/admin/files/view?'.http_build_query(['id'=>$id,'release'=>$release,'file'=>$file])],'size'=>Pages::bytes($v['size'])];}$items=[$p->table('Files',['name'=>'File','size'=>'Size'],$rows)];foreach(['Previous'=>max(0,$offset-100),'Next'=>$offset+100] as $label=>$next){if(($label==='Previous'&&$offset>0)||($label==='Next'&&$next<count($m['files']))){$items[]=$p->link($label,'/admin/files?'.http_build_query(['id'=>$id,'release'=>$release,'offset'=>$next]));}}$p->section('Files',$items);
            }else{
                $file=(string)($_GET['file']??'');$query=['id'=>$id,'release'=>$release,'file'=>$file];$context=['site'=>$id,'base'=>$release];$p->section('',[$p->link('Files','/admin/files?id='.$id.'&release='.$release,'folder')],['directory'=>true]);
                if($route==='files/view'){$content=$a->sites->read($u,$id,$release,$file);$p->title=$file;$p->literalTitle=true;$items=[];if($write){$items[]=$p->link('Edit','/admin/files/edit?'.http_build_query($query),'code');$items[]=$p->link('Delete','/admin/files/delete?'.http_build_query($query),'trash');}$p->section('Actions',$items,['directory'=>true]);$p->section('Content',[$p->node('codeBlock',['text'=>mb_check_encoding($content,'UTF-8')&&!str_contains($content,"\0")?$content:'Binary file'])]);}
                elseif($route==='files/delete'){$a->auth->access($u,$id,'write');$p->title='Delete file';$p->section('Delete',[$p->text($file,false),$p->form('file.delete','',[],$context+['path'=>$file],'Delete',true)]);}
                elseif(in_array($route,['files/new','files/edit'],true)){$a->auth->access($u,$id,'write');$content=$route==='files/edit'?$a->sites->read($u,$id,$release,$file):'';if(!mb_check_encoding($content,'UTF-8')||str_contains($content,"\0")){throw new Problem('Not a text file.');}$p->title=$route==='files/new'?'Add file':'Edit file';$p->section('File',[$p->form('file.save','',['path'=>['label'=>'File','required'=>true,'value'=>$file],'content'=>['label'=>'Content','kind'=>'textArea','value'=>$content],'name'=>['label'=>'Version name']],$context)]);}
                else{throw new Problem('Page not found.',404);}
            }
        }else{throw new Problem('Page not found.',404);}return $p->build();
    }
}
