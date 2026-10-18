<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
final class Result
{
    public static function sections(Surface $p,array $data): void
    {
        foreach($data as $key=>$value){$label=ucfirst(str_replace('_',' ',(string)$key));
            if(is_array($value)&&array_is_list($value)&&$value&&is_array($value[0])){
                $columns=[];$rows=[];foreach(array_slice($value,0,200) as $row){$clean=[];foreach($row as $name=>$cell){if(in_array($name,['actor','password','token','secret','payload'],true)){continue;}$columns[$name]=ucfirst(str_replace('_',' ',$name));$clean[$name]=is_scalar($cell)||$cell===null?(string)$cell:json_encode($cell,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}$rows[]=$clean;}if($columns){$p->section($label,[$p->table($label,$columns,$rows)]);}
            }elseif(is_string($value)&&in_array($key,['download','previous','portal'],true)&&preg_match('~^(?:/(?!/)|https://)~',$value)){$base=$p->app->config['base_path'];if($base!==''&&str_starts_with($value,$base.'/')){$value=substr($value,strlen($base));}$p->section($label,[$p->link('Open',$value)],['directory'=>true]);}
            elseif(is_scalar($value)||$value===null){$p->section($label,[$p->text(is_bool($value)?($value?'Completed':'Failed'):(string)$value,false)]);}
            else{$p->section($label,[$p->node('codeBlock',['text'=>json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)])]);}
        }
    }
}
