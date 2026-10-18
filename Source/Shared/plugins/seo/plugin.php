<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"seo","SEO") extends Module {

protected function fields(): array {return ['robots'=>['label'=>'Robots','kind'=>'textArea','value'=>"User-agent: *\nAllow: /"],'generate'=>['label'=>'Generate on publish','kind'=>'checkBox','value'=>false]];}
protected function operations(): array {return ['scan'=>'Scan','generate'=>'Create sitemap'];}
protected function execute(string $op,array $u,array $r,array $input): array
{
    $site=$this->app->sites->get($r['site']);$docs=$this->documents($site);$manifest=$this->app->sites->readManifest($site['id'],$site['active_release']);$issues=[];$urls=[];
    foreach($docs as $path=>$dom){$uri='/'.preg_replace('~(?:^|/)index\.html?$~','/',$path);$uri=preg_replace('~/+~','/',$uri);if($path!=='404.html'){$urls[]='https://'.$site['host'].$uri;}
        if(trim($dom->getElementsByTagName('title')->item(0)?->textContent??'')===''){$issues[]=['file'=>$path,'issue'=>'Missing title'];}
        $description=false;foreach($dom->getElementsByTagName('meta') as $meta){if(strtolower($meta->getAttribute('name'))==='description'&&trim($meta->getAttribute('content'))!==''){$description=true;}}
        if(!$description){$issues[]=['file'=>$path,'issue'=>'Missing description'];}
        foreach($dom->getElementsByTagName('a') as $link){$href=$link->getAttribute('href');if($href===''||preg_match('~^(?:[a-z]+:|//|#)~i',$href)){continue;}$target=rawurldecode((string)parse_url($href,PHP_URL_PATH));$parts=[];$full=str_starts_with($target,'/')?$target:dirname($path).'/'.$target;foreach(explode('/',$full) as $part){if($part==='..'){array_pop($parts);}elseif($part!==''&&$part!=='.'){$parts[]=$part;}}$target=implode('/',$parts);if(!isset($manifest['files'][$target],$manifest['files'][$target.'/index.html'])){if(!isset($manifest['files'][$target])&&!isset($manifest['files'][trim($target.'/index.html','/')])&&!isset($manifest['files'][$target.'.html'])){$issues[]=['file'=>$path,'issue'=>'Broken link','target'=>$href];}}if(count($issues)>=1000){break;}}
    }
    if($op==='scan'){return ['pages'=>count($docs),'issues'=>$issues];}
    $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as $url){$xml.='<url><loc>'.htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';}$xml.='</urlset>';
    return $this->publish($u,$site['id'],['sitemap.xml'=>$xml,'robots.txt'=>rtrim($r['data']['robots'])."\nSitemap: https://".$site['host']."/sitemap.xml\n"],'SEO');
}
protected function extend(Registry $r): void {$r->on('release.published','seo.generate',function($u,$data){foreach($this->app->extensions->records($this->id,$data['site']) as $record){if($record['data']['generate']){$this->authorize($u,$record['site'],true);$this->execute('generate',$u,$record,[]);}}return ['updated'=>true];});}

};$module->register($registry);
}
};
