<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"monitor","Monitor") extends Module {

protected function fields(): array {return ['url'=>['label'=>'URL','required'=>true],'interval'=>['label'=>'Interval (minutes)','kind'=>'numberField','value'=>15,'min'=>5,'max'=>1440],'status'=>['label'=>'Expected status','kind'=>'numberField','value'=>200,'min'=>100,'max'=>599],'days'=>['label'=>'Certificate warning (days)','kind'=>'numberField','value'=>14,'min'=>1,'max'=>90],'enabled'=>['label'=>'Enabled','kind'=>'checkBox','value'=>true]];}
protected function validate(array $d,?array $old): array {if(!filter_var($d['url'],FILTER_VALIDATE_URL)||!str_starts_with($d['url'],'https://')){throw new Problem('Use an HTTPS URL.');}return $d;}
protected function operations(): array {return ['check'=>'Check now','history'=>'History'];}
protected function execute(string $op,array $u,array $r,array $i): array
{
    if($op==='history'){return ['checks'=>array_slice($this->app->extensions->records('monitor-result',$r['id']),0,100)];}
    $start=microtime(true);try{$response=Client::request('HEAD',$r['data']['url']);$out=['status'=>$response['status'],'milliseconds'=>(int)((microtime(true)-$start)*1000),'certificate_expires'=>$response['certificate_expires'],'healthy'=>$response['status']===$r['data']['status']&&($response['certificate_expires']??0)>time()+$r['data']['days']*86400];}catch(Problem $e){$out=['healthy'=>false,'message'=>$e->getMessage()];}
    $this->app->extensions->save('monitor-result','Check',$r['id'],$out);if(!$out['healthy']){$this->app->extensions->emit('monitor.failed',$u['id'],['site'=>$r['site'],'monitor'=>$r['id']]+$out);}return $out;
}
protected function extend(Registry $registry): void {$registry->tasks['monitor.schedule']=function(){foreach($this->app->extensions->records($this->id) as $r){if(!$r['data']['enabled']){continue;}$period=(int)floor(time()/($r['data']['interval']*60));$owner=$this->app->db->one("SELECT * FROM users WHERE role='owner' AND enabled=1 LIMIT 1");if($owner){$this->app->jobs->add($owner,'monitor.run','monitor-'.$r['id'].'-'.$period,['id'=>$r['id'],'operation'=>'check','revision'=>$r['revision']]);}}$this->app->db->execute("DELETE FROM extension_records WHERE plugin='monitor-result' AND created_at<?",[gmdate('c',time()-2592000)]);};}

};$module->register($registry);
}
};
