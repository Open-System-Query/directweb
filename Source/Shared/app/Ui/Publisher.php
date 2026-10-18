<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\{App,Problem};
final class Publisher implements \Lumui\Contracts\SurfaceProvider
{
    public function __construct(private App $app,private array $view){}
    public function routeIdFromPath(string $path): string {return $this->view['surface_id'];}
    public function routeIdFromSurfaceId(string $surfaceId): ?string {return $surfaceId===$this->view['surface_id']?$surfaceId:null;}
    public function surface(string $routeId): array {if($routeId!==$this->view['surface_id']){throw new Problem('Page expired.',409);}return $this->view;}
    public function descriptor(): array {return self::description();}
    public function routes(): array {return [$this->view['surface_id']=>$this->view['metadata']['web']['path']??'/admin'];}
    public function revision(): int {return max(1,(int)($this->app->db->one('SELECT MAX(id) AS id FROM audit')['id']??1));}
    public static function document(array $surface): array
    {
        $normalize=function(array $value)use(&$normalize): array {foreach($value as $key=>&$item){if(is_array($item)){$item=$normalize($item);if($item===[]&&in_array($key,['metadata','properties'],true)){$item=(object)[];}}}unset($item);return $value;};
        $surface=$normalize($surface);if($surface['actions']===[]){$surface['actions']=(object)[];}return $surface;
    }
    public static function description(): array
    {
        return ['lumui_web'=>'1.0','id'=>'directweb','title'=>'DirectWeb','surface'=>['href'=>'/admin','type'=>'application/lumui+json'],'fallback'=>['href'=>'/admin','type'=>'text/html'],'actions'=>['href'=>'/admin/actions','type'=>'application/lumui+json','method'=>'POST','max_bytes'=>1048576],'protocol'=>['surface'=>'1.0','message'=>'1.0'],'authentication'=>['mode'=>'session','login'=>'/admin','note'=>'X-CSRF-Token'],'languages'=>array_keys(Translator::LANGUAGES),'profiles'=>['desktop','phone'],'outputs'=>['visual','screenReader']];
    }
}
