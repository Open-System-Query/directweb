<?php
declare(strict_types=1);
namespace DirectWebMcp;
use DirectWeb\Core\{App,Files,Problem};
final class Uploads
{
    private const DOWNLOAD_LIMIT=16777216;
    private const IMAGES=['png','jpg','jpeg','gif','webp','ico'];
    public function __construct(private App $app){}
    public function store(array $u,string $change,array $encoded,array $urls): array
    {
        if(!$encoded&&!$urls){throw new Problem('Add files or urls.');}if(count($encoded)+count($urls)>50){throw new Problem('Too many files.');}
        $this->app->sites->change($u,$change);$entries=[];$temporary=[];$seen=[];
        try{
            foreach($encoded as $path=>$data){
                $path=$this->target((string)$path);$data=is_string($data)?(string)preg_replace('/\s+/','',(string)preg_replace('~^data:[^,]*,~','',$data)):'';$bytes=base64_decode(strtr($data,'-_','+/'),true);
                if($bytes===false||$bytes===''){throw new Problem('Invalid base64 for %s.',422,[$path]);}$seen[strtolower($path)]=true;$entries[]=['path'=>$path,'entry'=>['data'=>$bytes]];
            }
            foreach($urls as $path=>$url){
                $path=$this->target((string)$path);if(isset($seen[strtolower($path)])){throw new Problem('Duplicate filename.');}$seen[strtolower($path)]=true;
                $response=\DirectWeb\Http\Client::fetch((string)$url,['Accept'=>'*/*'],self::DOWNLOAD_LIMIT);if($response['status']!==200||$response['body']===''){throw new Problem('Download failed for %s (HTTP %s).',422,[$path,(string)$response['status']]);}
                $file=$this->app->storage('staging/'.Files::id().'.download');$temporary[]=$file;if(file_put_contents($file,$response['body'])!==strlen($response['body'])){throw new Problem('Save failed.',503);}unset($response);
                $entries[]=['path'=>$path,'entry'=>['source'=>$file]];
            }
            $details=[];foreach($entries as $index=>$item){$details[$index]=$this->inspect($item['path'],$item['entry']);}
            $result=$this->write($u,$change,$entries);
        }finally{foreach($temporary as $file){@unlink($file);}}
        $uploaded=[];foreach($result['written'] as $index=>$written){$uploaded[]=$details[$index]+['replaced'=>$written['replaced']];}
        return ['change_id'=>$change,'uploaded'=>$uploaded,'files'=>$result['files'],'size'=>$result['size'],'next_action'=>'Reference the uploaded paths relative to the pages that use them, then call changes_validate and changes_commit as usual.'];
    }
    public function link(array $u,string $change,string $folder,int $seconds): array
    {
        $c=$this->app->sites->change($u,$change);$folder=trim(str_replace('\\','/',$folder),'/');if($folder!==''){Files::path($folder);}
        if($seconds<300||$seconds>86400){throw new Problem('Invalid duration.');}$expires=time()+$seconds;
        $body=rtrim(strtr(base64_encode(Files::json(['change'=>$change,'user'=>$u['id'],'limits'=>isset($u['_token'])?['sites'=>$u['_token']['sites'],'scopes'=>$u['_token']['scopes']]:null,'folder'=>$folder,'expires'=>$expires])),'+/','-_'),'=');
        $url=($this->app->config['secure']?'https':'http').'://'.$this->app->config['admin_host'].$this->app->url('/mcp/upload/'.$body.'.'.$this->sign($body));
        $this->app->audit($u['id'],'mcp.upload_link',$c['site_id'],['change_id'=>$change,'expires_at'=>$expires]);
        $where=$folder!==''?' under '.$folder.'/':'';
        return ['change_id'=>$change,'url'=>$url,'folder'=>$folder,'expires_at'=>gmdate('c',$expires),
            'upload_command'=>'curl -sS -H "Accept: application/json" -F "files[]=@local/photo.jpg" -F "files[]=@local/logo.png;filename=sub/logo.png" "'.$url.'"',
            'next_action'=>'If you can run shell commands and the files are on this computer, upload them yourself with upload_command (use curl.exe in Windows PowerShell): one -F "files[]=@<local file>" per file, and add ;filename=<relative/path> to place a file in a subfolder'.($where!==''?' of '.$folder.'/':'').'. The JSON reply lists each saved path. Only when the files are on the user\'s device, share the link so they can choose them and press Upload. Files keep their names'.$where.', and a file with the same path replaces the existing one. Then call changes_validate to confirm the paths before referencing them. The link only works while this change is open and does not publish anything.'];
    }
    public function http(string $token): void
    {
        $json=str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json');
        header('Content-Type: '.($json?'application/json':'text/html; charset=utf-8'));header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        $saved=[];$errors=[];
        try{
            [$u,$data,$c]=$this->open($token);$site=$this->app->sites->get($c['site_id']);
            if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
                $this->app->auth->limit('mcp-upload:'.hash('sha256',$token),60,3600);$files=$this->received();
                if(!$files){$limit=$this->bytes((string)ini_get('post_max_size'));$errors[]=['',$limit>0&&(int)($_SERVER['CONTENT_LENGTH']??0)>$limit?'The upload is larger than this server allows.':'Choose at least one file.'];}
                $entries=[];
                foreach($files as $f){
                    $name=(string)$f['full'];
                    if($f['error']!==UPLOAD_ERR_OK){$errors[]=[$name,in_array($f['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)?'The file is larger than this server allows.':'Upload failed.'];continue;}
                    try{if(!is_uploaded_file($f['tmp_name'])){throw new Problem('Upload failed.');}$path=$this->target(($data['folder']!==''?$data['folder'].'/':'').$this->name($name));$entry=['source'=>$f['tmp_name']];$this->inspect($path,$entry);unset($entries[strtolower($path)]);$entries[strtolower($path)]=['path'=>$path,'entry'=>$entry,'name'=>$name];}
                    catch(Problem $e){$errors[]=[$name,$e->getMessage()];}
                }
                if($entries){
                    try{$result=$this->write($u,$data['change'],$entries);foreach($result['written'] as $index=>$written){$saved[]=[$entries[$index]['name'],$written['path'],$written['replaced']];}$this->app->audit($u['id'],'mcp.uploaded',$c['site_id'],['change_id'=>$data['change'],'files'=>array_column($result['written'],'path')]);}
                    catch(Problem $e){$errors[]=['',$e->getMessage()];}
                }
            }
            if($json){if(!$saved&&$errors){http_response_code(422);}echo Files::json(['site'=>(string)$site['name'],'folder'=>$data['folder'],'expires_at'=>gmdate('c',(int)$data['expires']),'saved'=>array_map(fn($s)=>['name'=>$s[0],'path'=>$s[1],'replaced'=>$s[2]],$saved),'errors'=>array_map(fn($e)=>['name'=>$e[0],'message'=>$e[1]],$errors)]);return;}
            echo $this->page((string)$site['name'],$data,$saved,$errors);
        }catch(Problem $e){http_response_code($e->status);echo $json?Files::json(['error'=>$e->getMessage()]):$this->page('',null,$saved,[['',$e->getMessage()]]);}
        catch(\Throwable $e){error_log('DirectWeb MCP upload: '.$e->getMessage());http_response_code(500);echo $json?Files::json(['error'=>'Upload failed.']):$this->page('',null,$saved,[['','Upload failed.']]);}
    }
    private function open(string $token): array
    {
        $parts=explode('.',$token);if(count($parts)!==2||!hash_equals($this->sign($parts[0]),$parts[1])){throw new Problem('This upload link is invalid.',403);}
        $raw=base64_decode(strtr($parts[0],'-_','+/'),true);$data=$raw===false?null:json_decode($raw,true);
        if(!is_array($data)||!is_string($data['change']??null)||!is_string($data['user']??null)||!is_string($data['folder']??null)||(int)($data['expires']??0)<=time()){throw new Problem('This upload link has expired. Ask Claude for a new one.',410);}
        $u=$this->app->auth->user($data['user']);
        if(is_array($data['limits']??null)){$u['_token']=['id'=>'','sites'=>(string)($data['limits']['sites']??'[]'),'scopes'=>(string)($data['limits']['scopes']??'[]')];}
        try{$c=$this->app->sites->change($u,$data['change']);}catch(Problem $e){if($e->status===403){throw $e;}throw new Problem('This draft is already saved or closed. Ask Claude for a new upload link.',410);}
        return [$u,$data,$c];
    }
    private function sign(string $body): string { return hash_hmac('sha256','mcp-upload:'.$body,file_get_contents($this->app->storage('database/key'))); }
    private function target(string $path): string { $path=trim(str_replace('\\','/',$path),'/');Files::path($path);$this->app->sites->type($path);return $path; }
    private function name(string $name): string
    {
        $parts=[];
        foreach(explode('/',str_replace('\\','/',$name)) as $part){$part=rtrim(ltrim((string)preg_replace('~[:\x00-\x1f\x7f<>"|?*]+~','-',$part),'. '),'. ');if($part!==''){$parts[]=$part;}}
        $name=implode('/',$parts);if($name===''||pathinfo($name,PATHINFO_EXTENSION)===''){throw new Problem('File type not allowed: %s',422,[$name]);}return $name;
    }
    private function inspect(string $path,array $entry): array
    {
        $size=isset($entry['data'])?strlen($entry['data']):(int)filesize($entry['source']);if($size<1||$size>$this->app->config['max_upload']){throw new Problem('File too large.');}
        $out=['path'=>$path,'size'=>$size,'type'=>$this->app->sites->type($path)];$extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if(in_array($extension,self::IMAGES,true)){$info=isset($entry['data'])?@getimagesizefromstring($entry['data']):@getimagesize($entry['source']);if(!$info){throw new Problem('%s is not a valid image.',422,[$path]);}$out['width']=$info[0];$out['height']=$info[1];}
        return $out;
    }
    private function write(array $u,string $change,array $entries): array
    {
        return $this->app->lock(function()use($u,$change,$entries){
            $this->app->sites->change($u,$change);$base=$this->app->storage('staging/'.$change.'/files');$spare=$this->app->storage('staging/'.$change.'/replaced-'.Files::id());$moved=[];$created=[];$written=[];
            try{
                foreach($entries as $index=>$item){
                    $path=$item['path'];$entry=$item['entry'];$target=$base.'/'.$path;$replaced=false;
                    if(is_dir(dirname($target))){foreach(scandir(dirname($target))?:[] as $existing){$old=dirname($target).'/'.$existing;if(strtolower($existing)===strtolower(basename($path))&&is_file($old)){Files::mkdir($spare);$keep=$spare.'/'.Files::id();if(!rename($old,$keep)){throw new Problem('Save failed.',503);}$moved[]=[$keep,$old];$replaced=true;}}}
                    if(isset($entry['data'])){Files::write($target,$entry['data']);}
                    else{Files::mkdir(dirname($target));$tmp=$target.'.'.Files::id().'.tmp';if(!copy($entry['source'],$tmp)||!rename($tmp,$target)){@unlink($tmp);throw new Problem('Save failed.',503);}}
                    $created[]=$target;$written[$index]=['path'=>$path,'replaced'=>$replaced];
                }
                $manifest=$this->app->sites->manifest($base,false);
            }catch(\Throwable $e){
                foreach(array_reverse($created) as $file){@unlink($file);}foreach(array_reverse($moved) as [$keep,$old]){Files::mkdir(dirname($old));@rename($keep,$old);}Files::remove($spare);throw $e;
            }
            Files::remove($spare);return ['written'=>$written,'files'=>$manifest['count'],'size'=>$manifest['size']];
        });
    }
    private function bytes(string $value): int
    {
        $value=trim($value);if($value===''){return 0;}$number=(int)$value;return match(strtolower(substr($value,-1))){'g'=>$number*1073741824,'m'=>$number*1048576,'k'=>$number*1024,default=>$number};
    }
    private function received(): array
    {
        $f=$_FILES['files']??null;if(!is_array($f)||!isset($f['name'])){return [];}
        if(!is_array($f['name'])){return [['name'=>$f['name'],'full'=>(string)($f['full_path']??$f['name']),'tmp_name'=>$f['tmp_name'],'error'=>$f['error']]];}
        $out=[];foreach($f['name'] as $i=>$n){if($n===''&&($f['error'][$i]??0)===UPLOAD_ERR_NO_FILE){continue;}$out[]=['name'=>$n,'full'=>(string)($f['full_path'][$i]??$n),'tmp_name'=>$f['tmp_name'][$i],'error'=>$f['error'][$i]];}return $out;
    }
    private function page(string $site,?array $data,array $saved,array $errors): string
    {
        $e=fn(string $v): string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Upload files</title><style>'
            .':root{color-scheme:light dark;--bg:#f6f7f9;--card:#fff;--text:#1c1e21;--muted:#62666d;--line:#d9dce1;--accent:#2f6fde;--ok:#1d7a46;--bad:#b42318}'
            .'@media(prefers-color-scheme:dark){:root{--bg:#16181b;--card:#1f2226;--text:#e8eaed;--muted:#a0a4ab;--line:#34383e;--accent:#6ea0ff;--ok:#5cc58b;--bad:#ff8a80}}'
            .'body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}main{max-width:560px;margin:40px auto;padding:0 16px}'
            .'.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:24px}h1{font-size:1.4rem;margin:0 0 4px}p{margin:8px 0}.muted{color:var(--muted);font-size:.9rem}'
            .'input[type=file]{display:block;width:100%;box-sizing:border-box;margin:16px 0;padding:24px 12px;border:2px dashed var(--line);border-radius:10px;background:transparent;color:inherit}'
            .'button{background:var(--accent);color:#fff;border:0;border-radius:8px;padding:10px 20px;font:inherit;font-weight:600;cursor:pointer}ul{padding-left:20px}.ok{color:var(--ok)}.bad{color:var(--bad)}code{font-size:.9em;word-break:break-all}'
            .'</style></head><body><main><div class="card">';
        $html.='<h1>Upload files'.($site!==''?' to '.$e($site):'').'</h1>';
        if($saved){$html.='<p class="ok"><strong>Uploaded:</strong></p><ul>';foreach($saved as [$name,$path,$replaced]){$html.='<li>'.$e($name).' → <code>'.$e($path).'</code>'.($replaced?' (replaced)':'').'</li>';}$html.='</ul><p>Upload more files, or go back to Claude and say the files are uploaded.</p>';}
        if($errors){$html.='<ul class="bad">';foreach($errors as [$name,$message]){$html.='<li>'.($name!==''?$e($name).': ':'').$e($message).'</li>';}$html.='</ul>';}
        if($data!==null){
            $html.='<form method="post" enctype="multipart/form-data"><input type="file" name="files[]" multiple required><button type="submit">Upload</button></form>';
            $html.='<p class="muted">Files are added to the draft Claude is working on'.($data['folder']!==''?', in <code>'.$e($data['folder']).'/</code>':'').'. A file with the same name replaces the existing one. Nothing goes live until you approve publishing.</p>';
            $html.='<p class="muted">Allowed: web pages, images (JPG, PNG, WebP, GIF, AVIF, SVG, ICO), PDF, fonts, audio and video. Up to '.$e((string)ini_get('upload_max_filesize')).' per file. iPhone HEIC photos must be saved as JPG first. This link expires '.$e(gmdate('Y-m-d H:i',(int)$data['expires'])).' UTC.</p>';
        }
        return $html.'</div></main></body></html>';
    }
}
