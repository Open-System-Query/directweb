<?php
declare(strict_types=1);
namespace DirectWeb\Core;
final class App
{
    private mixed $requestGate=null;
    public readonly Database $db;
    public readonly \DirectWeb\Plugins\Extensions $extensions;
    public readonly \DirectWeb\Mail\Mailer $mail;
    public readonly \DirectWeb\Jobs\Queue $jobs;
    public readonly \DirectWeb\Identity\Auth $auth;
    public readonly \DirectWeb\Sites\Sites $sites;
    public readonly \DirectWeb\Plugins\Registry $plugins;
    public readonly \DirectWeb\Backups\Backups $backups;
    public readonly \DirectWeb\Ui\Translator $translator;
    public function __construct(public readonly string $root, public readonly array $config)
    {
        foreach (['database','sites','staging','plugins','backups','logs','cache'] as $dir) { Files::mkdir($this->storage($dir)); }
        if(PHP_SAPI!=='cli'&&empty($config['wordpress_worker'])){$this->requestGate=fopen($this->storage('database/maintenance.lock'),'c');if(!$this->requestGate||!flock($this->requestGate,LOCK_SH|LOCK_NB)){http_response_code(503);header('Retry-After: 30');exit('Maintenance');}}
        if(!is_file($this->storage('.htaccess'))){Files::write($this->storage('.htaccess'),"Require all denied\n");}
        $this->db = new Database($this->storage('database/directweb.sqlite'));
        $this->db->pdo->exec(file_get_contents($root . '/database/migrations/001.sql'));
        $this->db->migrate($root);
        if (!is_file($this->storage('database/key'))) { $h = @fopen($this->storage('database/key'), 'x'); if ($h) { fwrite($h, random_bytes(32)); fclose($h); chmod($this->storage('database/key'),0600); } }
        $this->extensions = new \DirectWeb\Plugins\Extensions($this);
        $this->jobs = new \DirectWeb\Jobs\Queue($this);
        $this->auth = new \DirectWeb\Identity\Auth($this);
        $this->mail = new \DirectWeb\Mail\Mailer($this);
        $this->sites = new \DirectWeb\Sites\Sites($this);
        $this->plugins = new \DirectWeb\Plugins\Registry($this);
        $this->backups = new \DirectWeb\Backups\Backups($this);
        $this->plugins->job('backup.restore',fn($u,$i)=>$this->backups->restore($u,$i['backup']));
        $this->translator = new \DirectWeb\Ui\Translator($root . '/locales');
        $this->translator->use($this->setting('locale','en'));
        foreach($this->plugins->all() as $plugin){if($plugin['enabled']){$this->translator->addDirectory($this->pluginRoot().'/'.$plugin['id'].'/locales');}}
    }
    public function pluginRoot(): string {return $this->config['plugin_root']??$this->root.'/plugins';}
    public function url(string $path): string {return str_starts_with($path,'/')&&!str_starts_with($path,'//')?($this->config['base_path']??'').$path:$path;}
    public function publicDocument(array $value): array
    {
        foreach($value as $key=>&$item){if(is_array($item)){$item=$this->publicDocument($item);}elseif(is_string($item)&&in_array($key,['href','endpoint','return','home','login','surface_href','path'],true)&&str_starts_with($item,'/')){$item=$this->url($item);}}unset($item);return $value;
    }
    public function storage(string $path): string { return rtrim($this->config['storage'], '/\\') . '/' . $path; }
    public function setting(string $key, string $default = ''): string { return $this->db->one('SELECT value FROM settings WHERE key=?', [$key])['value'] ?? $default; }
    public function set(string $key, string $value): void { $this->db->execute('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key,$value]); }
    public function audit(string $actor, string $action, string $target = '', array $detail = []): void { $this->db->execute('INSERT INTO audit(actor,action,target,detail,created_at) VALUES(?,?,?,?,?)', [$actor,$action,$target,Files::json($detail),gmdate('c')]); }
    public function lock(callable $callback): mixed
    {
        $h = fopen($this->storage('database/mutation.lock'), 'c');
        if (!$h || !flock($h, LOCK_EX)) { throw new Problem('System busy.',503); }
        try { return $callback(); } finally { flock($h,LOCK_UN); fclose($h); }
    }
    public function seal(string $text): string { $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return base64_encode($nonce . sodium_crypto_secretbox($text,$nonce,file_get_contents($this->storage('database/key')))); }
    public function unseal(string $text): string { $raw=base64_decode($text,true); if ($raw === false) { throw new Problem('Invalid key.'); } $out=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),file_get_contents($this->storage('database/key'))); if ($out===false) { throw new Problem('Invalid key.'); } return $out; }
}
