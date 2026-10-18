<?php
declare(strict_types=1);
namespace DirectWebSharing;
use DirectWeb\Core\{App,Files,Problem};
final class Uploads
{
    public const CHUNK=1048576;
    public function __construct(private App $app,private Sharing $sharing){}
    private function pending(string $id,?array $u): array
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)){throw new Problem('Upload expired.',410);}$row=$this->app->db->one('SELECT * FROM share_uploads WHERE id=? AND session_hash=?',[$id,hash('sha256',session_id())]);if(!$row||$row['owner']!==$this->sharing->actor($u)||$row['expires_at']<time()){throw new Problem('Upload expired.',410);}return $row;
    }
    public function begin(?array $u,array $input,string $ip): array
    {
        $this->sharing->requireTransfer($u,(string)($input['request']??''));$this->sharing->prune();$this->app->auth->limit('share-upload:'.hash('sha256',$ip),$this->sharing->settings()['upload_rate'],3600);$files=$input['files']??[];if(!is_array($files)){throw new Problem('Check transfer settings.');}$options=$this->sharing->validate($files,$input);$options['password']=$this->app->seal($options['password']);
        return $this->app->lock(function()use($u,$files,$options){if($this->sharing->usage()+$options['size']>$this->sharing->settings()['storage_mb']*1048576){throw new Problem('Storage limit reached.');}$id=Files::id();$dir=$this->app->storage('plugins/sharing/uploads/'.$id);Files::mkdir($dir);try{foreach($files as $index=>$file){if(!is_int($index)){throw new Problem('Invalid request.');}Files::write($dir.'/'.$index,'');}$this->app->db->execute('INSERT INTO share_uploads VALUES(?,?,?,?,?,?,?,NULL)',[$id,$this->sharing->actor($u),hash('sha256',session_id()),Files::json(array_values($files)),Files::json($options),$options['size'],time()+7200]);}catch(\Throwable $e){Files::remove($dir);throw $e;}return ['id'=>$id,'chunk'=>self::CHUNK];});
    }
    public function chunk(?array $u,string $id,int $index,int $offset,string $bytes): array
    {
        return $this->app->lock(function()use($u,$id,$index,$offset,$bytes){$row=$this->pending($id,$u);$options=json_decode($row['options'],true);$this->sharing->requireTransfer($u,$options['request']??'');$files=json_decode($row['manifest'],true);$file=$files[$index]??null;$length=strlen($bytes);if($row['result']||!$file||$offset<0||$length<1||$length>self::CHUNK||$offset+$length>$file['size']){throw new Problem('Invalid upload chunk.');}$path=$this->app->storage('plugins/sharing/uploads/'.$id.'/'.$index);$handle=fopen($path,'c+b');if(!$handle){throw new Problem('Upload failed.');}
            try{$current=fstat($handle)['size'];if($offset<$current&&$offset+$length<=$current){fseek($handle,$offset);if(hash_equals(hash('sha256',fread($handle,$length)),hash('sha256',$bytes))){return ['offset'=>$offset+$length];}}if($offset!==$current){throw new Problem('Upload position changed.',409);}fseek($handle,$offset);if(fwrite($handle,$bytes)!==$length||!fflush($handle)){ftruncate($handle,$offset);throw new Problem('Upload failed.');}$this->app->db->execute('UPDATE share_uploads SET expires_at=? WHERE id=?',[time()+7200,$id]);return ['offset'=>$offset+$length];}finally{fclose($handle);}
        });
    }
    public function finish(?array $u,string $id): array
    {
        $row=$this->pending($id,$u);$options=json_decode($row['options'],true);$this->sharing->requireTransfer($u,$options['request']??'');if($row['result']){return json_decode($row['result'],true);}$manifest=json_decode($row['manifest'],true);$uploads=[];foreach($manifest as $index=>$file){$path=$this->app->storage('plugins/sharing/uploads/'.$id.'/'.$index);if(!is_file($path)||filesize($path)!==$file['size']){throw new Problem('Upload incomplete.');}$uploads[]=['name'=>$file['name'],'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK];}$options=json_decode($row['options'],true);$options['password']=$this->app->unseal($options['password']);$result=$this->sharing->create($u,$uploads,$options,$id);Files::remove($this->app->storage('plugins/sharing/uploads/'.$id));return $result;
    }
    public function status(?array $u,string $id): array
    {
        $r=$this->pending($id,$u);$options=json_decode($r['options'],true);$this->sharing->requireTransfer($u,$options['request']??'');if($r['result']){return ['result'=>json_decode($r['result'],true)];}
        $files=json_decode($r['manifest'],true);$offsets=[];$hashes=[];foreach($files as $index=>$file){$path=$this->app->storage('plugins/sharing/uploads/'.$id.'/'.$index);$offsets[]=filesize($path);$hashes[]=hash_file('sha256',$path);}return ['id'=>$id,'chunk'=>self::CHUNK,'files'=>$files,'offsets'=>$offsets,'hashes'=>$hashes];
    }
    public function cancel(?array $u,string $id): array
    {
        return $this->app->lock(function()use($u,$id){$row=$this->pending($id,$u);if($row['result']){return json_decode($row['result'],true);}Files::remove($this->app->storage('plugins/sharing/uploads/'.$id));$this->app->db->execute('DELETE FROM share_uploads WHERE id=?',[$id]);return ['cancelled'=>true];});
    }
    public function http(?array $u): void
    {
        header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
        try{if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){throw new Problem('Use POST.',405);}$this->app->auth->csrf($_SERVER['HTTP_X_CSRF_TOKEN']??'');$operation=(string)($_GET['action']??'');$maximum=$operation==='chunk'?self::CHUNK:131072;if((int)($_SERVER['CONTENT_LENGTH']??0)>$maximum){throw new Problem('Upload too large.',413);}$raw=file_get_contents('php://input',false,null,0,$maximum+1);if($raw===false||strlen($raw)>$maximum){throw new Problem('Upload too large.',413);}
            if($operation==='chunk'){$index=filter_var($_GET['file']??null,FILTER_VALIDATE_INT);$offset=filter_var($_GET['offset']??null,FILTER_VALIDATE_INT);if($index===false||$offset===false){throw new Problem('Invalid request.');}$result=$this->chunk($u,(string)($_GET['id']??''),$index,$offset,$raw);}
            else{$input=json_decode($raw,true);if(!is_array($input)){throw new Problem('Invalid request.');}$result=match($operation){'status'=>$this->status($u,(string)($input['id']??'')),'begin'=>$this->begin($u,$input,$_SERVER['REMOTE_ADDR']??''),'finish'=>$this->finish($u,(string)($input['id']??'')),'cancel'=>$this->cancel($u,(string)($input['id']??'')),default=>throw new Problem('Invalid request.')};}
            echo Files::json($result);
        }catch(Problem $e){http_response_code($e->status);echo Files::json(['error'=>$this->app->translator->get($e->key,...$e->parameters)]);}catch(\Throwable $e){error_log('DirectWeb sharing upload: '.$e->getMessage());http_response_code(500);echo Files::json(['error'=>$this->app->translator->get('Upload failed.')]);}
    }
}
