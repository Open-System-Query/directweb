<?php
declare(strict_types=1);
namespace DirectWeb\Plugins;
use DirectWeb\Core\{App,Files,Problem};
final class Registry
{
    public array $pages=[];
    public array $actions=[];
    public array $publicActions=[];
    public array $routes=[];
    public array $tasks=[];
    public array $navigation=[];
    public array $httpRoutes=[];
    public array $siteRoutes=[];
    public array $jobHandlers=[];
    public array $eventHandlers=[];
    public array $tools=[];
    public array $services=[];
    public array $disableGuards=[];
    private bool $booted=false;
    private array $manifests=[];
    public function __construct(private App $app)
    {
        foreach(glob($app->root.'/plugins/*/plugin.json') as $path){
            $m=json_decode(file_get_contents($path),true);if(!is_array($m)||!preg_match('/^[a-z][a-z0-9-]{1,40}$/D',$m['id']??'')||basename(dirname($path))!==$m['id']){continue;}
            $m['path']=dirname($path);$this->manifests[$m['id']]=$m;
            $app->db->execute('INSERT OR IGNORE INTO plugins(id,enabled,version) VALUES(?,?,?)',[$m['id'],$m['type']==='renderer'?1:0,$m['version']]);
        }
    }
    public function all(): array { $out=[];foreach($this->manifests as $id=>$m){$m['enabled']=$this->enabled($id);unset($m['path']);$out[]=$m;}return $out; }
    public function description(string $id): string
    {
        $m=$this->manifests[$id]??[];$text=$m['description']??'';
        if(!is_string($text)||$text===''){return '';}
        $translator=new \DirectWeb\Ui\Translator($m['path'].'/locales');$translator->use($this->app->translator->locale);
        return $translator->get($text);
    }
    public function enabled(string $id): bool { if(isset($this->manifests[$id])&&$this->manifests[$id]['type']==='renderer'){return $this->app->setting('renderer','renderer-classic')===$id;}return isset($this->manifests[$id])&&(bool)($this->app->db->one('SELECT enabled FROM plugins WHERE id=?',[$id])['enabled']??false); }
    public function boot(): void
    {
        if($this->booted){return;}$this->booted=true;
        foreach($this->manifests as $id=>$m){if(!$this->enabled($id)||$m['type']==='renderer'){continue;}$snapshot=[$this->pages,$this->actions,$this->publicActions,$this->routes,$this->tasks,$this->navigation,$this->httpRoutes,$this->siteRoutes,$this->jobHandlers,$this->eventHandlers,$this->tools,$this->services,$this->disableGuards];$file=$m['path'].'/'.$m['entry'];try{$this->migrate($m);$p=require $file;if(!$p instanceof Plugin){throw new Problem('Invalid plugin.');}$p->register($this->app,$this);}catch(\Throwable $e){[$this->pages,$this->actions,$this->publicActions,$this->routes,$this->tasks,$this->navigation,$this->httpRoutes,$this->siteRoutes,$this->jobHandlers,$this->eventHandlers,$this->tools,$this->services,$this->disableGuards]=$snapshot;error_log('DirectWeb plugin '.$id.': '.$e->getMessage());$this->app->db->execute('UPDATE plugins SET enabled=0 WHERE id=?',[$id]);$this->app->audit('system','plugin.failed',$id);}}
    }
    public function addPage(string $id,string $label,string $icon,callable $page,array $options=[]): void { if(isset($this->pages[$id])){throw new Problem('Page already exists.');}$this->pages[$id]=$page;$this->navigation[$id]=['label'=>$label,'icon'=>$icon,'href'=>'/admin/'.$id]+$options; }
    public function addPublicAction(string $id,callable $handler): void {if(isset($this->actions[$id])||isset($this->publicActions[$id])){throw new Problem('Action already exists.');}$this->publicActions[$id]=$handler;}
    public function addAction(string $id,callable $handler): void { if(isset($this->actions[$id])||isset($this->publicActions[$id])){throw new Problem('Action already exists.');}$this->actions[$id]=$handler; }
    public function route(string $id,string $pattern,array $methods,callable $handler,bool $session=true): void
    {
        if(isset($this->httpRoutes[$id])){throw new Problem('Route already exists.');}
        $this->httpRoutes[$id]=compact('pattern','methods','handler','session');
    }
    public function dispatch(string $uri,string $method,?array $user,bool $session): bool
    {
        foreach($this->httpRoutes as $route){if($route['session']!==$session||!preg_match($route['pattern'],$uri,$matches)){continue;}if(!in_array($method,$route['methods'],true)){throw new Problem('Method not allowed.',405);}
            $result=($route['handler'])($matches,$user,$uri);
            if(is_array($result)&&isset($result['lumui_surface'])){(new \DirectWeb\Ui\Output($this->app))->send($result);}
            elseif(is_array($result)){header('Content-Type: application/json');echo Files::json($result);}
            elseif(is_string($result)){header('Location: '.$this->app->url($result),true,303);}
            return true;
        }
        return false;
    }
    public function job(string $id,callable $handler): void {if(isset($this->jobHandlers[$id])){throw new Problem('Job already exists.');}$this->jobHandlers[$id]=$handler;}
    public function on(string $name,string $id,callable $handler): void
    {
        $this->eventHandlers[$name][$id]=$handler;
        $this->job('event.'.$id,fn(array $u,array $payload)=>$handler($u,json_decode($payload['event']['data'],true),$payload['event']));
    }
    public function tool(string $id,string $description,array $schema,callable $handler,bool $write=false): void
    {
        if(isset($this->tools[$id])){throw new Problem('Tool already exists.');}
        $this->tools[$id]=['definition'=>['name'=>$id,'description'=>$description,'inputSchema'=>$schema,'annotations'=>['readOnlyHint'=>!$write,'destructiveHint'=>$write,'idempotentHint'=>!$write,'openWorldHint'=>false]],'handler'=>$handler,'scope'=>'tool:'.$id,'write'=>$write];
    }
    public function provide(string $name,string $plugin,object $service): void
    {
        if(isset($this->services[$name])||!isset($this->manifests[$plugin])){throw new Problem('Invalid plugin.');}
        $this->services[$name]=['plugin'=>$plugin,'service'=>$service];
    }
    public function service(string $name): ?object
    {
        $entry=$this->services[$name]??null;
        return $entry&&$this->enabled($entry['plugin'])?$entry['service']:null;
    }
    public function beforeDisable(string $plugin,callable $guard): void {$this->disableGuards[$plugin][]=$guard;}
    public function toggle(array $u,string $id,bool $on): void
    {
        $this->app->auth->requireOwner($u);$m=$this->manifests[$id]??throw new Problem('Plugin missing.');if($m['type']==='renderer'){throw new Problem('Choose a renderer.');}
        if($on){foreach($m['requires']??[] as $dependency){if(!$this->enabled($dependency)){throw new Problem('Required: %s',422,[$dependency]);}}$this->migrate($m);}else{$this->boot();}
        $this->app->db->transaction(function()use($u,$id,$on,$m){
            if($on){foreach($m['requires']??[] as $dependency){if(!$this->enabled($dependency)){throw new Problem('Required: %s',422,[$dependency]);}}}
            else{
                foreach($this->manifests as $other){if($this->enabled($other['id'])&&in_array($id,$other['requires']??[],true)){throw new Problem('In use: %s',422,[$other['id']]);}}
                foreach($this->disableGuards[$id]??[] as $guard){$guard();}
            }
            $this->app->db->execute('UPDATE plugins SET enabled=? WHERE id=?',[(int)$on,$id]);$this->app->audit($u['id'],$on?'plugin.enabled':'plugin.disabled',$id);
        });
    }
    private function migrate(array $m): void
    {
        $this->app->db->pdo->exec('CREATE TABLE IF NOT EXISTS plugin_migrations(plugin TEXT NOT NULL,name TEXT NOT NULL,hash TEXT NOT NULL,PRIMARY KEY(plugin,name))');
        $this->app->db->transaction(function()use($m){foreach(glob($m['path'].'/migrations/*.sql') as $file){$name=basename($file);$hash=hash_file('sha256',$file);$old=$this->app->db->one('SELECT hash FROM plugin_migrations WHERE plugin=? AND name=?',[$m['id'],$name]);if($old){if(!hash_equals($old['hash'],$hash)){throw new Problem('Migration changed.');}continue;}$this->app->db->pdo->exec(file_get_contents($file));$this->app->db->execute('INSERT INTO plugin_migrations VALUES(?,?,?)',[$m['id'],$name,$hash]);}});
    }
    public function removalReason(string $id): string
    {
        $m=$this->manifests[$id]??throw new Problem('Plugin missing.');if($m['type']==='renderer'&&$this->app->setting('renderer','renderer-classic')===$id){return 'Choose another renderer first.';}if($this->enabled($id)){return 'Disable to delete.';}return '';
    }
    public function selectRenderer(array $u,string $id): void
    {
        $this->app->auth->requireOwner($u);if($id!=='core'&&($this->manifests[$id]['type']??'')!=='renderer'){throw new Problem('Renderer missing.');}
        $this->app->db->transaction(function()use($u,$id){foreach($this->manifests as $plugin=>$m){if($m['type']==='renderer'){$this->app->db->execute('UPDATE plugins SET enabled=? WHERE id=?',[(int)($plugin===$id),$plugin]);}}$this->app->set('renderer',$id);$this->app->audit($u['id'],'renderer.selected',$id);});
    }
    public function remove(array $u,string $id): void
    {
        $this->app->auth->requireOwner($u);$m=$this->manifests[$id]??throw new Problem('Plugin missing.');$reason=$this->removalReason($id);if($reason!==''){throw new Problem($reason);}$this->app->backups->create($u);$this->app->lock(function()use($u,$id,$m){$archive=$this->app->storage('plugins/registry/packages/'.$id.'/'.Files::id());Files::mkdir(dirname($archive));if(!rename($m['path'],$archive)){throw new Problem('Delete failed.');}$this->app->db->execute('DELETE FROM plugins WHERE id=?',[$id]);$this->app->audit($u['id'],'plugin.removed',$id);});
    }
    public function deleteData(array $u,string $id): void
    {
        $this->app->auth->requireOwner($u);$m=$this->manifests[$id]??throw new Problem('Plugin missing.');if($this->enabled($id)){throw new Problem('Disable the plugin first.');}
        if($id==='folders'&&$this->app->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name='folder_rules'")&&$this->app->db->one('SELECT root FROM folder_rules LIMIT 1')){throw new Problem('Restore managed folder rules first.');}
        $this->app->backups->create($u);$tables=$m['tables']??[];$namespaces=$m['data_namespaces']??[];$settings=$m['settings']??[];
        $this->app->lock(function()use($u,$id,$tables,$namespaces,$settings){
            $this->app->db->transaction(function()use($id,$tables,$namespaces,$settings){foreach(array_reverse($tables) as $table){if(!preg_match('/^[a-z][a-z0-9_]+$/D',$table)){throw new Problem('Invalid plugin table.');}if($this->app->db->one("SELECT name FROM sqlite_master WHERE type='table' AND name=?",[$table])){$this->app->db->pdo->exec('DELETE FROM "'.$table.'"');}}$this->app->db->execute('DELETE FROM extension_records WHERE plugin=? OR plugin LIKE ?',[$id,$id.'-%']);foreach($namespaces as $namespace){$this->app->db->execute('DELETE FROM extension_records WHERE plugin=?',[$namespace]);}foreach($settings as $key){$this->app->db->execute('DELETE FROM settings WHERE key=?',[$key]);}$this->app->db->execute("DELETE FROM jobs WHERE type LIKE ? AND status='queued'",[$id.'.%']);if($id==='mcp'){$this->app->db->execute('DELETE FROM tokens');}$this->app->db->execute('DELETE FROM settings WHERE key LIKE ?',[$id.'.%']);});
            Files::remove($this->app->storage('plugins/'.$id));$this->app->audit($u['id'],'plugin.data.deleted',$id);
        });
    }
    public function renderer(): \Lumui\Contracts\SurfaceRenderer
    {
        $id=$this->app->setting('renderer','renderer-classic');$m=$this->manifests[$id]??null;
        if($m&&$m['type']==='renderer'){try{$factory=require $m['path'].'/'.$m['entry'];$r=$factory($this->app);if($r instanceof \Lumui\Contracts\SurfaceRenderer){return $r;}}catch(\Throwable $e){error_log('DirectWeb renderer: '.$e->getMessage());}}
        return new \DirectWeb\Ui\Renderer($this->app);
    }
    public function install(array $u,string $zip): void
    {
        $this->app->auth->requireOwner($u);$z=new \ZipArchive();if(!is_file($zip)||filesize($zip)>33554432||$z->open($zip)!==true){throw new Problem('Invalid package.');}
        $tmp=$this->app->storage('staging/plugin-'.Files::id());Files::mkdir($tmp);
        try{
            $text=$z->getFromName('plugin.json');$m=$text?json_decode($text,true):null;
            if(!$m||($m['id']??'')==='core'||!preg_match('/^[a-z][a-z0-9-]{1,40}$/D',$m['id']??'')||!in_array($m['type']??'',['plugin','renderer'],true)||($m['api']??null)!==1||($m['lumui']??null)!=='1.0'||!preg_match('/^\d+\.\d+(?:\.\d+)?$/D',$m['version']??'')){throw new Problem('Package incompatible.');}
            if(isset($m['description'])&&(!is_string($m['description'])||mb_strlen($m['description'])>240)){throw new Problem('Package incompatible.');}
            if(isset($m['min_core'])&&version_compare($this->app->config['version'],$m['min_core'],'<')){throw new Problem('Package incompatible.');}Files::path($m['entry']??'');$existing=$this->manifests[$m['id']]??null;if($existing&&($this->enabled($m['id'])||$m['id']===$this->app->setting('renderer','renderer-classic'))){throw new Problem('Disable the plugin first.');}if($existing&&version_compare($m['version'],$existing['version'],'<=')){throw new Problem('Newer version required.');}
            if($z->numFiles>2000){throw new Problem('Package too large.');}$total=0;$names=[];
            for($i=0;$i<$z->numFiles;$i++){$s=$z->statIndex($i);$p=rtrim($s['name'],'/');Files::path($p);$os=0;$attr=0;$z->getExternalAttributesIndex($i,$os,$attr);$kind=($attr>>16)&0170000;if($kind!==0&&$kind!==0100000&&$kind!==0040000){throw new Problem('Invalid package.');}if(str_ends_with($s['name'],'/')){continue;}if(isset($names[strtolower($p)])){throw new Problem('Duplicate file.');}$names[strtolower($p)]=true;$total+=$s['size'];if($total>67108864||$s['size']>8388608){throw new Problem('Package too large.');}$data=$z->getFromIndex($i);if($data===false||strlen($data)!==$s['size']){throw new Problem('Package corrupted.');}Files::write($tmp.'/'.$p,$data);}
            if(!is_file($tmp.'/'.$m['entry'])){throw new Problem('Entry point missing.');}
            $this->app->backups->create($u);
            $this->app->lock(function()use($tmp,$m,$u){$target=$this->app->root.'/plugins/'.$m['id'];$previous=$this->app->storage('plugins/registry/packages/'.$m['id'].'/'.Files::id());if(is_dir($target)){Files::mkdir(dirname($previous));if(!rename($target,$previous)){throw new Problem('Update failed.');}}try{if(!rename($tmp,$target)){throw new Problem('Installation failed.');}$this->app->db->execute('INSERT INTO plugins VALUES(?,0,?) ON CONFLICT(id) DO UPDATE SET version=excluded.version,enabled=0',[$m['id'],$m['version']]);$this->app->audit($u['id'],'plugin.installed',$m['id']);}catch(\Throwable $e){if(is_dir($previous)){Files::remove($target);rename($previous,$target);}throw $e;}});
        }finally{$z->close();Files::remove($tmp);}
    }
}
