<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(PHP_VERSION_ID<80100){fwrite(STDERR,"PHP 8.1 or newer is required.\n");exit(1);}
if(!extension_loaded('zip')){fwrite(STDERR,"PHP ZIP is required.\n");exit(1);}
$arguments=array_slice($argv,1);$pro=in_array('--pro',$arguments,true);$targets=array_values(array_filter($arguments,fn($value)=>$value!=='--pro'));$target=strtolower($targets[0]??'all');
if(in_array($target,['help','--help','-h'],true)){echo "php Build.php [all|webserver|wordpress|plugins] [--pro]\n";exit;}
if(!in_array($target,['all','webserver','wordpress','plugins'],true)){fwrite(STDERR,"Unknown target. Use all, webserver, wordpress or plugins.\n");exit(1);}
function copyTree(string $source,string $destination): void
{
    if(!is_dir($destination)&&!mkdir($destination,0750,true)){throw new RuntimeException('Cannot create '.$destination);}
    foreach(new DirectoryIterator($source) as $entry){
        if($entry->isDot()){continue;}
        if($entry->isLink()){throw new RuntimeException('Symbolic links are not supported.');}
        $path=$destination.'/'.$entry->getFilename();
        if($entry->isDir()){copyTree($entry->getPathname(),$path);}
        elseif(!copy($entry->getPathname(),$path)){throw new RuntimeException('Cannot copy '.$entry->getFilename());}
    }
}
function removeTree(string $directory): void
{
    if(!is_dir($directory)){return;}
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink()){rmdir($entry->getPathname());}else{unlink($entry->getPathname());}
    }
    rmdir($directory);
}
function catalogs(string $directory): void
{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $entry){
        if(!$entry->isFile()||$entry->getExtension()!=='po'){continue;}
        $messages=[];$id=null;$value='';$part='';
        foreach(file($entry->getPathname(),FILE_IGNORE_NEW_LINES) as $line){
            $line=trim($line);if($line===''||str_starts_with($line,'#')){continue;}
            if(preg_match('/^(msgid|msgstr) (".*")$/D',$line,$m)){
                if($m[1]==='msgid'){if($id!==null){$messages[$id]=$value;}$id=json_decode($m[2],true,512,JSON_THROW_ON_ERROR);$value='';$part='id';}
                else{$value=json_decode($m[2],true,512,JSON_THROW_ON_ERROR);$part='value';}
            }elseif(str_starts_with($line,'"')){$text=json_decode($line,true,512,JSON_THROW_ON_ERROR);if($part==='id'){$id.=$text;}else{$value.=$text;}}
            else{throw new RuntimeException('Invalid translation: '.$entry->getFilename());}
        }
        if($id!==null){$messages[$id]=$value;}ksort($messages,SORT_STRING);$count=count($messages);$offset=28+$count*16;$original='';$translated='';$originalTable='';$translatedTable='';
        foreach($messages as $key=>$value){$originalTable.=pack('V2',strlen((string)$key),$offset+strlen($original));$original.=$key."\0";}
        $offset+=strlen($original);foreach($messages as $value){$translatedTable.=pack('V2',strlen($value),$offset+strlen($translated));$translated.=$value."\0";}
        file_put_contents(substr($entry->getPathname(),0,-3).'.mo',pack('V7',0x950412de,0,$count,28,28+$count*8,0,0).$originalTable.$translatedTable.$original.$translated);
    }
}
function archive(string $directory,string $file,string $prefix=''): void
{
    $zip=new ZipArchive();if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){throw new RuntimeException('Cannot create archive.');}
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $entry){
        if($entry->isFile()){$name=str_replace('\\','/',substr($entry->getPathname(),strlen($directory)+1));$zip->addFile($entry->getPathname(),$prefix.$name);}
    }
    if(!$zip->close()){throw new RuntimeException('Cannot finish archive.');}
}
$root=__DIR__;$output=$pro?dirname($root).'/Artifacts':$root.'/Build';$assets=$output.'/Assets/FontAwesome';$stage=$output.'/.stage-'.bin2hex(random_bytes(8));$failed=false;
try{
    if(count($targets)>1){throw new RuntimeException('Choose one build target.');}
    if($pro&&(!is_dir($assets.'/icons')||!is_file($assets.'/LICENSE'))){throw new RuntimeException('Add licensed icons and LICENSE to Artifacts/Assets/FontAwesome.');}
    if(!is_dir($output.'/Packages')){mkdir($output.'/Packages',0750,true);}
    mkdir($stage,0750,true);
    foreach(['webserver'=>'Webserver','wordpress'=>'WordPress'] as $key=>$name){
        if($target!=='all'&&$target!==$key){continue;}
        $destination=$output.'/'.$name;$runtime=$key==='wordpress'?$destination.'/directweb/runtime':$destination;
        if(is_dir($runtime.'/storage')||($key==='webserver'&&is_file($runtime.'/config/local.php'))){throw new RuntimeException('Build/'.$name.' contains local installation data. Move it before rebuilding.');}
        $folder=$stage.'/'.$name;$package=$key==='wordpress'?$folder.'/directweb':$folder;
        copyTree($root.'/Source/Shared',$key==='wordpress'?$package.'/runtime':$package);
        copyTree($root.'/Source/'.$name,$package);
        if($pro){$assetRoot=$key==='wordpress'?$package.'/runtime':$package;foreach(glob($assetRoot.'/public/assets/icons/*.svg') as $icon){$licensed=$assets.'/icons/'.basename($icon);if(!is_file($licensed)){throw new RuntimeException('Licensed icon missing: '.basename($icon));}if(!copy($licensed,$icon)){throw new RuntimeException('Cannot copy licensed icon.');}}copy($assets.'/LICENSE',$assetRoot.'/licenses/fontawesome.txt');}
        catalogs($package);
        if(is_file($root.'/LICENSE')){copy($root.'/LICENSE',$package.'/LICENSE');}
        archive($package,$output.'/Packages/directweb-'.$key.'.zip',$key==='wordpress'?'directweb/':'');
        removeTree($destination);rename($folder,$destination);echo ($pro?'Artifacts/':'Build/').$name.PHP_EOL;
    }
    if(in_array($target,['all','plugins'],true)){
        if(!is_dir($output.'/Packages/Plugins')){mkdir($output.'/Packages/Plugins',0750,true);}
        foreach(glob($root.'/Source/Shared/plugins/*/plugin.json') as $manifest){
            $plugin=json_decode(file_get_contents($manifest),true,32,JSON_THROW_ON_ERROR);$folder=$stage.'/plugins/'.$plugin['id'];copyTree(dirname($manifest),$folder);catalogs($folder);if(is_file($root.'/LICENSE')){copy($root.'/LICENSE',$folder.'/LICENSE');}
            archive($folder,$output.'/Packages/Plugins/'.$plugin['id'].'.zip');
        }
        echo ($pro?"Artifacts/":"Build/")."Packages/Plugins\n";
    }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);$failed=true;}
finally{removeTree($stage);}

if($failed){exit(1);}
