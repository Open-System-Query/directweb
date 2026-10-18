<?php
declare(strict_types=1);
namespace DirectWebMail;
use DirectWeb\Core\{App,Problem};
use DirectWeb\Ui\Surface;
final class Pages
{
    public function __construct(private App $app,private Smtp $smtp){}
    public function page(string $route,array $u): array
    {
        $a=$this->app;$a->auth->requireOwner($u);if(isset($u['_token'])){throw new Problem('Access denied.',403);}
        $s=$this->smtp->settings();$p=new Surface($a,'Mail','plugin.mail'.($route!==''?'.'.$route:''),$u);
        if($route===''){
            $status=$a->mail->status();$labels=['disabled'=>'Disabled','unconfigured'=>'Not configured','untested'=>'Configured, untested','tested'=>'Successfully tested','failed'=>'Last send failed'];
            $items=[$p->text('Sends verification codes and plugin notifications through your SMTP provider.'),$p->node('badge',['label'=>$labels[$status['state']]])];
            if($status['last_success_at']!==null){$items[]=$p->node('valueDisplay',['label'=>'Last successful send','value'=>gmdate('Y-m-d H:i:s',strtotime($status['last_success_at'])).' UTC']);}
            if($status['state']==='failed'){$items[]=$p->node('alert',['title'=>'Last send failed','message'=>$status['error'],'severity'=>'error']);$items[]=$p->node('valueDisplay',['label'=>'Last failure','value'=>gmdate('Y-m-d H:i:s',strtotime($status['last_failure_at'])).' UTC']);}
            $p->section('Outgoing email',$items,['icon'=>'envelope']);
            $links=[$p->link('Settings','/admin/mail/settings','gear')];if($this->smtp->ready()){$links[]=$p->link('Test email','/admin/mail/test','envelope');}$links[]=$p->link('Plugin','/admin/plugins/detail?id=mail','plug');$p->section('Actions',$links,['directory'=>true,'icon'=>'gear']);
            $users=(int)$a->db->one('SELECT COUNT(*) AS total FROM users WHERE enabled=1 AND email_mfa=1')['total'];
            $rules=0;if($a->plugins->enabled('notifications')){foreach($a->extensions->records('notifications') as $r){if(!empty($r['data']['enabled'])&&!empty($r['data']['email'])){$rules++;}}}
            $p->section('Usage',[$p->node('valueDisplay',['label'=>'Accounts using email 2FA','value'=>(string)$users]),$p->node('valueDisplay',['label'=>'Email notification rules','value'=>(string)$rules])],['icon'=>'link']);
        }elseif($route==='settings'){
            $p->title='Mail settings';$p->section('',[$p->link('Mail','/admin/mail','arrow-rotate-left')],['directory'=>true]);
            $fields=['host'=>['label'=>'SMTP host','required'=>true,'value'=>$s['host']],'port'=>['label'=>'Port','kind'=>'numberField','value'=>$s['port'],'min'=>1,'max'=>65535],'encryption'=>['label'=>'Encryption','kind'=>'choice','value'=>$s['encryption'],'options'=>[['value'=>'starttls','label'=>'STARTTLS'],['value'=>'tls','label'=>'TLS']]],'username'=>['label'=>'SMTP username','value'=>$s['username']],'password'=>['label'=>'SMTP password','kind'=>'passwordField'],'from'=>['label'=>'Sender','content_type'=>'email','required'=>true,'value'=>$s['from']],'current'=>['label'=>'Current password','kind'=>'passwordField','autocomplete'=>'current-password','required'=>true]];
            if($a->auth->hasFactor($u)){$fields['proof']=['label'=>'Current 2FA / recovery code','required'=>true];}
            $p->section('SMTP connection',[$p->form('mail.save','',$fields)],['icon'=>'envelope']);
        }elseif($route==='test'){
            $p->title='Test email';$p->section('',[$p->link('Mail','/admin/mail','arrow-rotate-left')],['directory'=>true]);
            if(!$this->smtp->ready()){throw new Problem('Email is not configured.');}
            $p->section('Test email',[$p->form('mail.test','',['recipient'=>['label'=>'Recipient','content_type'=>'email','required'=>true,'max_length'=>254,'value'=>'']],[],'Send test')],['icon'=>'envelope']);
        }else{throw new Problem('Page not found.',404);}
        return $p->build();
    }
}
