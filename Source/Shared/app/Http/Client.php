<?php
declare(strict_types=1);
namespace DirectWeb\Http;
use DirectWeb\Core\{Files,Problem};
final class Client
{
    public static function request(string $method,string $url,array $headers=[],string $body='',int $limit=8388608): array
    {
        $parts=parse_url($url);$host=$parts['host']??'';$port=(int)($parts['port']??443);
        if(($parts['scheme']??'')!=='https'||!preg_match('/^[a-z0-9.-]+$/iD',$host)||(isset($parts['user'])||isset($parts['pass']))||isset($parts['fragment'])||$port<1||$port>65535||preg_match('/[\x00-\x20\x7f]/',$url)){throw new Problem('Use a public HTTPS URL.');}
        $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:gethostbynamel($host);if(!$ips){throw new Problem('Host unavailable.');}
        foreach($ips as $ip){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){throw new Problem('Private addresses are not allowed.');}}
        $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'SNI_enabled'=>true,'capture_peer_cert'=>true,'disable_compression'=>true]]);
        $socket=@stream_socket_client('tls://'.$ips[0].':'.$port,$errno,$error,15,STREAM_CLIENT_CONNECT,$ctx);if(!$socket){throw new Problem('Connection failed.');}stream_set_timeout($socket,30);
        try{
            $path=($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'');$hostHeader=$host.($port===443?'':':'.$port);
            $headers=['Host'=>$hostHeader,'User-Agent'=>'DirectWeb','Accept-Encoding'=>'identity','Connection'=>'close','Content-Length'=>(string)strlen($body)]+$headers;
            $request=$method.' '.$path." HTTP/1.1\r\n";foreach($headers as $key=>$value){if(!preg_match('/^[A-Za-z0-9-]+$/D',$key)||preg_match('/[\r\n]/',(string)$value)){throw new Problem('Invalid header.');}$request.=$key.': '.$value."\r\n";}$request.="\r\n".$body;
            for($offset=0;$offset<strlen($request);){$written=fwrite($socket,substr($request,$offset,65536));if(!$written){throw new Problem('Connection interrupted.');}$offset+=$written;}
            $line=fgets($socket,8192);if(!$line||!preg_match('~^HTTP/\d(?:\.\d)? (\d{3})~',$line,$match)){throw new Problem('Invalid response.');}$status=(int)$match[1];$responseHeaders=[];$size=0;
            while(($line=fgets($socket,8192))!==false&&trim($line)!==''){$size+=strlen($line);if($size>65536){throw new Problem('Response too large.');}$split=explode(':',$line,2);if(count($split)===2){$responseHeaders[strtolower(trim($split[0]))]=trim($split[1]);}}
            $data='';if($method!=='HEAD'){
                if(strtolower($responseHeaders['transfer-encoding']??'')==='chunked'){
                    while(true){$line=fgets($socket,128);if($line===false||!preg_match('/^[a-f0-9]+(?:;[^\r\n]*)?\r?\n$/iD',$line)){throw new Problem('Invalid response.');}$count=hexdec(explode(';',trim($line))[0]);if($count===0){break;}if(strlen($data)+$count>$limit){throw new Problem('Response too large.');}while($count>0){$chunk=fread($socket,min(65536,$count));if($chunk===false||$chunk===''){throw new Problem('Incomplete response.');}$data.=$chunk;$count-=strlen($chunk);}if(fread($socket,2)!=="\r\n"){throw new Problem('Invalid response.');}}
                }else{$data=stream_get_contents($socket,$limit+1);if($data===false||strlen($data)>$limit){throw new Problem('Response too large.');}if(isset($responseHeaders['content-length'])&&strlen($data)!==(int)$responseHeaders['content-length']){throw new Problem('Incomplete response.');}}
            }
            if(stream_get_meta_data($socket)['timed_out']){throw new Problem('Connection timed out.');}$certificate=stream_context_get_params($socket)['options']['ssl']['peer_certificate']??null;$cert=$certificate?openssl_x509_parse($certificate):[];
            return ['status'=>$status,'headers'=>$responseHeaders,'body'=>$data,'certificate_expires'=>$cert['validTo_time_t']??null];
        }finally{fclose($socket);}
    }
    public static function fetch(string $url,array $headers=[],int $limit=8388608): array
    {
        for($redirect=0;$redirect<5;$redirect++){
            $response=self::request('GET',$url,$headers,'',$limit);if(!in_array($response['status'],[301,302,303,307,308],true)){return $response;}
            $location=$response['headers']['location']??throw new Problem('Invalid redirect.');$base=parse_url($url);
            if(str_starts_with($location,'/')){if(str_starts_with($location,'//')){$location='https:'.$location;}else{$location='https://'.$base['host'].(isset($base['port'])?':'.$base['port']:'').$location;}}
            elseif(!str_starts_with($location,'https://')){$location='https://'.$base['host'].(isset($base['port'])?':'.$base['port']:'').rtrim(dirname($base['path']??'/'),'/').'/'.$location;}
            if(parse_url($location,PHP_URL_HOST)!==$base['host']||parse_url($location,PHP_URL_PORT)!==($base['port']??null)){unset($headers['Authorization'],$headers['PRIVATE-TOKEN']);}$url=$location;
        }
        throw new Problem('Too many redirects.');
    }
    public static function json(string $method,string $url,array $headers=[],?array $data=null): array
    {
        $r=self::request($method,$url,['Content-Type'=>'application/json','Accept'=>'application/json']+$headers,$data===null?'':Files::json($data));
        if($r['status']<200||$r['status']>=300){throw new Problem('Provider returned HTTP %s.',422,[(string)$r['status']]);}$out=json_decode($r['body'],true,64,JSON_THROW_ON_ERROR);if(!is_array($out)){throw new Problem('Invalid provider response.');}return $out;
    }
    public static function download(string $file,string $name,string $type='application/octet-stream'): void
    {
        if(!is_file($file)){throw new Problem('File missing.',404);}header('Content-Type: '.$type);header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\''.rawurlencode($name));header('Content-Length: '.filesize($file));header('Cache-Control: no-store');header('Content-Security-Policy: sandbox');header('X-Content-Type-Options: nosniff');readfile($file);
    }
}
