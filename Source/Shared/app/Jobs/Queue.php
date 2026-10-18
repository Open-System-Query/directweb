<?php
declare(strict_types=1);
namespace DirectWeb\Jobs;
use DirectWeb\Core\{App,Files,Problem};
final class Queue
{
    public function __construct(private App $app)
    {
        $app->db->pdo->exec("CREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY,actor TEXT NOT NULL,type TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'queued',result TEXT,error TEXT,created_at TEXT NOT NULL,updated_at INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0)");
        $app->db->transaction(function()use($app){$columns=array_column($app->db->all('PRAGMA table_info(jobs)'),'name');if(!in_array('payload',$columns,true)){$app->db->pdo->exec("ALTER TABLE jobs ADD COLUMN payload TEXT NOT NULL DEFAULT '{}'");}if(!in_array('due_at',$columns,true)){$app->db->pdo->exec('ALTER TABLE jobs ADD COLUMN due_at INTEGER NOT NULL DEFAULT 0');}});
    }
    public function add(array $u,string $type,?string $id=null,array $payload=[],int $due=0): string
    {
        if($type==='backup'){$this->app->auth->requireOwner($u);}elseif(!isset($this->app->plugins->jobHandlers[$type])){throw new Problem('Unknown job.');}
        if(empty($u['enabled'])){throw new Problem('Access denied.',403);}$id??=Files::id();
        $json=Files::json($payload);if(strlen($json)>1048576){throw new Problem('Job too large.');}
        $this->app->db->execute('INSERT OR IGNORE INTO jobs(id,actor,type,payload,due_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$id,$u['id'],$type,'sealed:'.$this->app->seal($json),$due,gmdate('c'),time()]);
        return $id;
    }
    public function get(array $u,string $id): array {$j=$this->app->db->one('SELECT * FROM jobs WHERE id=?',[$id]);if(!$j||($u['role']!=='owner'&&$j['actor']!==$u['id'])){throw new Problem('Job not found.',404);}return $j;}
    public function scheduleBackups(): void
    {
        $a=$this->app;$a->db->transaction(function()use($a){
            if($a->setting('backup_daily','1')!=='1'){$a->db->execute("DELETE FROM jobs WHERE type='backup' AND id GLOB 'daily-????-??-??' AND status='queued'");return;}
            $owner=$a->db->one("SELECT * FROM users WHERE role='owner' AND enabled=1 ORDER BY created_at LIMIT 1");if(!$owner){return;}
            $today=gmdate('Y-m-d');$id='daily-'.$today;
            $a->db->execute("DELETE FROM jobs WHERE type='backup' AND id GLOB 'daily-????-??-??' AND id<? AND status='queued'",[$id]);
            $current=$a->db->one('SELECT status FROM jobs WHERE id=?',[$id]);
            if(($current&&$current['status']!=='queued')||(!$current&&$a->setting('backup_last')===$today)){$id='daily-'.gmdate('Y-m-d',time()+86400);}
            $this->add($owner,'backup',$id,[],strtotime(substr($id,6).' 00:00:00 UTC'));
        });
    }
    public function usesBackup(string $id): bool
    {
        foreach($this->app->db->all("SELECT payload FROM jobs WHERE type='backup.restore' AND status IN ('queued','running')") as $job){
            $raw=$job['payload'];$payload=json_decode(str_starts_with($raw,'sealed:')?$this->app->unseal(substr($raw,7)):$raw,true);
            if(($payload['backup']??null)===$id){return true;}
        }
        return false;
    }
    public function work(): int
    {
        $a=$this->app;$h=fopen($a->storage('database/worker.lock'),'c');if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if($h){fclose($h);}return 0;}$done=0;
        try{
            $this->scheduleBackups();
            $a->db->execute("UPDATE jobs SET status='queued' WHERE status='running' AND attempts<3");
            $a->db->execute("UPDATE jobs SET status='failed',error='Job interrupted.' WHERE status='running' AND attempts>=3");
            foreach($a->db->all("SELECT * FROM jobs WHERE status='queued' AND due_at<=? ORDER BY created_at LIMIT 10",[time()]) as $j){
                if($j['type']!=='backup'&&!isset($a->plugins->jobHandlers[$j['type']])){continue;}
                $a->db->execute("UPDATE jobs SET status='running',attempts=attempts+1,updated_at=? WHERE id=?",[time(),$j['id']]);
                try{
                    $u=$a->auth->user($j['actor']);if(!$u['enabled']){throw new Problem('Account disabled.',403);}
                    if($j['type']==='backup'){$result=$a->backups->create($u);$a->backups->verify($result['id']);$a->extensions->emit('backup.created',$u['id'],$result);}
                    else{$result=($a->plugins->jobHandlers[$j['type']])($u,json_decode(str_starts_with($j['payload'],'sealed:')?$a->unseal(substr($j['payload'],7)):$j['payload'],true),$j);}
                    $a->db->execute("UPDATE jobs SET status='done',result=?,error=NULL,updated_at=? WHERE id=?",[Files::json($result??[]),time(),$j['id']]);$done++;if($j['type']==='backup.restore'){break;}
                }catch(\Throwable $e){
                    error_log('DirectWeb job '.$j['id'].': '.$e->getMessage());
                    $message=$e instanceof Problem?$e->getMessage():'Job failed.';
                    $a->db->execute("UPDATE jobs SET status='failed',error=?,updated_at=? WHERE id=?",[$message,time(),$j['id']]);
                    if(!str_starts_with($j['type'],'event.')){$a->extensions->emit('job.failed',$j['actor'],['job'=>$j['id'],'type'=>$j['type'],'message'=>$message]);}
                }
            }
            if($a->setting('worker_restart')!=='1'){$this->scheduleBackups();}$a->set('worker_last',gmdate('c'));return $done;
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
}
