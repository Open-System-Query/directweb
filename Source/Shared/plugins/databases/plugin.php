<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"databases","Databases") extends Module {

protected bool $siteScoped=false;
protected function fields(): array {return ['host'=>['label'=>'Host','value'=>'localhost','required'=>true],'port'=>['label'=>'Port','kind'=>'numberField','value'=>3306,'min'=>1,'max'=>65535],'database'=>['label'=>'Database','required'=>true],'username'=>['label'=>'Username','required'=>true],'password'=>['label'=>'Password','kind'=>'passwordField','required'=>true]];}
protected function validate(array $d,?array $old): array {foreach(['host','database'] as $key){if(!preg_match('/^[a-z0-9_.-]+$/iD',$d[$key])){throw new Problem('Invalid database setting.');}}return $d;}
private function connect(array $r): \PDO {if(!in_array('mysql',\PDO::getAvailableDrivers(),true)){throw new Problem('Enable PDO MySQL.');}$d=$r['data'];$options=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_TIMEOUT=>10];if(defined('PDO::MYSQL_ATTR_LOCAL_INFILE')){$options[\PDO::MYSQL_ATTR_LOCAL_INFILE]=false;}return new \PDO('mysql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['database'].';charset=utf8mb4',$d['username'],$this->secret($r,'password'),$options);}
protected function operations(): array {return ['tables'=>'Tables','export'=>'Export','import'=>'Import SQL','create'=>'Create database'];}
protected function operationFields(string $op,array $r): array {return $op==='import'?['sql'=>['label'=>'SQL','kind'=>'textArea','required'=>true]]:($op==='create'?['database'=>['label'=>'Database','required'=>true]]:[]);}
private function identifier(string $name): string {return chr(96).str_replace(chr(96),chr(96).chr(96),$name).chr(96);}
private function dump(\PDO $db,array $r): string
{
    $file=$this->app->storage('plugins/databases/'.$r['id'].'/'.Files::id().'.sql');Files::mkdir(dirname($file));$h=fopen($file,'xb');if(!$h){throw new Problem('Export failed.');}$total=0;
    try{$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();foreach($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(\PDO::FETCH_NUM) as $row){$table=$this->identifier($row[0]);$create=$db->query('SHOW CREATE TABLE '.$table)->fetch(\PDO::FETCH_NUM)[1];fwrite($h,$create.";\n");foreach($db->query('SELECT * FROM '.$table) as $values){$columns=implode(',',array_map($this->identifier(...),array_keys($values)));$line='INSERT INTO '.$table.' ('.$columns.') VALUES ('.implode(',',array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($values))).");\n";$total+=strlen($line);if($total>134217728){throw new Problem('Export exceeds 128 MB.');}fwrite($h,$line);}}$db->commit();}catch(\Throwable $e){if($db->inTransaction()){$db->rollBack();}fclose($h);Files::remove($file);throw $e;}fclose($h);return $file;
}
protected function execute(string $op,array $u,array $r,array $i): array
{
    $db=$this->connect($r);if($op==='tables'){return ['tables'=>$db->query('SHOW TABLE STATUS')->fetchAll()];}
    if($op==='create'){$name=(string)($i['database']??'');if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$name)){throw new Problem('Invalid database name.');}$db->exec('CREATE DATABASE '.$this->identifier($name).' CHARACTER SET utf8mb4');return ['created'=>$name];}
    $file=$this->dump($db,$r);$download='/admin/databases/download/'.$r['id'].'/'.basename($file);
    if($op==='export'){return ['download'=>$this->app->url($download)];}
    $sql=(string)($i['sql']??'');if($sql===''||strlen($sql)>2097152||preg_match('/\b(?:OUTFILE|DUMPFILE|LOAD_FILE|LOAD\s+DATA)\b/i',$sql)){throw new Problem('SQL unavailable.');}$db->exec($sql);return ['imported'=>true,'previous'=>$this->app->url($download)];
}
protected function extend(Registry $registry): void {$registry->route('databases.download','~^/admin/databases/download/([a-f0-9]{32})/([a-f0-9]{32}\.sql)$~D',['GET'],function($m,$u){if(!$u){throw new Problem('Sign in required.',401);}$this->record($u,$m[1]);Client::download($this->app->storage('plugins/databases/'.$m[1].'/'.$m[2]),$m[2],'application/sql');});}

};$module->register($registry);
}
};
