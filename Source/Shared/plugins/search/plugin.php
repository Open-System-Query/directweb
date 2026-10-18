<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"search","Search") extends Module {

protected function fields(): array {return ['enabled'=>['label'=>'Public search','kind'=>'checkBox','value'=>true],'limit'=>['label'=>'Results','kind'=>'numberField','value'=>20,'min'=>1,'max'=>100]];}
protected function operations(): array {return ['index'=>'Build index'];}
protected function execute(string $op,array $u,array $r,array $input): array
{
    $site=$this->app->sites->get($r['site']);$out=[];foreach($this->documents($site) as $path=>$dom){if($path==='404.html'){continue;}foreach(['script','style','nav'] as $tag){foreach(iterator_to_array($dom->getElementsByTagName($tag)) as $node){$node->parentNode?->removeChild($node);}}$text=preg_replace('/\s+/u',' ',$dom->textContent);$out[]=['url'=>'https://'.$site['host'].'/'.$path,'title'=>trim($dom->getElementsByTagName('title')->item(0)?->textContent??$path),'text'=>mb_substr(trim($text),0,10000)];if(count($out)>=2000){break;}}
    Files::write($this->app->storage('plugins/search/'.$r['id'].'.json'),Files::json($out));return ['pages'=>count($out),'portal'=>$this->app->url('/search/'.$r['id'])];
}
protected function extend(Registry $registry): void
{
    $registry->on('release.published','search.index',function($u,$data){foreach($this->app->extensions->records($this->id,$data['site']) as $r){$this->authorize($u,$r['site'],true);$this->execute('index',$u,$r,[]);}return ['indexed'=>true];});
    $registry->route('search.portal','~^/search/([a-f0-9]{32})(\.json)?$~D',['GET','HEAD'],function($match,$user){$r=$this->app->extensions->get($this->id,$match[1]);if(!$r['data']['enabled']){throw new Problem('Unavailable.',404);}$site=$this->app->sites->get($r['site']);if(!$site['enabled']){throw new Problem('Unavailable.',404);}$file=$this->app->storage('plugins/search/'.$r['id'].'.json');$index=is_file($file)?json_decode(file_get_contents($file),true):[];if(!empty($match[2])){return $index;}
        $query=mb_substr(trim((string)($_GET['q']??'')),0,100);$p=new Surface($this->app,'Search','search.public');$p->section('Search',[$p->form('search.query','',['query'=>['label'=>'Search','value'=>$query]],['record'=>$r['id']],'Search')]);$rows=[];if($query!==''){foreach($index as $entry){if(mb_stripos($entry['title'].' '.$entry['text'],$query)!==false){$rows[]=['title'=>['label'=>$entry['title'],'href'=>$entry['url']],'text'=>mb_substr($entry['text'],0,220)];if(count($rows)>=$r['data']['limit']){break;}}}}$p->section('Results',[$p->table('Results',['title'=>'Page','text'=>'Text'],$rows)]);return $p->build(false);});
    $registry->addPublicAction('search.query',function($u,$i){$id=(string)($i['record']??'');$this->app->extensions->get($this->id,$id);return '/search/'.$id.'?q='.rawurlencode(mb_substr((string)($i['query']??''),0,100));});
}

};$module->register($registry);
}
};
