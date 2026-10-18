<?php
declare(strict_types=1);
namespace DirectWeb\Ui;
use DirectWeb\Core\App;
class Renderer implements \Lumui\Contracts\SurfaceRenderer
{
    private array $surface=[];
    public function __construct(protected App $app){}
    private function e(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function t(string $s): string {return $this->e($this->app->translator->get($s));}
    private function url(string $s): string {$s=$this->app->url($s);return preg_match('~^(?:/(?!/)|https?://)~i',$s)?$this->e($s):'#';}
    private function icon(string $name): string
    {
        static $icons=[];
        if(!preg_match('/^[a-z0-9-]+$/D',$name)||!is_file($this->app->root.'/public/assets/icons/'.$name.'.svg')){$name='globe';}
        if(!isset($icons[$name])){
            $source=file_get_contents($this->app->root.'/public/assets/icons/'.$name.'.svg');
            $source=preg_replace('/<!--.*?-->/s','',$source);
            $icons[$name]=preg_replace('/<svg\b/','<svg class="icon" aria-hidden="true" focusable="false" width="18" height="18"',$source,1);
        }
        return $icons[$name];
    }
    protected function assetBase(): string {return '/assets';}
    public function render(array $surface): string
    {
        $this->surface=$surface;$content='';$utility='';$notices='';
        foreach($surface['pages'][0]['regions'] as $region){
            $meta=$region['metadata']??[];
            if(!empty($meta['navigation'])||!empty($meta['sidebar'])){continue;}
            elseif(!empty($meta['application_bar'])){foreach($region['items'] as $n){$utility.=$this->node($n);}}
            elseif(!empty($meta['notification'])){foreach($region['items'] as $n){$notices.=$this->node($n);}}
            else{$content.=$this->node($region);}
        }
        $page=($surface['surface_id']==='admin.home'||str_starts_with($surface['surface_id'],'admin.home.'))?'dashboard':'';$signed=!empty($surface['metadata']['user']);$guest=$signed?'':'public-shell';if(!empty($surface['metadata']['portal'])){$guest.=' portal-shell';}$home=$this->url($surface['metadata']['home']??'/admin');$direction=($surface['direction']??'ltr')==='rtl'?'rtl':'ltr';
        return '<!doctype html><html lang="'.$this->e($surface['locale']).'" dir="'.$direction.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$this->e($surface['title']).' · DirectWeb</title><link rel="stylesheet" href="'.$this->url($this->assetBase()).'/directweb.css?v='.$this->e($this->app->config['version']).'"><link rel="alternate" type="application/lumui+json" href="'.$this->url($_SERVER['REQUEST_URI']??'/admin').'"><script src="'.$this->url('/assets/qrcodegen.js').'" defer></script><script src="'.$this->url('/assets/secrets.js').'?v='.$this->e($this->app->config['version']).'" defer></script><script src="'.$this->url($this->assetBase()).'/directweb.js?v='.$this->e($this->app->config['version']).'" defer></script></head><body class="'.$page.'"><a class="skip" href="#main">'.$this->t('Skip to content').'</a><div class="application"><header class="topbar"><a class="brand" href="'.$home.'"><span class="brand-mark" aria-hidden="true">'.$this->icon('globe').'</span><span>directweb</span></a><div class="account-bar">'.$utility.'</div></header>'.'<div class="shell '.$guest.'"><main id="main"><div class="page-heading">'.($signed&&$page!=='dashboard'?'<a class="back-link" href="'.$this->url('/admin').'">'.$this->icon('house').$this->t('Dashboard').'</a>':'').'<h1>'.$this->e($surface['title']).'</h1></div>'.$notices.'<div class="regions">'.$content.'</div><footer><span>DirectWeb</span><span>'.$this->e($this->app->config['version']).'</span></footer></main>'.'</div></div><dialog id="confirm"><form method="dialog"><h2>'.$this->t('Confirm').'</h2><p>'.$this->t('Perform this action?').'</p><div class="dialog-actions"><button value="cancel" class="secondary">'.$this->t('Cancel').'</button><button value="confirm">'.$this->t('Continue').'</button></div></form></dialog></body></html>';
    }
    protected function node(array $n): string
    {
        if(($n['visible']??true)===false){return '';}$kind=$n['kind'];$id=$this->e($n['id']);$label=$this->e($n['label']??'');$meta=$n['metadata']??[];
        if($kind==='section'){
            $body=implode('',array_map(fn($v)=>$this->node($v),$n['items']));$classes='panel'.($label===''?' untitled':'');if(array_filter($n['items'],fn($item)=>$item['kind']==='table')){$classes.=' wide';}foreach(['wide','summary','directory','enrollment','workspace','plugin'] as $class){if(!empty($meta[$class])){$classes.=' '.$class;}}
            $anchor=isset($meta['anchor'])?' id="'.$this->e($meta['anchor']).'"':'';$title=$this->icon($meta['icon']??'folder').'<h2>'.$label.'</h2>'.(isset($meta['count'])?'<span class="section-count">'.(int)$meta['count'].'</span>':'');
            if(!empty($n['collapsible'])||!empty($meta['collapsible'])){return '<details class="'.$classes.'"'.$anchor.(!empty($meta['open'])?' open':'').'><summary class="panel-title">'.$title.'</summary><div class="panel-body">'.$body.'</div></details>';}
            return '<section class="'.$classes.'"'.$anchor.'>'.($label!==''?'<div class="panel-title">'.$title.'</div>':'').'<div class="panel-body">'.$body.'</div></section>';
        }
        if($kind==='text'){return '<p class="text">'.$this->e($n['text']).'</p>';}
        if($kind==='codeBlock'&&!empty($meta['secret'])){
            return '<div class="field secret-field" data-secret-endpoint="'.$this->url($meta['endpoint']).'" data-secret-token="'.$this->e($meta['token']).'" data-secret-csrf="'.$this->e($meta['csrf']).'" data-show-label="'.$this->t('Show').'" data-hide-label="'.$this->t('Hide').'" data-copied-label="'.$this->t('Copied').'" data-error-label="'.$this->t('Action failed.').'"><span id="'.$id.'-label" class="secret-label">'.$this->t($meta['secret_label']??'Access token').'</span><code id="'.$id.'" class="secret-value" data-secret-value aria-labelledby="'.$id.'-label" tabindex="0">••••••••••••••••</code><div class="dialog-actions"><button type="button" data-secret-show aria-controls="'.$id.'" aria-pressed="false">'.$this->t('Show').'</button><button type="button" data-secret-copy>'.$this->t('Copy').'</button></div><span role="status" aria-live="polite" data-secret-status></span></div>';
        }
        if($kind==='codeBlock'&&!empty($meta['copy'])){return '<div class="copy-block"><pre id="'.$id.'" tabindex="0">'.$this->e($n['text']).'</pre><button type="button" data-copy="'.$id.'" data-copied="'.$this->t('Copied').'">'.$this->t('Copy link').'</button></div>';}
        if($kind==='codeBlock'){return (isset($meta['qr'])?'<canvas class="qr-code" data-qr="'.$this->e($meta['qr']).'" role="img" aria-label="'.$this->t('Authenticator QR code').'"></canvas>':'').'<pre tabindex="0">'.$this->e($n['text']).'</pre>';}
        if($kind==='link'){$path=parse_url($_SERVER['REQUEST_URI']??'/admin',PHP_URL_PATH);$target=parse_url($n['href'],PHP_URL_PATH);$active=!str_contains($n['href'],'#')&&($path===$target||($target==='/admin/sites'&&in_array($path,['/admin/site','/admin/files'],true))||($target==='/admin/users'&&$path==='/admin/user'));return '<a class="nav-link" href="'.$this->url($n['href']).'"'.($active?' aria-current="page"':'').'>'.$this->icon($meta['icon']??'link').'<span>'.$label.'</span></a>';}
        if($kind==='valueDisplay'){return '<div class="metric"><span>'.$label.'</span><strong>'.$this->e($n['value']??$n['text']).'</strong></div>';}
        if($kind==='badge'){return '<span class="badge">'.$label.'</span>';}
        if($kind==='alert'){$error=($n['severity']??'info')==='error';return '<div class="notice'.($error?' error':'').'" role="'.($error?'alert':'status').'"><strong>'.$this->e($n['title']).'</strong><span>'.$this->e($n['message']).'</span></div>';}
        if($kind==='form'){
            $aid=$n['submit_action'];$action=$this->surface['actions'][$aid]['callback'];$compact=!array_filter($n['items'],fn($item)=>in_array($item['kind'],['textField','passwordField','numberField','choice','textArea','filePicker','checkBox'],true));$h='<form class="admin-form'.($compact?' compact-form':'').'" method="post" action="'.$this->url('/admin/actions').'" enctype="multipart/form-data"'.(!empty($meta['danger'])?' data-confirm="true"':'').(!empty($meta['download'])?' data-download="true"':'').(isset($meta['upload'])?' data-upload="'.$this->e(json_encode($this->app->publicDocument($meta['upload']))).'"':'').'>';
            foreach(['_action'=>$action,'_csrf'=>$_SESSION['csrf']??'','_return'=>$meta['return']??'/admin','_request'=>bin2hex(random_bytes(16))]+($meta['context']??[]) as $key=>$value){$h.='<input type="hidden" name="'.$this->e($key).'" value="'.$this->e($value).'">';}
            if($label!==''){$h.='<h3>'.$label.'</h3>';}$h.=implode('',array_map(fn($v)=>$this->node($v),$n['items']));if(isset($meta['upload'])){$h.='<div class="upload-feedback" role="status" aria-live="polite" hidden></div><div class="upload-progress" hidden><progress max="100" value="0" aria-label="'.$this->t('Upload progress').'"></progress><span class="upload-percent">0%</span><button type="button" data-upload-cancel>'.$this->t('Cancel').'</button></div><span hidden data-upload-text="'.$this->e(json_encode(array_map(fn($text)=>$this->app->translator->get($text),['Uploading','Finishing','Upload failed.','Upload cancelled.','File limit exceeded.','Transfer limit exceeded.','Storage limit reached.','Remove']))).'"></span>';}return $h.'</form>';
        }
        if($kind==='button'){return '<button type="submit"'.(($n['confirmation']??'')==='dangerous'?' class="danger"':'').(($n['enabled']??true)?'':' disabled').'>'.$label.'</button>';}
        if($kind==='table'){
            $h='<div class="table-scroll"><table><caption class="sr-only">'.$this->e($n['caption']??'').'</caption><thead><tr>';foreach($n['columns'] as $col){$h.='<th scope="col">'.$this->e($col['label']).'</th>';}$h.='</tr></thead><tbody>';
            foreach($n['rows'] as $row){$h.='<tr>';foreach($n['columns'] as $col){$value=$row[$col['id']]??'';$h.='<td>';if(is_array($value)&&isset($value['href'])){$h.='<a href="'.$this->url($value['href']).'">'.$this->e($value['label']).'</a>';}else{$h.=$this->e(is_scalar($value)?(string)$value:json_encode($value));}$h.='</td>';}$h.='</tr>';}
            if(!$n['rows']){$h.='<tr><td class="empty" colspan="'.count($n['columns']).'">'.$this->t('No items yet.').'</td></tr>';}return $h.'</tbody></table></div>';
        }
        $name=$this->e(($meta['name']??$n['id']).($kind==='filePicker'&&($n['selection_mode']??'single')==='multiple'?'[]':''));$required=!empty($n['required'])?' required':'';$value=$this->e($n['value']??'');$attrs=' id="'.$id.'" name="'.$name.'"'.$required;
        if($kind==='checkBox'){return '<label class="check" for="'.$id.'"><input type="checkbox"'.$attrs.' value="1"'.(!empty($n['value'])?' checked':'').'><span>'.$label.'</span></label>';}
        if($kind==='choice'){$input='<select'.$attrs.'>';foreach($n['options'] as $o){$input.='<option value="'.$this->e($o['value']).'"'.((string)$o['value']===(string)($n['value']??'')?' selected':'').'>'.$this->e($o['label']).'</option>';}$input.='</select>';}
        elseif($kind==='textArea'){$input='<textarea'.$attrs.' rows="18" spellcheck="false">'.$value.'</textarea>';}
        elseif($kind==='filePicker'){$input='<input type="file"'.$attrs.(($n['selection_mode']??'single')==='multiple'?' multiple':'').' accept="'.$this->e(is_array($n['accept']??'')?implode(',',$n['accept']):($n['accept']??'')).'">';}
        elseif(in_array($kind,['textField','passwordField','numberField'],true)){$type=$kind==='passwordField'?'password':($kind==='numberField'?'number':(($n['content_type']??'')==='email'?'email':'text'));$input='<input type="'.$type.'"'.$attrs.($kind!=='passwordField'?' value="'.$value.'"':'').' autocomplete="'.$this->e($n['autocomplete']??($type==='password'?'new-password':'off')).'"'.(isset($n['min_length'])?' minlength="'.(int)$n['min_length'].'"':'').(isset($n['max_length'])?' maxlength="'.(int)$n['max_length'].'"':'').(isset($n['min'])?' min="'.$this->e($n['min']).'"':'').(isset($n['max'])?' max="'.$this->e($n['max']).'"':'').'>';}
        else{if(isset($n['fallback'])){return $this->node($n['fallback']);}throw new \RuntimeException('Unsupported component: '.$kind);}
        return '<div class="field"><label for="'.$id.'">'.$label.'</label>'.$input.'</div>';
    }
}
