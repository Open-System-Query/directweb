<?php
declare(strict_types=1);
namespace DirectWeb\Plugins;
use DirectWeb\Core\{App,Files,Problem};
final class Extensions
{
    public function __construct(private App $app)
    {
        $app->db->pdo->exec("CREATE TABLE IF NOT EXISTS extension_versions(record TEXT NOT NULL,revision INTEGER NOT NULL,data TEXT NOT NULL,created_at TEXT NOT NULL,PRIMARY KEY(record,revision));CREATE TABLE IF NOT EXISTS extension_records(id TEXT PRIMARY KEY,plugin TEXT NOT NULL,site TEXT NOT NULL DEFAULT '',name TEXT NOT NULL,data TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 1,deleted INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);CREATE INDEX IF NOT EXISTS extension_lookup ON extension_records(plugin,site,deleted);CREATE TABLE IF NOT EXISTS extension_events(id TEXT PRIMARY KEY,name TEXT NOT NULL,actor TEXT NOT NULL,data TEXT NOT NULL,created_at TEXT NOT NULL,dispatched INTEGER NOT NULL DEFAULT 0);CREATE TABLE IF NOT EXISTS extension_deliveries(id TEXT PRIMARY KEY,created_at INTEGER NOT NULL);");
    }
    public function emit(string $name,string $actor,array $data): string
    {
        $id=Files::id();$this->app->db->execute('INSERT INTO extension_events(id,name,actor,data,created_at) VALUES(?,?,?,?,?)',[$id,$name,$actor,Files::json($data),gmdate('c')]);return $id;
    }
    public function dispatch(): void
    {
        foreach($this->app->db->all('SELECT * FROM extension_events WHERE dispatched=0 ORDER BY created_at LIMIT 100') as $event){
            $u=$this->app->db->one('SELECT * FROM users WHERE id=? AND enabled=1',[$event['actor']]);
            if(!$u){$u=$this->app->db->one("SELECT * FROM users WHERE role='owner' AND enabled=1 LIMIT 1");}if(!$u){continue;}
            foreach($this->app->plugins->eventHandlers[$event['name']]??[] as $key=>$handler){$this->app->jobs->add($u,'event.'.$key,'event-'.$event['id'].'-'.$key,['event'=>$event]);}
            $this->app->db->execute('UPDATE extension_events SET dispatched=1 WHERE id=?',[$event['id']]);
        }
    }
    public function records(string $plugin,string $site=''): array
    {
        $rows=$site===''?$this->app->db->all('SELECT * FROM extension_records WHERE plugin=? AND deleted=0 ORDER BY updated_at DESC',[$plugin]):$this->app->db->all('SELECT * FROM extension_records WHERE plugin=? AND site=? AND deleted=0 ORDER BY updated_at DESC',[$plugin,$site]);
        return array_map($this->decode(...),$rows);
    }
    public function get(string $plugin,string $id): array
    {
        return $this->decode($this->app->db->one('SELECT * FROM extension_records WHERE plugin=? AND id=? AND deleted=0',[$plugin,$id])??throw new Problem('Record not found.',404));
    }
    public function save(string $plugin,string $name,string $site,array $data,?string $id=null,int $revision=0): array
    {
        if(trim($name)===''||mb_strlen($name)>160){throw new Problem('Check name.');}
        return $this->app->db->transaction(function()use($plugin,$name,$site,$data,$id,$revision){
            if($id){$old=$this->get($plugin,$id);if($old['revision']!==$revision){throw new Problem('Record changed. Reopen the page.',409);}$this->app->db->execute('INSERT OR IGNORE INTO extension_versions VALUES(?,?,?,?)',[$id,$old['revision'],Files::json($old),gmdate('c')]);$this->app->db->execute('DELETE FROM extension_versions WHERE record=? AND revision NOT IN (SELECT revision FROM extension_versions WHERE record=? ORDER BY revision DESC LIMIT ?)',[$id,$id,max(2,(int)$this->app->setting('retention','20'))]);$this->app->db->execute('UPDATE extension_records SET name=?,site=?,data=?,revision=revision+1,updated_at=? WHERE id=?',[trim($name),$site,Files::json($data),gmdate('c'),$id]);}
            else{$id=Files::id();$this->app->db->execute('INSERT INTO extension_records(id,plugin,site,name,data,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$id,$plugin,$site,trim($name),Files::json($data),gmdate('c'),gmdate('c')]);}
            return $this->get($plugin,$id);
        });
    }
    private function decode(array $record): array {$record['data']=json_decode($record['data'],true,64,JSON_THROW_ON_ERROR);return $record;}
}
