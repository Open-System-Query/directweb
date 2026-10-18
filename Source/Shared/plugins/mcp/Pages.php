<?php
declare(strict_types=1);
namespace DirectWebMcp;
use DirectWeb\Core\{App,Problem};
use DirectWeb\Ui\Surface;
final class Pages
{
    public function __construct(private App $app){}
    public function page(array $u,string $view): array
    {
        $a=$this->app;$p=new Surface($a,'MCP','mcp.'.str_replace('/','.',$view),$u);
        if($view!=='home'){$p->section('',[$p->link('MCP','/admin/mcp','arrow-rotate-left')],['directory'=>true]);}
        if($view==='home'){$items=[$p->link('Connection','/admin/mcp/connection','code'),$p->link('Access','/admin/mcp/access','key')];if($u['role']==='owner'){$items[]=$p->link('Clients','/admin/mcp/clients','users');}$p->section('MCP',$items,['directory'=>true,'icon'=>'code']);}
        elseif($view==='connection'){$p->title='Connection';$url=($a->config['secure']?'https':'http').'://'.$a->config['admin_host'].$a->url('/mcp');$p->section('Endpoint',[$p->node('codeBlock',['text'=>$url,'metadata'=>['copy'=>true]]),$p->text('Streamable HTTP · Bearer'),$p->link('Access','/admin/mcp/access','key')]);}
        elseif($view==='access'){$p->title='Access';$p->section('Actions',[$p->link('Create access','/admin/mcp/access/new','plus')],['directory'=>true]);$rows=[];foreach($a->db->all('SELECT id,name,expires_at,last_used FROM tokens WHERE user_id=? ORDER BY expires_at DESC',[$u['id']]) as $t){$rows[]=['name'=>['label'=>$t['name'],'literal'=>true,'href'=>'/admin/mcp/access/token?id='.$t['id']],'expires'=>$t['expires_at']===0?$a->translator->get('Never'):gmdate('Y-m-d',$t['expires_at']),'last'=>$t['last_used']?gmdate('Y-m-d H:i',$t['last_used']):'—'];}$p->section('Tokens',[$p->table('Access',['name'=>'Name','expires'=>'Expires','last'=>'Last used'],$rows)]);}
        elseif($view==='access/new'){
            $p->title='Create access';$sites=$a->sites->list($u);if(!$sites){throw new Problem('Add a website first.');}$fields=['name'=>['label'=>'Name','required'=>true],'site'=>['label'=>'Website','kind'=>'choice','value'=>$sites[0]['id'],'options'=>array_map(fn($s)=>['value'=>$s['id'],'label'=>$s['name'],'literal'=>true],$sites)],'write'=>['label'=>'Publish','kind'=>'checkBox','value'=>false],'days'=>['label'=>'Days','kind'=>'numberField','value'=>30,'min'=>1],'never'=>['label'=>'Never expires','kind'=>'checkBox','value'=>false]];foreach($a->plugins->tools as $name=>$tool){if(str_ends_with($name,'_list')&&!isset($tool['definition']['inputSchema']['properties']['site_id'])){continue;}$fields['tool_'.$name]=['label'=>$tool['definition']['description'],'kind'=>'checkBox','value'=>false];}$p->section('Permissions',[$p->form('mcp.issue','',$fields,[],'Create')]);
        }elseif(in_array($view,['access/token','access/duration','access/replace'],true)){
            $id=(string)($_GET['id']??'');$t=$a->auth->ownedToken($u,$id);$oauth=(bool)$a->db->one('SELECT hash FROM oauth_refresh WHERE token_id=?',[$id]);
            $p->title=$t['name'];$p->literalTitle=true;
            if($view==='access/token'){
                $items=[$p->text($t['expires_at']===0?'Never expires':'Expires'),$p->text($t['expires_at']===0?'':gmdate('Y-m-d',$t['expires_at']),false)];
                if($t['secret']){$items[]=$p->node('codeBlock',['text'=>'••••••••••••••••','metadata'=>['secret'=>true,'endpoint'=>'/mcp/token','token'=>$id,'csrf'=>$_SESSION['csrf']??'']]);}
                else{$items[]=$p->text($oauth?'Managed by OAuth.':'Replace this token to enable Show and Copy.');}
                $p->section('Token',$items);
                if($t['secret']&&!$oauth){$p->section('Connection',[$p->node('codeBlock',['text'=>'••••••••••••••••','metadata'=>['secret'=>true,'secret_label'=>'Connection URL','endpoint'=>'/mcp/connection-url','token'=>$id,'csrf'=>$_SESSION['csrf']??'']])]);}
                $links=[];
                if(!$oauth){$links[]=$p->link('Expiry','/admin/mcp/access/duration?id='.$id,'clock-rotate-left');$links[]=$p->link('Replace token','/admin/mcp/access/replace?id='.$id,'arrows-rotate');}
                $links[]=$p->link('Revoke','/admin/mcp/access/revoke?id='.$id,'trash');$p->section('Actions',$links,['directory'=>true]);
            }elseif($view==='access/duration'){
                if($oauth){throw new Problem('Managed by OAuth.');}
                $p->section('Expiry',[$p->form('mcp.duration','',['days'=>['label'=>'Days','kind'=>'numberField','value'=>$t['expires_at']===0?365:max(1,(int)ceil(($t['expires_at']-time())/86400)),'min'=>1],'never'=>['label'=>'Never expires','kind'=>'checkBox','value'=>$t['expires_at']===0]],['token'=>$id])]);
            }else{
                if($oauth){throw new Problem('Managed by OAuth.');}
                $p->section('Replace token',[$p->text('The current token stops working. Update your connector afterward.'),$p->form('mcp.replace','',[],['token'=>$id],'Replace',true)]);
            }
        }
        elseif($view==='access/revoke'){$token=$a->db->one('SELECT id,name FROM tokens WHERE id=? AND user_id=?',[(string)($_GET['id']??''),$u['id']])??throw new Problem('Token missing.',404);$p->title='Revoke';$p->section('Token',[$p->text($token['name'],false),$p->form('mcp.revoke','',[],['token'=>$token['id']],'Revoke',true)]);}
        elseif(str_starts_with($view,'clients')){$a->auth->requireOwner($u);$p->title='Clients';
            if($view==='clients'){$p->section('Actions',[$p->link('Register','/admin/mcp/clients/new','plus')],['directory'=>true]);$rows=[];foreach($a->db->all('SELECT * FROM oauth_clients') as $c){$rows[]=['name'=>['label'=>$c['name'],'literal'=>true,'href'=>'/admin/mcp/clients/delete?id='.$c['id']],'id'=>$c['id'],'redirect'=>$c['redirect_uri']];}$p->section('Clients',[$p->table('Clients',['name'=>'Name','id'=>'Client ID','redirect'=>'Redirect URL'],$rows)]);}
            elseif($view==='clients/new'){$p->title='Register';$p->section('Client',[$p->form('mcp.client','',['name'=>['label'=>'Name','required'=>true],'redirect'=>['label'=>'Redirect URL','required'=>true]],[],'Register')]);}
            elseif($view==='clients/delete'){$c=$a->db->one('SELECT * FROM oauth_clients WHERE id=?',[(string)($_GET['id']??'')])??throw new Problem('Client missing.',404);$p->title='Delete';$p->section('Client',[$p->text($c['name'],false),$p->form('mcp.client.delete','',[],['client'=>$c['id']],'Delete',true)]);}
            else{throw new Problem('Page not found.',404);}
        }else{throw new Problem('Page not found.',404);}return $p->build();
    }
}
