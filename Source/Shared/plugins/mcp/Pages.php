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
        if($view==='home'){$items=[$p->link('Connection','/admin/mcp/connection','code'),$p->link('Access','/admin/mcp/access','key')];$items[]=$p->link('Messages','/admin/mcp/requests','bell');if($u['role']==='owner'){$items[]=$p->link('Clients','/admin/mcp/clients','users');}$p->section('MCP',$items,['directory'=>true,'icon'=>'code']);}
        elseif($view==='requests'){
            $p->title='Messages';$rows=$a->db->all("SELECT r.*,t.name FROM mcp_requests r JOIN tokens t ON t.id=r.token_id WHERE r.user_id=? AND r.status='pending' AND r.created_at>? ORDER BY r.created_at DESC",[$u['id'],time()-86400]);
            if(!$rows){$p->section('Messages',[$p->text('No open requests.')]);}
            foreach($rows as $r){$p->section($r['name'],[$p->text($r['ip'].' · '.$r['tool'].' · '.gmdate('Y-m-d H:i',(int)$r['created_at']).' UTC',false),$p->form('mcp.request.approve','',[],['request'=>$r['id']],'Approve'),$p->form('mcp.request.deny','',[],['request'=>$r['id']],'Deny',true)],['literal'=>true]);}
        }
        elseif($view==='connection'){$p->title='Connection';$url=($a->config['secure']?'https':'http').'://'.$a->config['admin_host'].$a->url('/mcp');$p->section('Endpoint',[$p->node('codeBlock',['text'=>$url,'metadata'=>['copy'=>true]]),$p->text('Streamable HTTP · Bearer'),$p->link('Access','/admin/mcp/access','key')]);}
        elseif($view==='access'){$p->title='Access';$p->section('Actions',[$p->link('Create access','/admin/mcp/access/new','plus')],['directory'=>true]);$rows=[];foreach($a->db->all('SELECT id,name,expires_at,last_used FROM tokens WHERE user_id=? ORDER BY expires_at DESC',[$u['id']]) as $t){$rows[]=['name'=>['label'=>$t['name'],'literal'=>true,'href'=>'/admin/mcp/access/token?id='.$t['id']],'expires'=>$t['expires_at']===0?$a->translator->get('Never'):gmdate('Y-m-d H:i',$t['expires_at']),'last'=>$t['last_used']?gmdate('Y-m-d H:i',$t['last_used']):'—'];}$p->section('Tokens',[$p->table('Access',['name'=>'Name','expires'=>'Expires','last'=>'Last used'],$rows)]);}
        elseif($view==='access/new'){
            $p->title='Create access';$sites=$a->sites->list($u);$owner=$u['role']==='owner';if(!$sites&&!$owner){throw new Problem('Add a website first.');}
            $fields=['name'=>['label'=>'Name','required'=>true]]+$this->grid($u,$sites)+self::lifetime(1,false)+($this->authenticator($u)?self::approvalFields(null):[]);
            foreach($a->plugins->tools as $name=>$tool){if(str_ends_with($name,'_list')&&!isset($tool['definition']['inputSchema']['properties']['site_id'])){continue;}$fields['tool_'.$name]=['label'=>$tool['definition']['description'],'kind'=>'checkBox','value'=>false];}
            $p->section('Rights',[$p->form('mcp.issue','',$fields,[],'Create')]);
        }elseif(in_array($view,['access/token','access/duration','access/replace'],true)){
            $id=(string)($_GET['id']??'');$t=$a->auth->ownedToken($u,$id);$oauth=(bool)$a->db->one('SELECT hash FROM oauth_refresh WHERE token_id=?',[$id]);
            $p->title=$t['name'];$p->literalTitle=true;
            if($view==='access/token'){
                $items=[$p->text($t['expires_at']===0?'Never expires':'Expires'),$p->text($t['expires_at']===0?'':gmdate('Y-m-d H:i',$t['expires_at']).' UTC',false)];
                $row=$a->db->one('SELECT * FROM mcp_approvals WHERE token_id=?',[$id]);
                if($row||$t['expires_at']===0||\DirectWeb\Identity\Auth::elevated(json_decode($t['scopes'],true)?:[])){$items[]=$p->text($a->translator->get('Approval').': '.$a->translator->get(self::INTERVALS[(int)($row['interval_seconds']??86400)]??'Every day').((int)($row['bind_ip']??0)?' · '.$a->translator->get('IP address'):''),false);}
                if($t['secret']){$items[]=$p->node('codeBlock',['text'=>'••••••••••••••••','metadata'=>['secret'=>true,'endpoint'=>'/mcp/token','token'=>$id,'csrf'=>$_SESSION['csrf']??'']]);}
                else{$items[]=$p->text($oauth?'Managed by OAuth.':'Replace this token to enable Show and Copy.');}
                $p->section('Token',$items);
                $p->section('Rights',[$p->table('Rights',['site'=>'Website','read'=>'Read','write'=>'Write','execute'=>'Execute'],$this->overview($t))]);
                if($t['secret']&&!$oauth){$p->section('Connection',[$p->node('codeBlock',['text'=>'••••••••••••••••','metadata'=>['secret'=>true,'secret_label'=>'Connection URL','endpoint'=>'/mcp/connection-url','token'=>$id,'csrf'=>$_SESSION['csrf']??'']])]);}
                $links=[];
                if(!$oauth){$links[]=$p->link('Settings','/admin/mcp/access/duration?id='.$id,'clock-rotate-left');$links[]=$p->link('Replace token','/admin/mcp/access/replace?id='.$id,'arrows-rotate');}
                $links[]=$p->link('Revoke','/admin/mcp/access/revoke?id='.$id,'trash');$p->section('Actions',$links,['directory'=>true]);
            }elseif($view==='access/duration'){
                if($oauth){throw new Problem('Managed by OAuth.');}
                $p->title='Settings';$fields=self::lifetime(1,$t['expires_at']===0);if($this->authenticator($u)){$fields+=self::approvalFields($a->db->one('SELECT * FROM mcp_approvals WHERE token_id=?',[$id])??(($t['expires_at']===0||\DirectWeb\Identity\Auth::elevated(json_decode($t['scopes'],true)?:[]))?['interval_seconds'=>86400]:null));}
                $p->section('Settings',[$p->text('A new duration starts now.'),$p->form('mcp.duration','',$fields,['token'=>$id])]);
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
    private static function lifetime(int $hours,bool $never): array
    {
        return ['duration'=>['label'=>'Duration','kind'=>'choice','value'=>$never?'0':(string)$hours,'options'=>[['value'=>'1','label'=>'1 hour'],['value'=>'24','label'=>'1 day'],['value'=>'168','label'=>'7 days'],['value'=>'720','label'=>'30 days'],['value'=>'0','label'=>'Unlimited']]]];
    }
    private function grid(array $u,array $sites): array
    {
        $t=fn(string $s)=>$this->app->translator->get($s);$labels=['read'=>$t('Read'),'write'=>$t('Write'),'execute'=>$t('Execute')];$owner=$u['role']==='owner';$rows=[];
        if($owner){$rows['all']=[$t('All websites'),['read','write','execute']];}
        foreach($sites as $s){$rows[$s['id']]=[$s['name'].' · '.$s['host'],$this->app->sites->server($s)&&$owner?['read','write','execute']:['read','write']];}
        if($owner){$rows['directweb']=['DirectWeb',empty($this->app->config['wordpress'])?['read','write','execute']:['read','write']];}
        $fields=[];foreach($rows as $key=>[$label,$columns]){foreach($columns as $column){$fields['r_'.$key.'_'.$column]=['label'=>$label.' · '.$labels[$column],'literal'=>true,'kind'=>'checkBox','value'=>false,'grid'=>['row'=>(string)$key,'row_label'=>$label,'column'=>$column,'column_label'=>$labels[$column]]];}}
        return $fields;
    }
    private function overview(array $token): array
    {
        $scopes=json_decode($token['scopes'],true)?:[];$sites=json_decode($token['sites'],true)?:[];$rights=[];$mark='✓';
        foreach($scopes as $scope){if(preg_match('/^(read|write|execute)@(.+)$/D',(string)$scope,$m)){$rights[$m[2]][$m[1]]=true;}elseif(in_array($scope,['read','write'],true)){foreach($sites as $site){$rights[$site][$scope]=true;}}}
        $rows=[];foreach($rights as $site=>$set){$name=$site==='*'?$this->app->translator->get('All websites'):(($s=$this->app->db->one('SELECT name,host FROM sites WHERE id=?',[$site]))?$s['name'].' · '.$s['host']:$site);$rows[]=['site'=>$name,'read'=>!empty($set['read'])?$mark:'','write'=>!empty($set['write'])?$mark:'','execute'=>!empty($set['execute'])?$mark:''];}
        if(array_intersect($scopes,\DirectWeb\Identity\Auth::ADMIN_SCOPES)){$rows[]=['site'=>'DirectWeb','read'=>in_array('admin.read',$scopes,true)?$mark:'','write'=>in_array('admin.manage',$scopes,true)?$mark:'','execute'=>in_array('admin.update',$scopes,true)?$mark:''];}
        return $rows;
    }
    private function authenticator(array $u): bool { return !empty($this->app->db->one('SELECT totp FROM users WHERE id=?',[$u['id']])['totp']); }
    public const INTERVALS=[900=>'Every 15 minutes',3600=>'Every hour',28800=>'Every 8 hours',86400=>'Every day'];
    private static function approvalFields(?array $row): array
    {
        $options=[['value'=>'0','label'=>'Not required']];foreach(self::INTERVALS as $seconds=>$label){$options[]=['value'=>(string)$seconds,'label'=>$label];}
        return ['approval'=>['label'=>'Approval','kind'=>'choice','value'=>(string)($row['interval_seconds']??0),'options'=>$options],'bind_ip'=>['label'=>'Bind approval to the IP address','kind'=>'checkBox','value'=>(bool)($row['bind_ip']??false)]];
    }
}
