<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\{App,Problem};
final class Pages
{
    public function __construct(private App $app){}
    public static function bytes(int $v): string {return $v>=1073741824?round($v/1073741824,1).' GB':($v>=1048576?round($v/1048576,1).' MB':($v>=1024?round($v/1024,1).' KB':$v.' B'));}
    public function page(string $route,?array $u): array
    {
        $a=$this->app;$p=new Surface($a,'Dashboard','admin.'.str_replace('/','.',$route?:'home'),$u);
        if($route==='setup'){$setup=new \DirectWeb\Hosting\Setup($a);$setup->prepare();$p->title='Welcome to DirectWeb';$fields=['name'=>['label'=>'Name','required'=>true],'username'=>['label'=>'Username','required'=>true],'password'=>['label'=>'Password','kind'=>'passwordField','min_length'=>12,'required'=>true]];$items=[];if(!$setup->local()){$fields['setup_key']=['label'=>'Installation key','kind'=>'passwordField','required'=>true];$items[]=$p->text('Read the key in storage/database/setup.key using your hosting file manager.');}$items[]=$p->form('setup','',$fields,[],'Install');$p->section('Install',$items,['icon'=>'key']);return $p->build(false);}
        if($route==='login'){return (new Account($a))->login();}
        if(!$u){throw new Problem('Sign in required.',401);}
        if($route==='account'){return (new Account($a))->page($u);}
        if(isset($a->plugins->pages[$route])){return ($a->plugins->pages[$route])($u);}
        if($route===''||$route==='home'){return (new Overview($a))->page($u);}
        if(preg_match('~^(?:sites?|files|release)(?:/|$)~',$route)){return (new SitePages($a))->page($route,$u);}
        $a->auth->requireOwner($u);
        if(preg_match('~^(?:users?|plugins|backups)(?:/|$)~',$route)){return (new SystemPages($a))->page($route,$u);}
        if($route==='activity'){$p->title='Activity';$rows=$a->db->all('SELECT a.created_at,a.action,COALESCE(u.name,a.actor) AS actor,a.target FROM audit a LEFT JOIN users u ON u.id=a.actor ORDER BY a.id DESC LIMIT 100');$p->section('Recent actions',[$p->table('Activity',['created_at'=>'Date','actor'=>'User','action'=>'Action','target'=>'Target'],$rows)]);}
        elseif($route==='settings'){$p->title='Settings';$p->section('Settings',[$p->link('General','/admin/general','gear'),$p->link('Hosting','/admin/hosting','server')],['directory'=>true,'icon'=>'gear']);}
        elseif($route==='hosting'){return (new \DirectWeb\Hosting\Setup($a))->page($u);}
        elseif($route==='general'){$p->title='General';$p->section('General',[$p->form('settings.save','',['locale'=>['label'=>'Language','kind'=>'choice','value'=>$a->setting('locale','en'),'options'=>$a->translator->options()],'retention'=>['label'=>'Releases to retain','kind'=>'numberField','value'=>$a->setting('retention','20')]])]);}
        else{throw new Problem('Page not found.',404);}return $p->build();
    }
}
