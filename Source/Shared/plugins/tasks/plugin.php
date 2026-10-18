<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"tasks","Tasks") extends Module {

protected function fields(): array {return ['operation'=>['label'=>'Task','kind'=>'choice','value'=>'publish','options'=>[['value'=>'publish','label'=>'Publish release'],['value'=>'backup','label'=>'Backup'],['value'=>'prune','label'=>'Prune releases']]],'release'=>['label'=>'Release ID'],'at'=>['label'=>'Start (UTC)','value'=>gmdate('Y-m-d H:i',time()+3600),'required'=>true],'repeat'=>['label'=>'Repeat (minutes, 0 = once)','kind'=>'numberField','value'=>0,'min'=>0,'max'=>525600],'enabled'=>['label'=>'Enabled','kind'=>'checkBox','value'=>true]];}
protected function validate(array $d,?array $old): array {if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D',$d['at'])||strtotime($d['at'].' UTC')===false){throw new Problem('Use YYYY-MM-DD HH:MM.');}return $d;}
protected function operations(): array {return ['run'=>'Run now'];}
protected function execute(string $op,array $u,array $r,array $i): array
{
    return match($r['data']['operation']){'publish'=>$this->app->sites->restore($u,$r['site'],$r['data']['release'],$this->app->sites->get($r['site'])['revision']),'backup'=>['job'=>$this->app->jobs->add($u,'backup')],'prune'=>['removed'=>$this->app->sites->prune($u,$r['site'])]};
}
protected function extend(Registry $registry): void {$registry->tasks['tasks.schedule']=function(){foreach($this->app->extensions->records($this->id) as $r){$start=strtotime($r['data']['at'].' UTC');if(!$r['data']['enabled']||$start>time()){continue;}$period=$r['data']['repeat']>0?(int)floor((time()-$start)/($r['data']['repeat']*60)):0;$audit=$this->app->db->one('SELECT actor FROM audit WHERE action=? AND target=? ORDER BY id DESC LIMIT 1',[$this->id.'.saved',$r['id']]);$u=$audit?$this->app->db->one('SELECT * FROM users WHERE id=? AND enabled=1',[$audit['actor']]):null;if($u){$this->app->jobs->add($u,'tasks.run','task-'.$r['id'].'-'.$r['revision'].'-'.$period,['id'=>$r['id'],'operation'=>'run','revision'=>$r['revision']]);}}};}

};$module->register($registry);
}
};
