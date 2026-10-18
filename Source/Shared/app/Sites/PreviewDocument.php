<?php
declare(strict_types=1);
namespace DirectWeb\Sites;
use DirectWeb\Core\Problem;
use DOMDocument;
final class PreviewDocument
{
    public function __construct(private string $mount){}
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

    private function rewriteUrl(string $url,string $documentPath): string
    {
        $url=trim($url);if($url===''||str_starts_with($url,'#')||str_starts_with($url,'//')||preg_match('/^[a-z][a-z0-9+.-]*:/i',$url)){return $url;}
        $parts=parse_url($url);if($parts===false){return $url;}$path=rawurldecode($parts['path']??'');$directory=dirname($documentPath);
        $resolved=$this->normalizeReference($path===''?$documentPath:(str_starts_with($path,'/')?$path:($directory==='.'?'':$directory.'/').$path));
        return $this->mount.implode('/',array_map('rawurlencode',explode('/',$resolved??'__invalid_path__'))).(str_ends_with($path,'/')&&$resolved!==''?'/':'').(isset($parts['query'])?'?'.$parts['query']:'').(isset($parts['fragment'])?'#'.$parts['fragment']:'');
    }
    public function css(string $contents, string $documentPath): string
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

    public function html(string $contents, string $documentPath): string
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
                    $basePath = rawurldecode((string) parse_url($base, PHP_URL_PATH));
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
                        $element->setAttribute('style', $this->css($element->getAttribute('style'), $documentPath));
                    }
                    if (strtolower($element->tagName) === 'meta' && strtolower($element->getAttribute('http-equiv')) === 'refresh') {
                        $element->setAttribute('content', preg_replace_callback('~(url\s*=\s*)(.+)$~i', function (array $match) use ($documentPath): string {
                            return $match[1] . $this->rewriteUrl(trim($match[2], " \t\n\r\0\x0B\"'"), $documentPath);
                        }, $element->getAttribute('content')) ?? $element->getAttribute('content'));
                    }
                }
                foreach ($document->getElementsByTagName('style') as $style) {
                    $rewritten = $this->css($style->textContent, $documentPath);
                    while ($style->firstChild !== null) {
                        $style->removeChild($style->firstChild);
                    }
                    $style->appendChild($document->createTextNode($rewritten));
                }
            }
            if(!$externalBase){$head=$document->getElementsByTagName('head')->item(0);if($head){$base=$document->createElement('base');$directory=dirname($documentPath);$base->setAttribute('href',$this->mount.($directory==='.'?'':$directory.'/'));$head->insertBefore($base,$head->firstChild);}}
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
