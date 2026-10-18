<?php
declare(strict_types=1);
namespace DirectWebMail;
use DirectWeb\Core\{App,Files,Problem};
final class Smtp implements \DirectWeb\Mail\StatusTransport
{
    public function __construct(private App $app){}
    public function settings(): array {return json_decode($this->app->setting('mail','{}'),true)+['host'=>'','port'=>587,'encryption'=>'starttls','username'=>'','password'=>'','from'=>''];}
    public function ready(): bool {$s=$this->settings();return $this->app->plugins->enabled('mail')&&$s['host']!==''&&$s['from']!=='';}
    private function fingerprint(array $s): string
    {
        $config=[];foreach(['host','port','encryption','username','password','from'] as $key){$config[$key]=$s[$key];}
        return hash_hmac('sha256',Files::json($config),file_get_contents($this->app->storage('database/key')));
    }
    public function status(): array
    {
        $available=$this->app->plugins->enabled('mail');$configured=$this->ready();
        $out=['state'=>!$available?'disabled':($configured?'untested':'unconfigured'),'available'=>$available,'configured'=>$configured,'last_attempt_at'=>null,'last_success_at'=>null,'last_failure_at'=>null,'error'=>null];
        $saved=json_decode($this->app->setting('mail.status','{}'),true);
        if(!$configured||!is_array($saved)||!hash_equals($this->fingerprint($this->settings()),(string)($saved['fingerprint']??''))){return $out;}
        foreach(['last_attempt_at','last_success_at','last_failure_at','error'] as $key){$out[$key]=$saved[$key]??null;}
        $out['state']=$out['error']!==null?'failed':($out['last_success_at']!==null?'tested':'untested');
        return $out;
    }
    private function record(array $settings,?string $error): void
    {
        $fingerprint=$this->fingerprint($settings);
        $this->app->db->transaction(function()use($fingerprint,$error){
            if(!hash_equals($fingerprint,$this->fingerprint($this->settings()))){return;}
            $previous=json_decode($this->app->setting('mail.status','{}'),true);
            if(!is_array($previous)||($previous['fingerprint']??'')!==$fingerprint){$previous=[];}
            $now=gmdate('c');
            $this->app->set('mail.status',Files::json(['fingerprint'=>$fingerprint,'last_attempt_at'=>$now,'last_success_at'=>$error===null?$now:($previous['last_success_at']??null),'last_failure_at'=>$error!==null?$now:($previous['last_failure_at']??null),'error'=>$error]));
        });
    }
    private function failure(?\PHPMailer\PHPMailer\PHPMailer $mail): string
    {
        if(!$mail){return 'Email delivery failed.';}
        $smtp=$mail->getSMTPInstance();$error=$smtp->getError();$code=$smtp instanceof Connection?$smtp->failureCode:(int)($error['smtp_code']??0);
        if(str_contains(strtolower($mail->ErrorInfo),'could not authenticate')){return 'SMTP authentication failed.';}
        if(in_array($code,[530,534,535,538],true)){return 'SMTP authentication failed.';}
        if(in_array($code,[421,450,451,452,454],true)){return 'SMTP server temporarily unavailable.';}
        if($code>=500){return 'SMTP server rejected the message.';}
        return $code===0?'SMTP connection failed.':'Email delivery failed.';
    }
    public function configure(array $u,array $input): void
    {
        $this->app->auth->requireOwner($u);if(!$this->app->plugins->enabled('mail')){throw new Problem('Email is not configured.');}$this->app->db->transaction(function()use($u,$input){$s=$this->settings();
        $host=trim((string)($input['host']??''));$port=(int)($input['port']??587);$encryption=(string)($input['encryption']??'starttls');$from=trim((string)($input['from']??''));$username=(string)($input['username']??'');$password=(string)($input['password']??'');
        if(!preg_match('/^[a-zA-Z0-9.-]{1,253}$/D',$host)||$port<1||$port>65535||!filter_var($from,FILTER_VALIDATE_EMAIL)||strlen($from)>254||strlen($username)>254||strlen($password)>1024||!in_array($encryption,['starttls','tls','none'],true)){throw new Problem('Check SMTP settings.');}
        if($encryption==='none'&&($this->app->config['secure']||!in_array($host,['localhost','127.0.0.1'],true))){throw new Problem('SMTP encryption required.');}
        $storedPassword=$s['password'];if($password!==''&&($storedPassword===''||!hash_equals($this->app->unseal($storedPassword),$password))){$storedPassword=$this->app->seal($password);}
        $settings=['host'=>$host,'port'=>$port,'encryption'=>$encryption,'from'=>$from,'username'=>$username,'password'=>$storedPassword];if($username===''){$settings['password']='';}
        if(!hash_equals($this->fingerprint($s),$this->fingerprint($settings))){$this->app->db->execute("DELETE FROM settings WHERE key='mail.status'");}
        $this->app->set('mail',Files::json($settings));$this->app->audit($u['id'],'mail.configured');});
    }
    public function send(string $recipient,string $subject,string $body): void
    {
        if(!$this->ready()){throw new Problem('Email is not configured.');}
        if(strlen($recipient)>254||!filter_var($recipient,FILTER_VALIDATE_EMAIL)){throw new Problem('Invalid email address.');}
        $s=$this->settings();$mail=null;
        try{
            foreach(['Exception','PHPMailer','SMTP'] as $class){require_once dirname(__DIR__).'/packages/phpmailer/'.$class.'.php';}
            require_once __DIR__.'/Connection.php';
            $mail=new \PHPMailer\PHPMailer\PHPMailer(true);$mail->setSMTPInstance(new Connection());$mail->isSMTP();$mail->Host=$s['host'];$mail->Port=$s['port'];$mail->Timeout=10;$mail->getSMTPInstance()->Timelimit=15;$mail->CharSet='UTF-8';$mail->SMTPDebug=0;$mail->SMTPAuth=$s['username']!=='';$mail->Username=$s['username'];$mail->Password=$s['password']!==''?$this->app->unseal($s['password']):'';$mail->SMTPSecure=match($s['encryption']){'starttls'=>'tls','tls'=>'ssl',default=>''};$mail->SMTPAutoTLS=$s['encryption']!=='none';$mail->setFrom($s['from'],'DirectWeb');$mail->addAddress($recipient);$mail->Subject=$subject;$mail->Body=$body;$mail->send();
        }catch(\Throwable $e){$error=$this->failure($mail);$this->record($s,$error);throw new Problem($error,503);}
        $this->record($s,null);
    }
}
