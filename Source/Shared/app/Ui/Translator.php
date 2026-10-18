<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
final class Translator
{
    public const LANGUAGES=['en'=>'English','nl'=>'Nederlands','de'=>'Deutsch','fr'=>'Français','es'=>'Español','it'=>'Italiano','pt'=>'Português','pl'=>'Polski','sv'=>'Svenska','da'=>'Dansk','nb'=>'Norsk bokmål','fi'=>'Suomi','is'=>'Íslenska','cs'=>'Čeština','sk'=>'Slovenčina','hu'=>'Magyar','ro'=>'Română','bg'=>'Български','el'=>'Ελληνικά','uk'=>'Українська','tr'=>'Türkçe'];
    public string $locale='en';
    private array $messages=[];
    public function __construct(private string $directory){}
    public function use(string $locale): void
    {
        $this->locale=isset(self::LANGUAGES[$locale])?$locale:'en';$this->messages=[];$this->addDirectory($this->directory);
    }
    public function addDirectory(string $directory): void
    {
        $file=$directory.'/'.$this->locale.'/LC_MESSAGES/directweb.mo';if(!is_file($file)){return;}$data=file_get_contents($file);if(strlen($data)<28){return;}$h=unpack('Vmagic/Vversion/Vcount/Voriginal/Vtranslated',substr($data,0,20));if($h['magic']!==0x950412de){return;}
        for($i=0;$i<$h['count'];$i++){$o=unpack('Vlength/Voffset',substr($data,$h['original']+$i*8,8));$t=unpack('Vlength/Voffset',substr($data,$h['translated']+$i*8,8));$key=substr($data,$o['offset'],$o['length']);$value=substr($data,$t['offset'],$t['length']);if($key!==''&&$value!==''){$this->messages[$key]=$value;}}
    }
    public function get(string $text,mixed ...$values): string
    {
        $translated=$text===''?'':($this->messages[$text]??$text);return $values?vsprintf($translated,$values):$translated;
    }
    public function options(): array
    {
        $out=[];foreach(self::LANGUAGES as $code=>$name){$out[]=['value'=>$code,'label'=>$name];}return $out;
    }
}
