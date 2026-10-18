<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"statistics","Statistics") extends Module {

protected function fields(): array {return ['enabled'=>['label'=>'Enabled','kind'=>'checkBox','value'=>true],'days'=>['label'=>'Retention (days)','kind'=>'numberField','value'=>90,'min'=>1,'max'=>730]];}
protected function operations(): array {return ['report'=>'View report'];}
protected function execute(string $op,array $u,array $r,array $i): array {return ['days'=>$this->app->db->all("SELECT day,SUM(hits) AS requests,SUM(CASE WHEN kind='page' AND status=200 THEN hits ELSE 0 END) AS pageviews,SUM(CASE WHEN status=404 THEN hits ELSE 0 END) AS missing FROM traffic WHERE site=? GROUP BY day ORDER BY day DESC LIMIT 90",[$r['site']]),'pages'=>$this->app->db->all("SELECT path,SUM(hits) AS views FROM traffic WHERE site=? AND kind='page' AND status=200 GROUP BY path ORDER BY views DESC LIMIT 100",[$r['site']]),'downloads'=>$this->app->db->all("SELECT path,SUM(hits) AS downloads FROM traffic WHERE site=? AND kind='download' AND status=200 GROUP BY path ORDER BY downloads DESC LIMIT 100",[$r['site']]),'referrers'=>$this->app->db->all('SELECT referrer,SUM(hits) AS requests FROM traffic WHERE site=? GROUP BY referrer ORDER BY requests DESC LIMIT 100',[$r['site']])];}
protected function extend(Registry $r): void
{
    $this->app->db->pdo->exec('CREATE TABLE IF NOT EXISTS traffic(site TEXT NOT NULL,day TEXT NOT NULL,path TEXT NOT NULL,status INTEGER NOT NULL,referrer TEXT NOT NULL,hits INTEGER NOT NULL DEFAULT 1,PRIMARY KEY(site,day,path,status,referrer))');
    if(!in_array('kind',array_column($this->app->db->all('PRAGMA table_info(traffic)'),'name'),true)){$this->app->db->pdo->exec("ALTER TABLE traffic ADD COLUMN kind TEXT NOT NULL DEFAULT 'request'");}
    $r->siteRoutes[$this->id]=function($site,$uri){if(!empty($site['_preview'])||($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){return false;}$enabled=false;foreach($this->app->extensions->records($this->id,$site['id']) as $r){if($r['data']['enabled']){$enabled=true;break;}}if(!$enabled){return false;}$ref=mb_substr((string)parse_url($_SERVER['HTTP_REFERER']??'',PHP_URL_HOST),0,253);$path=mb_substr($uri,0,512);register_shutdown_function(function()use($site,$ref,$path){try{$type='';foreach(headers_list() as $header){if(str_starts_with(strtolower($header),'content-type:')){$type=strtolower($header);}}$kind=str_contains($type,'text/html')?'page':(preg_match('~application/pdf|audio/|video/|application/octet-stream~',$type)?'download':'asset');$this->app->db->execute('INSERT INTO traffic(site,day,path,status,referrer,kind) VALUES(?,?,?,?,?,?) ON CONFLICT(site,day,path,status,referrer) DO UPDATE SET hits=hits+1',[$site['id'],gmdate('Y-m-d'),$path,http_response_code()?:200,$ref,$kind]);}catch(\Throwable){}});return false;};
    $r->tasks['statistics.retention']=function(){foreach($this->app->extensions->records($this->id) as $r){$this->app->db->execute('DELETE FROM traffic WHERE site=? AND day<?',[$r['site'],gmdate('Y-m-d',time()-86400*$r['data']['days'])]);}};
}

};$module->register($registry);
}
};
