<?php
declare(strict_types=1);
namespace DirectWebSharing;
use DirectWeb\Core\{App,Problem};
use DirectWeb\Ui\{Surface,Pages};
final class Portal
{
    public function __construct(private App $app,private Sharing $sharing){}
    private function surface(Surface $p): array {$surface=$p->build(false);$surface['metadata']['home']='/transfer';$surface['metadata']['portal']=true;return $surface;}
    public function transferForm(Surface $p,string $origin,string $request=''): array
    {
        $s=$this->sharing->settings();$form=$p->form('sharing.create','',['name'=>['label'=>'Name','max_length'=>120],'files'=>['label'=>'Files','kind'=>'filePicker','multiple'=>true,'required'=>true],'hours'=>['label'=>'Expires in (hours)','kind'=>'numberField','value'=>$s['hours'],'min'=>1,'max'=>$s['max_hours']],'limit'=>['label'=>'Download limit (0 = unlimited)','kind'=>'numberField','value'=>0,'min'=>0,'max'=>100000],'password'=>['label'=>'Download password','kind'=>'passwordField','required'=>$s['password_required'],'min_length'=>8,'max_length'=>1024]],['origin'=>$origin,'request'=>$request],'Upload');
        $form['metadata']['upload']=['endpoint'=>'/transfer/upload','return'=>$request!==''?'/transfer?task=complete':($origin==='portal'?'/transfer':'/admin/sharing'),'max_file'=>$s['file_mb']*1048576,'max_total'=>$s['transfer_mb']*1048576,'max_files'=>$s['files'],'available'=>max(0,$s['storage_mb']*1048576-$this->sharing->usage())];return $form;
    }
    private function history(Surface $p,?array $u,string $origin): void
    {
        $shares=$this->sharing->list($u);$rows=[];foreach($shares as $share){$rows[]=['name'=>['label'=>$share['name'],'literal'=>true,'href'=>($origin==='admin'?'/admin/sharing/detail?id=':'/transfer?task=detail&id=').$share['id']],'code'=>['label'=>$share['code'],'href'=>'/'.$share['code']],'size'=>Pages::bytes(array_sum(array_column($this->sharing->files($share['id']),'size'))),'downloads'=>$share['downloads'],'expires'=>gmdate('Y-m-d H:i',$share['expires_at']),'status'=>$share['revoked']?'Revoked':($share['expires_at']<=time()?'Expired':'Active')];}
        $p->section('Transfers',[$p->table('Transfers',['name'=>'Name','code'=>'Code','size'=>'Size','downloads'=>'Downloads','expires'=>'Expires','status'=>'Status'],$rows)],['wide'=>true]);
        $created=(string)($_GET['created']??'');foreach($shares as $share){if($created!==''&&$share['code']===$created){$p->section('Share link',[$p->node('codeBlock',['text'=>$this->sharing->url($created),'metadata'=>['copy'=>true]]),$p->link('Open','/'.$created,'arrow-up-right-from-square')],['wide'=>true]);break;}}
    }
    public function home(?array $u): array
    {
        if(!$this->sharing->settings()['portal']){throw new Problem('Unavailable.',404);}$p=new Surface($this->app,'File transfer','sharing.portal');$task=(string)($_GET['task']??(isset($_GET['created'])?'history':''));
        if($task===''){$items=[$p->link('Receive','/transfer?task=receive','download')];if($this->sharing->canUpload($u)){$items[]=$p->link('Send','/transfer?task=send','upload');$items[]=$p->link('Transfers','/transfer?task=history','clock-rotate-left');}else{$items[]=$p->link('Sign in','/transfer/login','user');}$p->section('Transfers',$items,['directory'=>true]);if($u){$p->section('Account',[$p->link('Dashboard','/admin','house')],['directory'=>true]);}}
        elseif($task==='complete'){$p->section('Transfer',[$p->text('Received.')]);}
        elseif($task==='receive'){$p->title='Receive';$p->section('Code',[$p->form('sharing.open','',['code'=>['label'=>'Code','required'=>true,'min_length'=>5,'max_length'=>5]],[],'Open')]);}
        elseif($task==='send'){$this->sharing->requireUpload($u);$p->title='Send';$p->section('Transfer',[$this->transferForm($p,'portal')]);}
        elseif($task==='history'){$this->history($p,$u,'portal');}
        elseif($task==='detail'){return $this->detail($u,'portal');}
        else{throw new Problem('Page not found.',404);}return $this->surface($p);
    }
    public function admin(array $u): array
    {
        $p=new Surface($this->app,'Sharing','plugin.sharing',$u);$items=[$p->link('Open portal','/transfer','globe')];
        if($this->sharing->canUpload($u)){$items[]=$p->link('Send','/admin/sharing/new','plus');$items[]=$p->link('File requests','/admin/sharing/requests','upload');}
        if($u['role']==='owner'){$items[]=$p->link('Settings','/admin/sharing-settings','gear');}$p->section('Actions',$items,['directory'=>true]);$this->history($p,$u,'admin');return $p->build();
    }
    public function createPage(array $u): array {$this->sharing->requireUpload($u);$p=new Surface($this->app,'Send','sharing.new',$u);$p->section('',[$p->link('Sharing','/admin/sharing','arrow-rotate-left')],['directory'=>true]);$p->section('Transfer',[$this->transferForm($p,'admin')]);return $p->build();}
    public function detail(?array $u,string $origin): array
    {
        $share=$this->sharing->owned($u,(string)($_GET['id']??''));$p=new Surface($this->app,$share['name'],'sharing.detail.'.$share['id'],$origin==='admin'?$u:null);$p->literalTitle=true;
        $p->section('Link',[$p->node('codeBlock',['text'=>$this->sharing->url($share['code']),'metadata'=>['copy'=>true]]),$p->link('Open','/'.$share['code'],'globe')]);
        $p->section('Expiry',[$p->form('sharing.extend','',['hours'=>['label'=>'Expires in (hours)','kind'=>'numberField','value'=>$this->sharing->settings()['hours'],'min'=>1,'max'=>$this->sharing->settings()['max_hours']]],['share'=>$share['id'],'origin'=>$origin])]);
        foreach(['revoke'=>'Revoke','delete'=>'Delete'] as $op=>$label){$p->section($label,[$p->form('sharing.'.$op,'',[],['share'=>$share['id'],'origin'=>$origin],$label,true)]);}return $origin==='admin'?$p->build():$this->surface($p);
    }
    public function requestPage(array $request): array {$p=new Surface($this->app,$request['name'],'sharing.request.'.$request['id']);$p->literalTitle=true;$p->section('Send',[$this->transferForm($p,'portal',$request['code'])]);return $this->surface($p);}
    public function settings(array $u): array
    {
        $this->app->auth->requireOwner($u);$p=new Surface($this->app,'Sharing settings','plugin.sharing.settings',$u);$s=$this->sharing->settings();$fields=[];
        foreach(['portal'=>'Public portal','public_uploads'=>'Guest uploads','password_required'=>'Require download password','notify'=>'Download notifications'] as $key=>$label){$fields[$key]=['label'=>$label,'kind'=>'checkBox','value'=>$s[$key]];}
        foreach(['file_mb'=>'File limit (MB)','transfer_mb'=>'Transfer limit (MB)','storage_mb'=>'Storage limit (MB)','files'=>'Files per transfer','hours'=>'Default expiry (hours)','max_hours'=>'Maximum expiry (hours)','upload_rate'=>'Uploads per hour per IP'] as $key=>$label){$fields[$key]=['label'=>$label,'kind'=>'numberField','value'=>$s[$key],'required'=>true,'min'=>1];}
        $p->section('Limits',[$p->form('sharing.settings','',$fields)],['wide'=>true]);$p->section('Portal',[$p->link('Open portal','/transfer','globe')]);return $p->build();
    }
    public function download(string $token): array
    {
        $this->sharing->guard($_SERVER['REMOTE_ADDR']??'');$share=$this->sharing->find($token);$p=new Surface($this->app,'Download','sharing.download.'.substr(hash('sha256',$token),0,16));
        if(!$this->sharing->allowed($share)){$p->section('Protected',[$p->form('sharing.unlock','',['password'=>['label'=>'Download password','kind'=>'passwordField','required'=>true]],['token'=>$token],'Open')]);}
        else{$p->title=$share['name'];$p->literalTitle=true;$items=[];$zip=$p->form('sharing.zip','',[],['token'=>$token],'Download ZIP');$zip['metadata']['download']=true;$items[]=$zip;foreach($this->sharing->files($share['id']) as $file){$form=$p->form('sharing.download',$file['name'].' · '.Pages::bytes($file['size']),[],['token'=>$token,'file'=>$file['id']],'Download');$form['metadata']['download']=true;$items[]=$form;}$p->section('Files',$items);$p->section('Available',[$p->text($this->app->translator->get('Until %s',gmdate('Y-m-d H:i',$share['expires_at'])),false)]);}
        $p->section('Portal',[$p->link('Send files','/transfer','upload')]);return $this->surface($p);
    }
}
