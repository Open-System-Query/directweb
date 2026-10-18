<?php
declare(strict_types=1);
namespace DirectWeb\Core;
final class Database
{
    public readonly \PDO $pdo;
    public function __construct(string $file)
    {
        Files::mkdir(dirname($file));
        $this->pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=10000; PRAGMA synchronous=FULL;');
    }
    public function migrate(string $root): void
    {
        if(!in_array('published',array_column($this->all('PRAGMA table_info(releases)'),'name'),true)){
            $this->transaction(function(){
                if(in_array('published',array_column($this->all('PRAGMA table_info(releases)'),'name'),true)){return;}
                $this->pdo->exec('ALTER TABLE releases ADD COLUMN published INTEGER NOT NULL DEFAULT 0');
                $this->pdo->exec('UPDATE releases SET published=1');
                foreach($this->all("SELECT detail FROM audit WHERE action='release.committed' ORDER BY id") as $row){$result=json_decode($row['detail'],true);if(isset($result['release_id'])&&empty($result['live'])&&empty($result['unchanged'])){$this->execute('UPDATE releases SET published=0 WHERE id=?',[$result['release_id']]);}}
                foreach($this->all("SELECT detail FROM audit WHERE action IN ('release.restored','release.published')") as $row){$result=json_decode($row['detail'],true);if(isset($result['release_id'])){$this->execute('UPDATE releases SET published=1 WHERE id=?',[$result['release_id']]);}}
                $this->pdo->exec('UPDATE releases SET published=1 WHERE id IN (SELECT active_release FROM sites)');
            });
        }
        if(in_array('username',array_column($this->all('PRAGMA table_info(users)'),'name'),true)){return;}
        $file=$this->one('PRAGMA database_list')['file'];$snapshot=dirname($file).'/before-accounts.sqlite';if($this->one('SELECT id FROM users LIMIT 1')&&!is_file($snapshot)){$source=new \SQLite3($file,SQLITE3_OPEN_READONLY);$target=new \SQLite3($snapshot);if(!$source->backup($target)){$source->close();$target->close();@unlink($snapshot);throw new Problem('Account backup failed.');}$source->close();$target->close();chmod($snapshot,0600);}
        $this->pdo->exec('PRAGMA foreign_keys=OFF');
        try{$this->transaction(function()use($root){if(in_array('username',array_column($this->all('PRAGMA table_info(users)'),'name'),true)){return;}$this->pdo->exec(file_get_contents($root.'/database/migrations/002-accounts.sql'));if($this->all('PRAGMA foreign_key_check')){throw new Problem('Account migration failed.');}});}finally{$this->pdo->exec('PRAGMA foreign_keys=ON');}
    }
    public function execute(string $sql, array $params = []): \PDOStatement { $s = $this->pdo->prepare($sql); $s->execute($params); return $s; }
    public function one(string $sql, array $params = []): ?array { return $this->execute($sql, $params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->execute($sql, $params)->fetchAll(); }
    public function transaction(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result = $callback(); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
}
