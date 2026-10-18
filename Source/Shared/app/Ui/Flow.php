<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\Problem;
final class Flow
{
    public static function apply(Surface $p,array $regions): array
    {
        $uri=$_SERVER['REQUEST_URI']??'/admin';$path=parse_url($uri,PHP_URL_PATH)?:'/admin';
        if((str_starts_with($p->id,'admin.login')||str_starts_with($p->id,'admin.setup'))||!str_starts_with($path,'/admin')&&$path!=='/transfer'){return $regions;}
        $forms=[];$tables=0;
        foreach($regions as $ri=>$region){foreach($region['items']??[] as $ii=>$node){if($node['kind']==='table'){$tables++;}if($node['kind']==='form'&&empty($node['metadata']['download'])){$action=$p->actions[$node['submit_action']]['callback']??'';if(in_array($action,['logout','login.email','login.cancel'],true)){continue;}$key=$action.'.'.substr(hash('sha256',json_encode($node['metadata']['context']??[])),0,8);$forms[$key]=[$ri,$ii,$node];}}}
        if(count($forms)<2&&$tables===0&&(!str_starts_with($p->id,'plugin.folder')||str_starts_with($p->id,'plugin.folder.preview'))){return $regions;}
        $query=[];parse_str((string)parse_url($uri,PHP_URL_QUERY),$query);unset($query['view']);$parent=$path.($query?'?'.http_build_query($query):'');$selected=(string)($_GET['view']??'');
        if($selected!==''){
            [$ri,$ii,$form]=$forms[$selected]??throw new Problem('Page not found.',404);
            $region=$regions[$ri];$items=[];
            foreach($region['items']??[] as $index=>$node){if($index===$ii||!in_array($node['kind'],['form','table'],true)){$items[]=$node;}}
            $region['items']=$items;$region['metadata']['collapsible']=false;unset($region['collapsible']);
            $p->title=($region['label']?:$p->title);$p->literalTitle=true;
            $p->id.='.'.substr(hash('sha256',$selected),0,12);
            return [$p->node('section',['label'=>'','items'=>[$p->link('Back',$parent,'arrow-rotate-left')],'metadata'=>['directory'=>true]]),$region];
        }
        foreach($forms as $key=>[$ri,$ii,$form]){
            $button=end($form['items']);$label=trim($form['label']??'')?:($regions[$ri]['label']??$button['label']);
            if(count(array_filter($regions[$ri]['items'],fn($n)=>$n['kind']==='form'))>1){$label=$button['label']??$label;}
            $href=$parent.(str_contains($parent,'?')?'&':'?').'view='.rawurlencode($key);
            $regions[$ri]['items'][$ii]=$p->link($label,$href,$regions[$ri]['metadata']['icon']??'arrow-right-from-bracket');
            $regions[$ri]['metadata']['directory']=true;
        }
        return $regions;
    }
}
