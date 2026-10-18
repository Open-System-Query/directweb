<?php
declare(strict_types=1);
namespace DirectWeb\Sites;
use DirectWeb\Core\{App,Files,Problem};
final class Preview
{
    public function __construct(private App $app){}
    public function url(array $u,string $site,string $release): string
    {
        return $this->link($u,$site,$release)['url'];
    }
    public function link(array $u,string $site,string $release,int $seconds=86400): array
    {
        $this->app->auth->access($u,$site);$this->app->sites->release($site,$release);
        if($seconds<60||$seconds>PHP_INT_MAX-time()){throw new Problem('Invalid duration.');}
        $expires=time()+$seconds;$host=strtolower($this->app->config['admin_host']);$base=$this->app->config['preview_base']??'';
        if($base!==''&&empty($this->app->config['wordpress'])){
            $host=$release.'.'.Files::host($base);if($host===strtolower($this->app->config['admin_host'])){throw new Problem('Invalid preview domain.');}
            $body=rtrim(strtr(base64_encode(Files::json(['site'=>$site,'release'=>$release,'host'=>$host,'expires'=>$expires])),'+/','-_'),'=');
            $signature=hash_hmac('sha256',$body,file_get_contents($this->app->storage('database/key')));
            $this->app->audit($u['id'],'preview.created',$site,['release_id'=>$release,'expires_at'=>$expires]);
            return ['site_id'=>$site,'release_id'=>$release,'url'=>($this->app->config['secure']?'https':'http').'://'.$host.'/?preview_token='.$body.'.'.$signature,'expires_at'=>gmdate('c',$expires),'mode'=>'domain'];
        }
        $body=rtrim(strtr(base64_encode(Files::json(['site'=>$site,'release'=>$release,'host'=>$host,'expires'=>$expires,'mode'=>'path'])),'+/','-_'),'=');
        $signature=hash_hmac('sha256',$body,file_get_contents($this->app->storage('database/key')));
        $url=($this->app->config['secure']?'https':'http').'://'.$host.$this->app->url('/preview/'.$body.'.'.$signature.'/');
        $this->app->audit($u['id'],'preview.created',$site,['release_id'=>$release,'expires_at'=>$expires]);
        return ['site_id'=>$site,'release_id'=>$release,'url'=>$url,'expires_at'=>gmdate('c',$expires),'mode'=>'isolated','limitations'=>['Form submissions and browser storage are disabled.','JavaScript URLs starting with / may need a separate preview domain.']];
    }
    public function serve(string $uri): void
    {
        if(!preg_match('~^/preview/([A-Za-z0-9_-]+\.[a-f0-9]{64})(/.*)?$~D',$uri,$match)){throw new Problem('Preview unavailable.',404);}
        [$body,$signature]=explode('.',$match[1],2);
        if(!hash_equals(hash_hmac('sha256',$body,file_get_contents($this->app->storage('database/key'))),$signature)){throw new Problem('Preview expired.',403);}
        $raw=base64_decode(strtr($body,'-_','+/'),true);$data=$raw===false?null:json_decode($raw,true);
        if(!is_array($data)||($data['mode']??'')!=='path'||($data['host']??'')!==strtolower($_SERVER['HTTP_HOST']??'')||($data['expires']??0)<=time()){throw new Problem('Preview expired.',403);}
        $a=$this->app;$site=$a->sites->get($data['site']);$manifest=$a->sites->readManifest($site['id'],$data['release']);
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){header('Allow: GET, HEAD');throw new Problem('Method not allowed.',405);}
        $mount=$a->url('/preview/'.$match[1].'/');
        if(!isset($match[2])){header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('Location: '.$mount,true,303);return;}
        $path=rawurldecode(ltrim($match[2],'/'));if($path!==''){Files::path(rtrim($path,'/'));}
        $file=null;foreach($path===''?[$manifest['start']]:[$path,rtrim($path,'/').'/index.html',rtrim($path,'/').'/index.htm',rtrim($path,'/').'.html',rtrim($path,'/').'.htm'] as $candidate){if(isset($manifest['files'][$candidate])){$file=$candidate;break;}}
        if(!$file&&$site['spa']&&pathinfo($path,PATHINFO_EXTENSION)===''){$file=$manifest['start'];}
        if(!$file){if(isset($manifest['files']['404.html'])){$file='404.html';http_response_code(404);}else{throw new Problem('Page not found.',404);}}
        $entry=$manifest['files'][$file];$source=$a->sites->dir($site['id'],$data['release']).'/files/'.$file;$extension=strtolower(pathinfo($file,PATHINFO_EXTENSION));$contents=null;
        if(in_array($extension,['html','htm','css'],true)){$document=new PreviewDocument($mount);$contents=$extension==='css'?$document->css(file_get_contents($source),$file):$document->html(file_get_contents($source),$file);}
        header('Content-Type: '.$entry['type']);header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow, noarchive');header('Referrer-Policy: no-referrer');header('Access-Control-Allow-Origin: *');
        header("Content-Security-Policy: sandbox allow-scripts allow-modals allow-downloads; worker-src 'none'; object-src 'none'; form-action 'none'; frame-ancestors 'none'");
        header('Content-Length: '.($contents===null?$entry['size']:strlen($contents)));
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){if($contents!==null){echo $contents;}else{readfile($source);}}
    }
    public function resolve(string $host): ?array
    {
        $base=$this->app->config['preview_base'];if($base===''||!str_ends_with($host,'.'.$base)){return null;}
        $token=(string)($_GET['preview_token']??$_COOKIE[$this->app->config['secure']?'__Host-dw-preview':'dw_preview']??'');$parts=explode('.',$token);if(count($parts)!==2||!hash_equals(hash_hmac('sha256',$parts[0],file_get_contents($this->app->storage('database/key'))),$parts[1])){throw new Problem('Preview expired.',403);}$raw=base64_decode(strtr($parts[0],'-_','+/'),true);$data=$raw===false?null:json_decode($raw,true);if(!$data||($data['host']??'')!==$host||($data['expires']??0)<time()){throw new Problem('Preview expired.',403);}
        $s=$this->app->sites->get($data['site']);$this->app->sites->release($data['site'],$data['release']);$s['active_release']=$data['release'];$s['enabled']=1;$s['_preview']=true;
        if(isset($_GET['preview_token'])){setcookie($this->app->config['secure']?'__Host-dw-preview':'dw_preview',$token,['expires'=>$data['expires'],'path'=>'/','secure'=>$this->app->config['secure'],'httponly'=>true,'samesite'=>'Lax']);header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('Location: /',true,303);exit;}
        return $s;
    }
}
