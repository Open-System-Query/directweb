<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"notifications","Notifications") extends Module {

protected bool $siteScoped=false;
protected function fields(): array {return ['event'=>['label'=>'Event','kind'=>'choice','value'=>'release.published','options'=>array_map(fn($e)=>['value'=>$e,'label'=>$e],['release.published','backup.created','job.failed','monitor.failed','form.received','sharing.downloaded'])],'email'=>['label'=>'Email (Mail plugin)'],'webhook'=>['label'=>'Webhook URL'],'secret'=>['label'=>'Webhook secret','kind'=>'passwordField'],'enabled'=>['label'=>'Enabled','kind'=>'checkBox','value'=>true]];}
protected function validate(array $d,?array $old): array {if($d['email']!==''&&!filter_var($d['email'],FILTER_VALIDATE_EMAIL)){throw new Problem('Invalid email.');}if($d['email']!==''&&!$this->app->mail->ready()){throw new Problem('Configure the Mail plugin to send email notifications.');}if($d['webhook']!==''&&(!filter_var($d['webhook'],FILTER_VALIDATE_URL)||!str_starts_with($d['webhook'],'https://'))){throw new Problem('Use an HTTPS URL.');}return $d;}
protected function operations(): array {return ['inbox'=>'Inbox','test'=>'Send test'];}
protected function execute(string $op,array $u,array $r,array $i): array {if($op==='inbox'){return ['messages'=>array_slice($this->app->extensions->records('notification-message',$r['id']),0,100)];}return $this->deliver($r,['event'=>'notification.test','created_at'=>gmdate('c'),'data'=>[]]);}
private function deliver(array $r,array $message): array
{
    $this->app->extensions->save('notification-message',$message['event'],$r['id'],$message);$json=Files::json($message);$sent=[];$errors=[];
    if($r['data']['email']!==''){try{$this->app->mail->send($r['data']['email'],'DirectWeb · '.$message['event'],$json);$sent[]='email';}catch(Problem $e){$errors['email']=$this->app->translator->get($e->key);}}
    if($r['data']['webhook']!==''){try{$headers=['Content-Type'=>'application/json'];$secret=$this->secret($r,'secret');if($secret!==''){$headers['X-DirectWeb-Signature']='sha256='.hash_hmac('sha256',$json,$secret);}$response=Client::request('POST',$r['data']['webhook'],$headers,$json);if($response['status']<200||$response['status']>=300){throw new Problem('Webhook failed.');}$sent[]='webhook';}catch(Problem $e){$errors['webhook']=$this->app->translator->get($e->key);}}return ['stored'=>true,'sent'=>$sent,'errors'=>$errors];
}
protected function extend(Registry $registry): void
{
    foreach(['release.published','backup.created','job.failed','monitor.failed','form.received','sharing.downloaded'] as $event){$registry->on($event,'notifications.'.str_replace('.','-',$event),function($u,$data,$e)use($event){$deliveries=[];foreach($this->app->extensions->records($this->id) as $r){if($r['data']['enabled']&&$r['data']['event']===$event){$deliveries[$r['id']]=$this->deliver($r,['event'=>$event,'created_at'=>$e['created_at'],'data'=>$data]);}}return ['deliveries'=>$deliveries];});}
    $registry->tasks['notifications.retention']=fn()=>$this->app->db->execute("DELETE FROM extension_records WHERE plugin='notification-message' AND created_at<?",[gmdate('c',time()-7776000)]);
}

};$module->register($registry);
}
};
