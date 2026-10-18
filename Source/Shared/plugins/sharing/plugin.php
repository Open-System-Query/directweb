<?php
declare(strict_types=1);
require_once __DIR__.'/Sharing.php';
require_once __DIR__.'/Uploads.php';
require_once __DIR__.'/Portal.php';
require_once __DIR__.'/Requests.php';
return new class implements DirectWeb\Plugins\Plugin {
    public function register(DirectWeb\Core\App $app,DirectWeb\Plugins\Registry $registry): void
    {
        $sharing=new DirectWebSharing\Sharing($app);$portal=new DirectWebSharing\Portal($app,$sharing);$uploads=new DirectWebSharing\Uploads($app,$sharing);$requests=new DirectWebSharing\Requests($app,$sharing,$portal);
        $registry->pages['sharing/new']=fn($u)=>$portal->createPage($u);$registry->pages['sharing/detail']=fn($u)=>$portal->detail($u,'admin');foreach(['list','new','detail'] as $view){$registry->pages['sharing/requests'.($view==='list'?'':'/'.$view)]=fn($u)=>$requests->page($u,$view);}
        $registry->addPage('sharing','Sharing','link',fn(array $u)=>$portal->admin($u));$registry->pages['sharing-settings']=fn(array $u)=>$portal->settings($u);
        $registry->addAction('sharing.settings',function(array $u,array $i)use($sharing){$sharing->configure($u,$i);return '/admin/sharing-settings';});
        $registry->addPublicAction('sharing.create',function(?array $u,array $i)use($sharing,$app){$app->auth->limit('share-upload:'.hash('sha256',$_SERVER['REMOTE_ADDR']??''),$sharing->settings()['upload_rate'],3600);$r=$sharing->create($u,DirectWeb\Http\Controller::uploads('files'),$i);return (($i['origin']??'')==='portal'?'/transfer':'/admin/sharing').'?created='.$r['code'];});
        foreach(['revoke','delete'] as $operation){$registry->addPublicAction('sharing.'.$operation,function(?array $u,array $i)use($sharing,$operation){$sharing->revoke($u,(string)($i['share']??''),$operation==='delete');return ($i['origin']??'')==='portal'?'/transfer':'/admin/sharing';});}
        $registry->addPublicAction('sharing.open',function(?array $u,array $i)use($sharing){$sharing->guard($_SERVER['REMOTE_ADDR']??'');$code=strtolower(trim((string)($i['code']??'')));if(!preg_match('/^[a-z2-9]{5}$/D',$code)){throw new DirectWeb\Core\Problem('Link unavailable.',404);}$sharing->find($code);return '/'.$code;});
        $registry->route('sharing.portal','~^/transfer$~D',['GET','HEAD'],fn($m,$u)=>$portal->home($u));
        $registry->route('sharing.login','~^/transfer/login$~D',['GET'],function($m,$u){$_SESSION['after_login']='/transfer';return $u?'/transfer':'/admin';});
        $registry->route('sharing.upload','~^/transfer/upload$~D',['POST'],function($m,$u)use($uploads){$uploads->http($u);});
        $registry->route('sharing.download','~^/(?:s/)?((?!admin$)[a-z2-9]{5}|[a-f0-9]{64})$~D',['GET','HEAD'],function($m,$u)use($app,$portal,$sharing){$sharing->guard($_SERVER['REMOTE_ADDR']??'');$request=$app->db->one('SELECT id FROM share_requests WHERE code=?',[$m[1]]);return $request?$portal->requestPage($sharing->request($m[1])):$portal->download($m[1]);});
        $registry->addPublicAction('sharing.unlock',function($u,$i)use($sharing){$token=(string)($i['token']??'');$sharing->unlock($token,(string)($i['password']??''),$_SERVER['REMOTE_ADDR']??'');return strlen($token)===5?'/'.$token:'/s/'.$token;});
        $registry->addPublicAction('sharing.download',function($u,$i)use($sharing){$file=$sharing->download((string)($i['token']??''),(string)($i['file']??''));\DirectWeb\Http\Client::download($file['path'],$file['name']);exit;});
        $registry->routes['sharing']=$sharing;$registry->routes['sharing.portal']=$portal;$registry->routes['sharing.upload']=$uploads;
        $registry->addPublicAction('sharing.extend',function($u,$i)use($sharing){$sharing->extend($u,(string)($i['share']??''),(int)($i['hours']??0));return ($i['origin']??'')==='portal'?'/transfer?task=history':'/admin/sharing';});
        $registry->addPublicAction('sharing.zip',function($u,$i)use($sharing){$file=$sharing->zip((string)($i['token']??''));try{\DirectWeb\Http\Client::download($file['path'],$file['name'],'application/zip');}finally{\DirectWeb\Core\Files::remove($file['path']);}exit;});
        $registry->addAction('sharing.request.create',function($u,$i)use($sharing){$r=$sharing->requestCreate($u,(string)($i['name']??''),(int)($i['hours']??48));return '/admin/sharing/requests/detail?id='.$r['id'];});
        $registry->addAction('sharing.request.close',function($u,$i)use($app){$r=$app->db->one('SELECT * FROM share_requests WHERE id=?',[(string)($i['id']??'')]);if(!$r||($u['role']!=='owner'&&$r['owner']!==$u['id'])){throw new \DirectWeb\Core\Problem('Access denied.',403);}$app->db->execute('UPDATE share_requests SET enabled=0 WHERE id=?',[$r['id']]);return '/admin/sharing/requests';});
        $registry->tasks['sharing.prune']=fn()=>$sharing->prune();
    }
};
