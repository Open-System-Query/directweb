<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"hosting-connector","Hosting Connector") extends Module {

protected bool $siteScoped=false;
protected function fields(): array {return ['endpoint'=>['label'=>'DirectAdmin URL','required'=>true],'username'=>['label'=>'Username','required'=>true],'key'=>['label'=>'Login key','kind'=>'passwordField','required'=>true]];}
protected function validate(array $d,?array $old): array {if(!preg_match('~^https://[a-z0-9.-]+(?::[0-9]+)?/?$~iD',$d['endpoint'])||str_contains($d['username'],':')){throw new Problem('Check connection.');}return $d;}
protected function operations(): array {return ['domains'=>'Domains','domain'=>'Add domain','mailboxes'=>'Mailboxes','mailbox'=>'Add mailbox','certificates'=>'Certificates','settings'=>'Hosting settings','database'=>'Create database','certificate'=>'Request certificate','settings-save'=>'Change hosting setting'];}
protected function operationFields(string $op,array $r): array
{
    $domain=['domain'=>['label'=>'Domain','required'=>true]];
    return match($op){'certificate'=>$domain+['email'=>['label'=>'Email','required'=>true]],'settings-save'=>$domain+['setting'=>['label'=>'Setting','kind'=>'choice','value'=>'php','options'=>[['value'=>'php','label'=>'PHP'],['value'=>'cgi','label'=>'CGI'],['value'=>'ssl','label'=>'SSL']]],'enabled'=>['label'=>'Enabled','kind'=>'checkBox','value'=>true]],'domain'=>$domain+['bandwidth'=>['label'=>'Bandwidth (MB)','kind'=>'numberField','value'=>1000,'min'=>1],'quota'=>['label'=>'Disk (MB)','kind'=>'numberField','value'=>1000,'min'=>1]],'mailboxes','certificates','settings'=>$domain,'mailbox'=>$domain+['user'=>['label'=>'Mailbox','required'=>true],'password'=>['label'=>'Password','kind'=>'passwordField','required'=>true],'quota'=>['label'=>'Quota (MB)','kind'=>'numberField','value'=>100,'min'=>1]],'database'=>['name'=>['label'=>'Database','required'=>true],'user'=>['label'=>'Username','required'=>true],'password'=>['label'=>'Password','kind'=>'passwordField','required'=>true]],default=>[]};
}
protected function execute(string $op,array $u,array $r,array $i): array
{
    $method='GET';$values=[];$domain=(string)($i['domain']??'');if($domain!==''){Files::host($domain);}
    $command=match($op){'domains'=>'CMD_API_SHOW_DOMAINS','domain','settings-save'=>'CMD_API_DOMAIN','mailboxes','mailbox'=>'CMD_API_POP','certificates','certificate'=>'CMD_API_SSL','settings'=>'CMD_API_ADDITIONAL_DOMAINS','database'=>'CMD_API_DATABASES'};
    if(in_array($op,['mailboxes','certificates','settings'],true)){$values=['domain'=>$domain];if($op==='mailboxes'){$values['action']='full_list';}if($op==='settings'){$values['action']='view';}}
    elseif($op==='domain'){$method='POST';$values=['action'=>'create','domain'=>$domain,'bandwidth'=>(int)($i['bandwidth']??1000),'quota'=>(int)($i['quota']??1000),'ssl'=>'ON','cgi'=>'OFF','php'=>'ON'];}
    elseif($op==='mailbox'){$method='POST';$values=['action'=>'create','domain'=>$domain,'user'=>(string)($i['user']??''),'passwd'=>(string)($i['password']??''),'passwd2'=>(string)($i['password']??''),'quota'=>(int)($i['quota']??100)];}
    elseif($op==='database'){$method='POST';$values=['action'=>'create','name'=>(string)($i['name']??''),'user'=>(string)($i['user']??''),'passwd'=>(string)($i['password']??''),'passwd2'=>(string)($i['password']??'')];}
    elseif($op==='certificate'){$method='POST';$email=(string)($i['email']??'');if(!filter_var($email,FILTER_VALIDATE_EMAIL)){throw new Problem('Invalid email.');}$values=['domain'=>$domain,'action'=>'save','type'=>'create','request'=>'letsencrypt','name'=>$domain,'email'=>$email,'keysize'=>4096,'encryption'=>'sha256','le_select0'=>$domain];}
    elseif($op==='settings-save'){
        $old=$this->execute('settings',$u,$r,['domain'=>$domain]);if(isset($old[$domain])&&is_string($old[$domain])){parse_str($old[$domain],$old);}if(isset($old[$domain])&&is_array($old[$domain])){$old=$old[$domain];}
        foreach(['php','cgi','ssl','quota','bandwidth'] as $required){if(!array_key_exists($required,$old)){throw new Problem('Hosting settings unavailable.');}}
        $this->app->extensions->save('hosting-history','Before update',$r['id'],$old);$setting=(string)($i['setting']??'');if(!in_array($setting,['php','cgi','ssl'],true)){throw new Problem('Invalid setting.');}
        $values=array_filter($old,'is_scalar');$values['domain']=$domain;$values['action']='modify';$values[$setting]=!empty($i['enabled'])?'ON':'OFF';foreach(['quota','bandwidth'] as $key){if($values[$key]==='unlimited'){$values['u'.$key]='unlimited';unset($values[$key]);}}$method='POST';
    }
    $values['json']='yes';$query=http_build_query($values);$url=rtrim($r['data']['endpoint'],'/').'/'.$command.($method==='GET'&&$query!==''?'?'.$query:'');$response=Client::request($method,$url,['Authorization'=>'Basic '.base64_encode($r['data']['username'].':'.$this->secret($r,'key')),'Content-Type'=>'application/x-www-form-urlencoded'],$method==='POST'?$query:'');if($response['status']!==200){throw new Problem('Hosting request failed.');}$data=json_decode($response['body'],true);if(!is_array($data)){parse_str($response['body'],$data);}if(!empty($data['error'])){throw new Problem('Hosting provider rejected the request.');}return $data;
}

};$module->register($registry);
}
};
