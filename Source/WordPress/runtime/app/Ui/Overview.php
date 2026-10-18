<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\App;
final class Overview
{
    public function __construct(private App $app){}
    public function page(array $u): array
    {
        $a=$this->app;$p=new Surface($a,'Dashboard','admin.home',$u);$owner=$u['role']==='owner';
        $links=[$p->link('Websites','/admin/sites','globe'),$p->link('Publishing','/admin/wordpress','globe')];if($owner){$links[]=$p->link('Add website','/admin/sites/new','plus');}foreach($a->plugins->navigation as $id=>$entry){if(($entry['group']??'websites')==='system'||(!empty($entry['roles'])&&!in_array($u['role'],$entry['roles'],true))){continue;}$links[]=$p->link($entry['label'],$entry['href'],$entry['icon']);}$p->section('Website management',$links,['directory'=>true,'icon'=>'globe']);
        $links=[$p->link('Account','/admin/account','shield-halved')];if($owner){$links[]=$p->link('Users','/admin/users','users');$links[]=$p->link('Backups','/admin/backups','hard-drive');$links[]=$p->link('Activity','/admin/activity','clock-rotate-left');}$p->section('Administration',$links,['directory'=>true,'icon'=>'server']);
        $links=$owner?[$p->link('Plugins','/admin/plugins','plug'),$p->link('Settings','/admin/settings','gear')]:[];foreach($a->plugins->navigation as $entry){if(($entry['group']??'websites')!=='system'||(!empty($entry['roles'])&&!in_array($u['role'],$entry['roles'],true))){continue;}$links[]=$p->link($entry['label'],$entry['href'],$entry['icon']);}if($links){$p->section('System',$links,['directory'=>true,'icon'=>'gear']);}
        return $p->build();
    }
}
