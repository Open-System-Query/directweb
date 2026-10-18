<?php
declare(strict_types=1);
namespace DirectWeb\Mail;
use DirectWeb\Core\{App,Problem};
final class Mailer
{
    public function __construct(private App $app)
    {
        if(is_file($app->root.'/plugins/mail/plugin.json')&&$app->setting('migration.mail-plugin')===''){
            $app->db->transaction(function()use($app){
                if($app->setting('migration.mail-plugin')!==''){return;}
                $settings=json_decode($app->setting('mail','{}'),true);
                $manifest=json_decode(file_get_contents($app->root.'/plugins/mail/plugin.json'),true);
                $app->db->execute('INSERT OR IGNORE INTO plugins(id,enabled,version) VALUES(?,?,?)',['mail',(int)!empty($settings['enabled']),$manifest['version']]);
                $app->set('migration.mail-plugin','1');
            });
        }
    }
    public function available(): bool {return $this->transport()!==null;}
    public function ready(): bool {return $this->transport()?->ready()??false;}
    public function status(): array
    {
        $transport=$this->transport();
        if($transport instanceof StatusTransport){return $transport->status();}
        $available=$transport!==null;$configured=$transport?->ready()??false;
        return ['state'=>!$available?'disabled':($configured?'untested':'unconfigured'),'available'=>$available,'configured'=>$configured,'last_attempt_at'=>null,'last_success_at'=>null,'last_failure_at'=>null,'error'=>null];
    }
    private function transport(): ?Transport
    {
        $service=$this->app->plugins->service('mail');
        return $service instanceof Transport?$service:null;
    }
    public function sendCode(string $recipient,string $code): void
    {
        $this->send($recipient,$this->app->translator->get('Your verification code'),$this->app->translator->get('Your code: %s',$code)."\n\n".$this->app->translator->get('Expires in 5 minutes.'));
    }
    public function send(string $recipient,string $subject,string $body): void
    {
        $transport=$this->transport();
        if(!$transport||!$transport->ready()){throw new Problem('Email is not configured.');}
        $transport->send($recipient,$subject,$body);
    }
}
