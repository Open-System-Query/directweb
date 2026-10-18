<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\App;
use DirectWeb\Identity\Totp;
final class Account
{
    public function __construct(private App $app){}
    private function password(string $label='Current password'): array {return ['label'=>$label,'kind'=>'passwordField','autocomplete'=>'current-password','required'=>true];}
    private function code(string $label='Code'): array {return ['label'=>$label,'required'=>true,'max_length'=>32];}
    private function proof(): array {return ['password'=>$this->password(),'proof'=>$this->code('Current 2FA / recovery code')];}
    public function login(): array
    {
        $a=$this->app;$p=new Surface($a,'Sign in','admin.login');$pending=$a->auth->pending();
        if(!$pending){$p->section('Account',[$p->form('login','',['username'=>['label'=>'Username','required'=>true],'password'=>$this->password('Password')],[],'Sign in')]);return $p->build(false);}
        $methods=[];if($pending['totp']){$methods[]=['value'=>'totp','label'=>'Authenticator app'];}if($pending['email_mfa']&&$a->mail->ready()){$methods[]=['value'=>'email','label'=>'Email'];}$methods[]=['value'=>'recovery','label'=>'Recovery code'];
        $p->section('Verify',[$p->form('login.verify','',['method'=>['label'=>'Method','kind'=>'choice','value'=>$methods[0]['value'],'options'=>$methods],'code'=>$this->code()],[],'Verify')]);
        if($pending['email_mfa']&&$a->mail->ready()){$p->section('Email',[$p->form('login.email','',[],[],'Send code')]);}$p->section('Account',[$p->form('login.cancel','',[],[],'Cancel')]);return $p->build(false);
    }
    public function page(array $u): array
    {
        $a=$this->app;$p=new Surface($a,'Account','admin.account',$u);$enabled=$a->auth->hasFactor($u);$method=(string)($_GET['security']??'');
        if($method===''){
            $p->section('Profile',[$p->text($u['name'],false),$p->text($u['username'],false),$p->node('badge',['label'=>$enabled?'2FA enabled':'2FA off'])],['icon'=>'user']);
            $items=[$p->link('Password','/admin/account?security=password','key'),$p->link('Authenticator app','/admin/account?security=totp','shield-halved'),$p->link('Sessions','/admin/account?security=sessions','server')];if($a->mail->available()||$u['email_mfa']){$items[]=$p->link('Email','/admin/account?security=email','envelope');}if(isset($_SESSION['recovery_codes'])){$items[]=$p->link('Recovery codes','/admin/account?security=recovery','key');}if($u['email_mfa']&&$a->mail->ready()){$items[]=$p->link('Verification code','/admin/account?security=proof','envelope');}$p->section('Security',$items,['directory'=>true,'icon'=>'shield-halved']);return $p->build();
        }
        $p->section('',[$p->link('Account','/admin/account','arrow-rotate-left')],['directory'=>true]);
        if($method==='password'){$p->title='Password';$p->section('Password',[$p->form('password.change','',['current'=>$this->password(),'password'=>['label'=>'New password','kind'=>'passwordField','min_length'=>12,'required'=>true]])],['icon'=>'key']);}
        elseif($method==='totp'){
            $p->title='Authenticator app';
            if(!$u['totp']){$_SESSION['totp_setup']??=Totp::secret();$secret=$_SESSION['totp_setup'];$uri='otpauth://totp/'.rawurlencode('DirectWeb:'.$u['username']).'?'.http_build_query(['secret'=>$secret,'issuer'=>'DirectWeb','algorithm'=>'SHA1','digits'=>6,'period'=>30],'','&',PHP_QUERY_RFC3986);$fields=['code'=>$this->code('Authenticator code'),'password'=>$this->password()];if($enabled){$fields['proof']=$this->code('Current 2FA / recovery code');}$p->section('Authenticator app',[$p->text('Scan the QR code.'),$p->node('codeBlock',['text'=>$secret,'metadata'=>['qr'=>$uri]]),$p->form('2fa.enroll','',$fields,[],'Enable')],['enrollment'=>true]);}
            else{$p->section('Authenticator app',[$p->node('badge',['label'=>'Enabled']),$p->form('2fa.remove','',$this->proof(),['method'=>'totp'],'Disable',true)]);}
        }elseif($method==='email'){
            $p->title='Email';$items=[];
            if($u['email_mfa']){$items[]=$p->text($u['email'],false);$items[]=$p->form('2fa.remove','',$this->proof(),['method'=>'email'],'Disable',true);}
            elseif($a->mail->ready()){$pending=$a->db->one('SELECT id FROM email_challenges WHERE user_id=? AND purpose=? AND session_hash=? AND expires_at>?',[$u['id'],'enroll',hash('sha256',session_id()),time()]);
                if($pending&&empty($_GET['resend'])){$items[]=$p->form('2fa.email.verify','',['code'=>$this->code()],[],'Verify');$items[]=$p->link('Send code','/admin/account?security=email&resend=1','envelope');}
                else{$fields=['email'=>['label'=>'Email','required'=>true,'content_type'=>'email','value'=>$u['email']??''],'password'=>$this->password()];if($enabled){$fields['proof']=$this->code('Current 2FA / recovery code');}$items[]=$p->form('2fa.email.send','',$fields,[],'Send code');}
            }else{$items[]=$p->text('Email is not configured.');if($u['role']==='owner'){$items[]=$a->mail->available()?$p->link('Mail settings','/admin/mail/settings','gear'):$p->link('Plugins','/admin/plugins','plug');}}$p->section('Email',$items);
        }elseif($method==='proof'&&$u['email_mfa']){$p->title='Verification code';$p->section('Verification code',[$p->form('2fa.proof.send','',['password'=>$this->password()],[],'Send code')]);}
        elseif($method==='recovery'){$p->title='Recovery codes';$codes=$_SESSION['recovery_codes']??[];$p->section('Recovery codes',[$p->text('Save these codes. They are shown once.'),$p->node('codeBlock',['text'=>implode("\n",$codes)])]);unset($_SESSION['recovery_codes']);}
        elseif($method==='sessions'){$p->title='Sessions';$sessions=$a->db->all('SELECT created_at,seen_at FROM sessions WHERE user_id=? ORDER BY seen_at DESC',[$u['id']]);$p->section('Actions',[$p->link('Revoke other sessions','/admin/account?security=revoke','shield-halved')],['directory'=>true]);$p->section('Sessions',[$p->table('Sessions',['created_at'=>'Started','last'=>'Last active'],array_map(fn($s)=>['created_at'=>$s['created_at'],'last'=>gmdate('Y-m-d H:i',$s['seen_at'])],$sessions))]);}
        elseif($method==='revoke'){$p->title='Sessions';$p->section('Revoke',[$p->form('sessions.revoke','',[],[],'Revoke other sessions',true)]);}
        else{throw new \DirectWeb\Core\Problem('Page not found.',404);}return $p->build();
    }
}
