<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\{App,Problem};
final class SystemPages
{
    public function __construct(private App $app){}
    public function page(string $route,array $u): array
    {
        $a=$this->app;$a->auth->requireOwner($u);$p=new Surface($a,'Administration','admin.'.str_replace('/','.',$route),$u);
        if(str_starts_with($route,'users')||$route==='user'){
            $p->title='Users';
            if($route==='users'){$p->section('Actions',[$p->link('Add user','/admin/users/new','plus')],['directory'=>true]);$rows=[];foreach($a->db->all('SELECT * FROM users ORDER BY name') as $v){$rows[]=['name'=>['label'=>$v['name'],'literal'=>true,'href'=>'/admin/user?id='.$v['id']],'username'=>$v['username'],'role'=>$v['role'],'status'=>$v['enabled']?($a->auth->hasFactor($v)?'2FA enabled':'Password'):'Blocked'];}$p->section('Users',[$p->table('Users',['name'=>'Name','username'=>'Username','role'=>'Role','status'=>'Status'],$rows)]);}
            else{$p->section('',[$p->link('Users','/admin/users','arrow-rotate-left')],['directory'=>true]);$roles=array_map(fn($r)=>['value'=>$r,'label'=>ucfirst($r)],['admin','editor','reader']);
                if($route==='users/new'){$p->title='Add user';$p->section('User',[$p->form('user.create','',['name'=>['label'=>'Name','required'=>true],'username'=>['label'=>'Username','required'=>true],'password'=>['label'=>'Temporary password','kind'=>'passwordField','min_length'=>12,'required'=>true],'role'=>['label'=>'Role','kind'=>'choice','value'=>'editor','options'=>$roles]],[],'Add')]);}
                else{$id=(string)($_GET['id']??'');$target=$a->db->one('SELECT * FROM users WHERE id=?',[$id])??throw new Problem('User missing.',404);$p->title=$target['name'];$p->literalTitle=true;
                    if($route==='user'){$p->section('Profile',[$p->text($target['username'],false),$p->text(ucfirst($target['role']))]);if($target['role']!=='owner'){$p->section('Actions',[$p->link('Access','/admin/users/edit?id='.$id,'shield-halved')],['directory'=>true]);}elseif($target['id']===$u['id']){$p->section('Account',[$p->link('Account','/admin/account','user')],['directory'=>true]);}}
                    elseif($route==='users/edit'){$fields=['enabled'=>['label'=>'Account enabled','kind'=>'checkBox','value'=>(bool)$target['enabled']],'role'=>['label'=>'Role','kind'=>'choice','value'=>$target['role'],'options'=>$roles]];foreach($a->sites->list($u) as $s){$fields['site_'.$s['id']]=['label'=>$s['name'],'literal'=>true,'kind'=>'checkBox','value'=>(bool)$a->db->one('SELECT user_id FROM user_sites WHERE user_id=? AND site_id=?',[$id,$s['id']])];}$p->section('Access',[$p->form('user.access','',$fields,['user'=>$id])]);}
                    else{throw new Problem('Page not found.',404);}
                }
            }
        }elseif(str_starts_with($route,'plugins')){
            $p->title='Plugins';$all=$a->plugins->all();$current=$a->setting('renderer','renderer-classic');
            if($route==='plugins'){$p->section('Actions',[$p->link('Install','/admin/plugins/install','plus'),$p->link('Appearance','/admin/plugins/appearance','palette')],['directory'=>true]);$rows=[];foreach($all as $m){$rows[]=['name'=>['label'=>$m['name'],'literal'=>true,'href'=>'/admin/plugins/detail?id='.$m['id']],'description'=>$a->plugins->description($m['id']),'version'=>$m['version'],'type'=>$m['type']==='renderer'?'Renderer':'Plugin','status'=>$m['type']==='renderer'?($current===$m['id']?'In use':'Available'):($m['enabled']?'Enabled':'Disabled')];}$p->section('Installed',[$p->table('Plugins',['name'=>'Name','description'=>'Description','version'=>'Version','type'=>'Type','status'=>'Status'],$rows)]);}
            else{$p->section('',[$p->link('Plugins','/admin/plugins','arrow-rotate-left')],['directory'=>true]);
                if($route==='plugins/install'){$p->title='Install plugin';$p->section('Package',[$p->form('plugin.install','',['package'=>['label'=>'Plugin file','kind'=>'filePicker','accept'=>'.zip','required'=>true],'activate'=>['label'=>'Enable after installation','kind'=>'checkBox','value'=>true]],[],'Install',true)]);}
                elseif($route==='plugins/appearance'){$p->title='Appearance';$options=[['value'=>'core','label'=>'Default']];foreach($all as $m){if($m['type']==='renderer'){$options[]=['value'=>$m['id'],'label'=>$m['name'],'literal'=>true];}}$p->section('Renderer',[$p->form('renderer.select','',['plugin'=>['label'=>'Renderer','kind'=>'choice','value'=>$current,'options'=>$options]],[],'Use')]);}
                else{$id=(string)($_GET['id']??'');$m=null;foreach($all as $item){if($item['id']===$id){$m=$item;break;}}if(!$m){throw new Problem('Plugin missing.',404);}$p->title=$m['name'];$p->literalTitle=true;$reason=$a->plugins->removalReason($id);
                    if($route==='plugins/detail'){$items=[];$description=$a->plugins->description($id);if($description!==''){$items[]=$p->text($description,false);}$items[]=$p->text($m['version'],false);$items[]=$p->node('badge',['label'=>$m['enabled']?'Enabled':'Disabled']);$p->section('Plugin',$items);$links=[];if($m['enabled']&&isset($a->plugins->navigation[$id])){$entry=$a->plugins->navigation[$id];$links[]=$p->link('Open',$entry['href'],$entry['icon']);}if($m['type']==='renderer'){$links[]=$p->link('Appearance','/admin/plugins/appearance','palette');}else{$links[]=$p->link($m['enabled']?'Disable':'Enable','/admin/plugins/toggle?id='.$id,'plug');}if($reason===''){$links[]=$p->link('Remove','/admin/plugins/remove?id='.$id,'trash');$links[]=$p->link('Delete data','/admin/plugins/data?id='.$id,'trash');}$p->section('Actions',$links,['directory'=>true]);if($reason!==''){$p->section('Status',[$p->text($reason)]);}}
                    elseif($route==='plugins/toggle'&&$m['type']!=='renderer'){$p->section($m['enabled']?'Disable':'Enable',[$p->form('plugin.toggle','',[],['plugin'=>$id,'enabled'=>$m['enabled']?'0':'1'],$m['enabled']?'Disable':'Enable',true)]);}
                    elseif($route==='plugins/remove'){$p->section('Remove',[$p->text('Data is retained.'),$p->form('plugin.remove','',[],['plugin'=>$id],'Remove',true)]);}
                    elseif($route==='plugins/data'){$p->section('Delete data',[$p->text('A backup is created first.'),$p->form('plugin.data','',[],['plugin'=>$id],'Delete data',true)]);}
                    else{throw new Problem('Page not found.',404);}
                }
            }
        }elseif(str_starts_with($route,'backups')){
            $p->title='Backups';
            if($route==='backups'){$p->section('Actions',[$p->link('Create backup','/admin/backups/new','plus'),$p->link('Import','/admin/backups/import','upload'),$p->link('Schedule','/admin/backups/settings','clock-rotate-left'),$p->link('Jobs','/admin/backups/jobs','server')],['directory'=>true]);$rows=[];foreach($a->db->all('SELECT * FROM backups ORDER BY created_at DESC') as $b){$rows[]=['date'=>['label'=>$b['created_at'],'literal'=>true,'href'=>'/admin/backups/detail?id='.$b['id']],'size'=>Pages::bytes($b['size'])];}$p->section('Backups',[$p->table('Backups',['date'=>'Date','size'=>'Size'],$rows)]);}
            else{$p->section('',[$p->link('Backups','/admin/backups','arrow-rotate-left')],['directory'=>true]);
                if($route==='backups/new'){$p->title='Create backup';$p->section('Backup',[$p->form('backup.create','',[],[],'Create backup')]);}
                elseif($route==='backups/import'){$p->title='Import backup';$p->section('Backup',[$p->form('backup.import','',['backup'=>['label'=>'Backup file','kind'=>'filePicker','accept'=>'.zip','required'=>true]],[],'Import')]);}
                elseif($route==='backups/settings'){$p->title='Schedule';$p->section('Schedule',[$p->form('backup.settings','',['automatic'=>['label'=>'Daily backup','kind'=>'checkBox','value'=>$a->setting('backup_daily','1')==='1']])]);}
                elseif($route==='backups/jobs'){$p->title='Jobs';$a->jobs->scheduleBackups();$p->section('Worker',[$p->text($a->setting('worker_last')?:'Not run yet.')]);$rows=$a->db->all("SELECT id,created_at,type,status,error,due_at FROM jobs ORDER BY CASE WHEN status IN ('queued','running') THEN 0 ELSE 1 END,created_at DESC LIMIT 100");foreach($rows as &$row){if($row['type']==='backup'&&str_starts_with($row['id'],'daily-')){$row['type']='Daily backup';}if($row['status']==='queued'&&$row['due_at']>time()){$row['status']='Scheduled';}if($row['due_at']>0){$row['created_at']=gmdate('c',$row['due_at']);}}unset($row);$p->section('Jobs',[$p->table('Jobs',['created_at'=>'Date','type'=>'Job','status'=>'Status','error'=>'Error'],$rows)]);}
                else{$id=(string)($_GET['id']??'');$b=$a->db->one('SELECT * FROM backups WHERE id=?',[$id])??throw new Problem('Backup missing.',404);
                    if($route==='backups/detail'){$p->section('Backup',[$p->text($b['created_at'],false),$p->text(Pages::bytes($b['size']),false)]);$p->section('Actions',[$p->link('Download','/admin/backup/'.$id,'download'),$p->link('Restore','/admin/backups/restore?id='.$id,'arrow-rotate-left'),$p->link('Delete','/admin/backups/delete?id='.$id,'trash')],['directory'=>true]);}
                    elseif($route==='backups/delete'){$p->title='Delete backup';$p->section('Backup',[$p->text($b['created_at'],false),$p->form('backup.delete','',[],['backup'=>$id],'Delete',true)]);}
                    elseif($route==='backups/restore'){$p->title='Restore';$fields=['password'=>['label'=>'Current password','kind'=>'passwordField','required'=>true]];if($a->auth->hasFactor($u)){$fields['proof']=['label'=>'Current 2FA / recovery code','required'=>true];}$fields['confirm']=['label'=>'Replace current data','kind'=>'checkBox','required'=>true];$p->section('Restore',[$p->text($b['created_at'],false),$p->text('Current data is backed up before restoration.'),$p->form('backup.restore','',$fields,['backup'=>$id],'Restore',true)]);}
                    else{throw new Problem('Page not found.',404);}
                }
            }
        }else{throw new Problem('Page not found.',404);}return $p->build();
    }
}
