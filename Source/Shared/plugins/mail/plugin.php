<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Problem};
use DirectWeb\Plugins\Registry;
require_once __DIR__.'/src/Smtp.php';
require_once __DIR__.'/src/Pages.php';
return new class implements DirectWeb\Plugins\Plugin
{
    public function register(App $app,Registry $registry): void
    {
        if(!$registry->enabled('mail')){return;}
        $smtp=new DirectWebMail\Smtp($app);$pages=new DirectWebMail\Pages($app,$smtp);
        $registry->provide('mail','mail',$smtp);
        $registry->beforeDisable('mail',function()use($app){
            if($app->db->one("SELECT id FROM users WHERE enabled=1 AND email_mfa=1 AND (totp IS NULL OR totp='') LIMIT 1")){throw new Problem('Email-only users must add an authenticator first.');}
        });
        $registry->addPage('mail','Mail','envelope',fn($u)=>$pages->page('',$u),['group'=>'system','roles'=>['owner']]);
        foreach(['settings','test'] as $route){$registry->pages['mail/'.$route]=fn($u)=>$pages->page($route,$u);}
        $registry->addAction('mail.save',function($u,$i)use($app,$smtp){
            $app->auth->requireOwner($u);if(isset($u['_token'])){throw new Problem('Access denied.',403);}
            $app->auth->authorizeChange($u,(string)($i['current']??''),(string)($i['proof']??''));
            $smtp->configure($u,$i);return '/admin/mail/test';
        });
        $registry->addAction('mail.test',function($u,$i)use($app){
            $app->auth->requireOwner($u);if(isset($u['_token'])){throw new Problem('Access denied.',403);}
            $recipient=trim((string)($i['recipient']??''));
            if(strlen($recipient)>254||!filter_var($recipient,FILTER_VALIDATE_EMAIL)){throw new Problem('Invalid email address.');}
            $app->auth->limit('mail-test:'.$u['id'],3,300);
            $app->mail->send($recipient,$app->translator->get('DirectWeb test email'),$app->translator->get('DirectWeb can send email through your SMTP provider.'));
            $app->audit($u['id'],'mail.test');$_SESSION['flash']=$app->translator->get('Test email sent to %s.',$recipient);return '/admin/mail';
        });
    }
};
