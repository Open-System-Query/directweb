<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\App;
use Lumui\Http\{Request,Response,WebPublisher};
use Lumui\Protocol\SurfaceValidator;
final class Output
{
    public function __construct(private App $app){}
    public function send(array $surface,int $status=200): void
    {
        $a=$this->app;$surface['metadata']['web']=['path'=>$_SERVER['REQUEST_URI']??'/admin','surface_href'=>$_SERVER['REQUEST_URI']??'/admin'];$validator=new SurfaceValidator($a->root.'/packages/lumui/resources/component-catalog.json',$a->root.'/packages/lumui/resources/schemas/surface.schema.json');$validator->validate($surface);
        if(session_status()===PHP_SESSION_ACTIVE){$_SESSION['lumui_views'][$surface['surface_id']]=['surface'=>$surface,'expires'=>time()+1800];if(count($_SESSION['lumui_views'])>20){array_shift($_SESSION['lumui_views']);}header('X-CSRF-Token: '.$_SESSION['csrf']);}
        $renderer=new class($a) implements \Lumui\Contracts\SurfaceRenderer {
            public function __construct(private App $app){}
            public function render(array $surface): string
            {
                try{return $this->app->plugins->renderer()->render($surface);}catch(\Throwable $e){error_log('DirectWeb renderer failed: '.$e->getMessage());return (new Renderer($this->app))->render($surface);}
            }
        };
        $query=[];foreach($_GET as $key=>$value){if(is_scalar($value)){$query[$key]=(string)$value;}}
        $request=new Request('GET',$_SERVER['REQUEST_URI']??'/admin',['Accept'=>$_SERVER['HTTP_ACCEPT']??''],$query);
        $response=(new WebPublisher(new Publisher($a,$surface),$validator,$renderer))->handle($request);$headers=$response->headers();$headers['Cache-Control']='no-store';unset($headers['ETag']);
        $body=$response->body();if($response->status()===200&&str_starts_with($headers['Content-Type'],'application/lumui+json')){$body=json_encode($a->publicDocument(Publisher::document($surface)),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);$headers['Content-Length']=(string)strlen($body);}
        (new Response($response->status()===200?$status:$response->status(),$headers,$body))->send(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD');
    }
}
