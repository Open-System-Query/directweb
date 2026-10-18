<?php
declare(strict_types=1);
use DirectWeb\Core\{App,Files,Problem};
use DirectWeb\Plugins\{Module,Registry};
use DirectWeb\Http\Client;
use DirectWeb\Ui\Surface;
return new class implements DirectWeb\Plugins\Plugin {
public function register(App $app,Registry $registry): void {
$module=new class($app,"media","Media") extends Module {

protected function fields(): array {return ['source'=>['label'=>'Source file','required'=>true],'output'=>['label'=>'Output file','required'=>true],'width'=>['label'=>'Width','kind'=>'numberField','value'=>1600,'min'=>1,'max'=>8192],'height'=>['label'=>'Height','kind'=>'numberField','value'=>900,'min'=>1,'max'=>8192],'crop'=>['label'=>'Crop','kind'=>'checkBox','value'=>false],'quality'=>['label'=>'Quality','kind'=>'numberField','value'=>85,'min'=>1,'max'=>100],'format'=>['label'=>'Format','kind'=>'choice','value'=>'webp','options'=>[['value'=>'webp','label'=>'WebP'],['value'=>'avif','label'=>'AVIF'],['value'=>'jpg','label'=>'JPEG'],['value'=>'png','label'=>'PNG']]]];}
protected function validate(array $data,?array $old): array {Files::path($data['source']);Files::path($data['output']);if(pathinfo($data['output'],PATHINFO_EXTENSION)!==$data['format']){throw new Problem('Output extension must match format.');}return $data;}
protected function operations(): array {return ['convert'=>'Convert'];}
protected function execute(string $op,array $u,array $r,array $input): array
{
    if(!function_exists('imagecreatefromstring')){throw new Problem('Enable the GD extension.');}$s=$this->app->sites->get($r['site']);$d=$r['data'];$m=$this->app->sites->readManifest($s['id'],$s['active_release']);$meta=$m['files'][$d['source']]??throw new Problem('File missing.');if($meta['size']>16777216){throw new Problem('Image too large.');}
    $file=$this->app->sites->dir($s['id'],$s['active_release']).'/files/'.$d['source'];$size=getimagesize($file);if(!$size||$size[0]*$size[1]>24000000){throw new Problem('Image too large.');}$src=imagecreatefromstring(file_get_contents($file));if(!$src){throw new Problem('Invalid image.');}
    $scale=$d['crop']?max($d['width']/$size[0],$d['height']/$size[1]):min($d['width']/$size[0],$d['height']/$size[1],1);$w=$d['crop']?(int)$d['width']:max(1,(int)round($size[0]*$scale));$h=$d['crop']?(int)$d['height']:max(1,(int)round($size[1]*$scale));$dst=imagecreatetruecolor($w,$h);imagealphablending($dst,false);imagesavealpha($dst,true);imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));$sw=$w/$scale;$sh=$h/$scale;imagecopyresampled($dst,$src,0,0,(int)(($size[0]-$sw)/2),(int)(($size[1]-$sh)/2),$w,$h,(int)$sw,(int)$sh);
    $function=match($d['format']){'jpg'=>'imagejpeg','png'=>'imagepng','avif'=>'imageavif',default=>'imagewebp'};if(!function_exists($function)){throw new Problem('Image format unavailable.');}
    ob_start();try{$ok=$function($dst,null,$d['format']==='png'?6:(int)$d['quality']);$bytes=ob_get_contents();}finally{ob_end_clean();imagedestroy($src);imagedestroy($dst);}if(!$ok){throw new Problem('Conversion failed.');}return $this->publish($u,$s['id'],[$d['output']=>$bytes],$r['name']);
}

};$module->register($registry);
}
};
