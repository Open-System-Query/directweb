<?php
declare(strict_types=1);
namespace DirectWebWordPress;
use DirectWeb\Core\{App,Files,Problem};
use DOMDocument;
final class Website
{
    private array $manifest=[];
    private string $release='';
    public function __construct(private App $app){}
    public function serve(array $site,string $uri): void
    {
        $a=$this->app;
        if(!$site['enabled']||!$site['active_release']){throw new Problem('Website unavailable.',503);}
        $a->plugins->boot();foreach($a->plugins->siteRoutes as $handler){if($handler($site,$uri)){return;}}
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){header('Allow: GET, HEAD');throw new Problem('Method not allowed.',405);}
        $path=rawurldecode(ltrim($uri,'/'));if($path!==''){Files::path(rtrim($path,'/'));}
        $this->release=$site['active_release'];$this->manifest=$a->sites->readManifest($site['id'],$this->release);
        $file=$this->findFile(rtrim($path,'/'));$status=200;
        if($file===''&&$site['spa']&&pathinfo($path,PATHINFO_EXTENSION)===''){$file=$this->manifest['start'];}
        if($file===''){if(isset($this->manifest['files']['404.html'])){$file='404.html';$status=404;}else{throw new Problem('Page not found.',404);}}
        $entry=$this->manifest['files'][$file];$source=$a->sites->dir($site['id'],$this->release).'/files/'.$file;$body=null;$extension=strtolower(pathinfo($file,PATHINFO_EXTENSION));
        if(in_array($extension,['html','htm','css'],true)){$contents=file_get_contents($source);$body=$extension==='css'?$this->rewriteCss($contents,$file):$this->rewriteHtml($contents,$file);}
        while(ob_get_level()>0){ob_end_clean();}
        status_header($status);nocache_headers();header('Content-Type: '.$entry['type']);header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');header("Content-Security-Policy: worker-src 'none'; object-src 'none'");header('Cache-Control: no-cache');header('X-Robots-Tag: '.((string)get_option('blog_public','1')==='0'?'noindex, nofollow, noarchive':'all'));
        $etag='"'.($body!==null?hash('sha256',$body):$entry['hash']).'"';header('ETag: '.$etag);
        if($status===200&&($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){status_header(304);return;}
        header('Content-Length: '.($body!==null?strlen($body):$entry['size']));
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){if($body!==null){echo $body;}else{readfile($source);}}
    }
    private function findFile(string $path): string
    {
        if($path===''){return $this->manifest['start'];}
        foreach([$path,$path.'/index.html',$path.'/index.htm',$path.'.html',$path.'.htm'] as $candidate){if(isset($this->manifest['files'][$candidate])){return $candidate;}}
        return '';
    }
    private function normalizeReference(string $path): ?string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    private function pagePath(string $path): string
    {
        if ($path === $this->manifest['start']) {
            return '';
        }
        $directory = dirname($path);
        if (in_array(basename($path), ['index.html', 'index.htm'], true) && $directory !== '.' && $this->findFile($directory) === $path) {
            return $directory . '/';
        }
        $withoutExtension = preg_replace('/\.html?$/i', '', $path);
        if (is_string($withoutExtension) && $withoutExtension !== $path && $this->findFile($withoutExtension) === $path) {
            return $withoutExtension . '/';
        }
        return $path;
    }

    private function routeUrl(string $path,array $parameters=[],string $fragment=''): string
    {
        unset($parameters['directweb_file'],$parameters['directweb_version']);
        $file=$this->findFile(rtrim($path,'/'));$extension=strtolower(pathinfo($file!==''?$file:$path,PATHINFO_EXTENSION));
        if($file!==''&&in_array($extension,['html','htm'],true)){
            $path=$this->pagePath($file);$url=home_url('/'.implode('/',array_map('rawurlencode',explode('/',$path))));
        }elseif($extension!==''){
            $parameters['directweb_file']=$file!==''?$file:$path;$parameters['directweb_version']=$this->manifest['files'][$file]['hash']??$this->release;
            $url=home_url('/index.php');
        }else{$url=home_url('/'.implode('/',array_map('rawurlencode',explode('/',$path))));}
        if($parameters!==[]){$url.='?'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986);}
        return $fragment!==''?$url.'#'.$fragment:$url;
    }
    private function rewriteUrl(string $url, string $documentPath): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return $url;
        }
        $parts = wp_parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $path = rawurldecode($parts['path'] ?? '');
        $root=rtrim(rawurldecode((string)wp_parse_url(home_url('/'),PHP_URL_PATH)),'/');
        if($root!==''&&($path===$root||str_starts_with($path,$root.'/'))){$path=substr($path,strlen($root))?:'/';}
        if ($path === '') {
            $resolved = $documentPath;
        } elseif (str_starts_with($path, '/')) {
            $resolved = $this->normalizeReference($path);
        } else {
            $directory = dirname($documentPath);
            $resolved = $this->normalizeReference(($directory === '.' ? '' : $directory . '/') . $path);
        }
        if ($resolved === null) {
            return $this->routeUrl('__invalid_path__');
        }
        $parameters = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $parameters);
        }
        return $this->routeUrl($resolved, $parameters, $parts['fragment'] ?? '');
    }

    private function rewriteCss(string $contents, string $documentPath): string
    {
        $contents = preg_replace_callback('~url\(\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s)]+))\s*\)~i', function (array $match) use ($documentPath): string {
            $url = $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
            $rewritten = $this->rewriteUrl($url, $documentPath);
            return 'url("' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '', ''], $rewritten) . '")';
        }, $contents) ?? $contents;
        return preg_replace_callback('~(@import\s+)(["\x27])([^"\x27]+)\2~i', function (array $match) use ($documentPath): string {
            return $match[1] . $match[2] . $this->rewriteUrl($match[3], $documentPath) . $match[2];
        }, $contents) ?? $contents;
    }

    private function rewriteSourceSet(string $value, string $documentPath): string
    {
        if (stripos($value, 'data:') !== false) {
            return $value;
        }
        $items = [];
        foreach (explode(',', $value) as $candidate) {
            $parts = preg_split('/\s+/', trim($candidate), 2);
            $items[] = $this->rewriteUrl($parts[0] ?? '', $documentPath) . (isset($parts[1]) ? ' ' . $parts[1] : '');
        }
        return implode(', ', $items);
    }

    private function rewriteHtml(string $contents, string $documentPath): string
    {
        if (!class_exists('DOMDocument')) {
            throw new Problem('PHP extension required: dom.',503);
        }
        $previousErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $contents, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new Problem('Invalid HTML.',503);
            }
            foreach (iterator_to_array($document->childNodes) as $child) {
                if ($child->nodeType === XML_PI_NODE) {
                    $document->removeChild($child);
                }
            }
            $baseElements = iterator_to_array($document->getElementsByTagName('base'));
            $externalBase = false;
            foreach ($baseElements as $baseElement) {
                $base = $baseElement->getAttribute('href');
                if ($base !== '' && !$externalBase) {
                    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $base)) {
                        $externalBase = true;
                        continue;
                    }
                    $basePath = rawurldecode((string) wp_parse_url($base, PHP_URL_PATH));
                    $directory = dirname($documentPath);
                    $resolvedBase = $this->normalizeReference(str_starts_with($basePath, '/') ? $basePath : ($directory === '.' ? '' : $directory . '/') . $basePath);
                    if ($resolvedBase !== null) {
                        $documentPath = $resolvedBase . (str_ends_with($basePath, '/') ? '/__base__.html' : '');
                    }
                }
                $baseElement->parentNode->removeChild($baseElement);
            }
            if (!$externalBase) {
                foreach ($document->getElementsByTagName('*') as $element) {
                    foreach (['href', 'src', 'poster', 'action', 'formaction', 'data', 'background', 'xlink:href'] as $attribute) {
                        if ($element->hasAttribute($attribute)) {
                            $original = $element->getAttribute($attribute);
                            $rewritten = $this->rewriteUrl($original, $documentPath);
                            $element->setAttribute($attribute, $rewritten);
                            if ($rewritten !== $original && $element->hasAttribute('integrity')) {
                                $element->removeAttribute('integrity');
                            }
                        }
                    }
                    foreach (['srcset', 'imagesrcset'] as $attribute) {
                        if ($element->hasAttribute($attribute)) {
                            $element->setAttribute($attribute, $this->rewriteSourceSet($element->getAttribute($attribute), $documentPath));
                        }
                    }
                    if ($element->hasAttribute('style')) {
                        $element->setAttribute('style', $this->rewriteCss($element->getAttribute('style'), $documentPath));
                    }
                    if (strtolower($element->tagName) === 'meta' && strtolower($element->getAttribute('http-equiv')) === 'refresh') {
                        $element->setAttribute('content', preg_replace_callback('~(url\s*=\s*)(.+)$~i', function (array $match) use ($documentPath): string {
                            return $match[1] . $this->rewriteUrl(trim($match[2], " \t\n\r\0\x0B\"'"), $documentPath);
                        }, $element->getAttribute('content')) ?? $element->getAttribute('content'));
                    }
                }
                foreach ($document->getElementsByTagName('style') as $style) {
                    $rewritten = $this->rewriteCss($style->textContent, $documentPath);
                    while ($style->firstChild !== null) {
                        $style->removeChild($style->firstChild);
                    }
                    $style->appendChild($document->createTextNode($rewritten));
                }
            }
            $result = $document->saveHTML();
            if ($result === false) {
                throw new Problem('Invalid HTML.',503);
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

}
