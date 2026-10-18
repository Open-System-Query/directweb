<?php
declare(strict_types=1);

namespace Lumui\Http;

use Lumui\Contracts\SurfaceProvider;
use Lumui\Contracts\SurfaceRenderer;
use Lumui\Protocol\DescriptorValidator;
use Lumui\Protocol\MediaTypes;
use Lumui\Protocol\SurfaceValidator;
use RuntimeException;

final class WebPublisher
{
    
    private $provider;
    
    private $surfaceValidator;
    
    private $descriptorValidator;
    
    private $renderer;

    public function __construct(
        SurfaceProvider $provider,
        SurfaceValidator $surfaceValidator,
        SurfaceRenderer $renderer,
        ?DescriptorValidator $descriptorValidator = null
    ) {
        $this->provider = $provider;
        $this->surfaceValidator = $surfaceValidator;
        $this->renderer = $renderer;
        $this->descriptorValidator = $descriptorValidator ?? new DescriptorValidator();
    }

    public function handleCurrentRequest(): void
    {
        $request = Request::fromGlobals();
        $response = $this->handle($request);
        $response->send($request->method() === 'HEAD');
    }

    public function handle(Request $request): Response
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $this->problem(405, 2001, 'This resource supports GET and HEAD.', [
                'Allow' => 'GET, HEAD',
            ]);
        }

        $routeId = $this->provider->routeIdFromPath($request->path());
        $surface = $this->validatedSurface($routeId);
        $status = $routeId === 'not-found' ? 404 : 200;

        $representation = $this->selectRepresentation($request);
        if ($representation === null) {
            return $this->problem(406, 2003, 'No acceptable LUMUI or HTML representation is available.', [
                'Vary' => 'Accept',
            ]);
        }
        if ($representation === 'semantic') {
            return $this->jsonResponse($surface, MediaTypes::LUMUI_JSON, $status, true, $request);
        }

        $html = $this->renderer->render($surface);
        $path = (string) ($surface['metadata']['web']['path'] ?? $request->path());
        $surfaceHref = (string) ($surface['metadata']['web']['surface_href'] ?? $path);
        $descriptorHref = $this->relatedHref($surface, 'service-desc');
        $links = '<' . $surfaceHref . '>; rel="alternate"; type="' . MediaTypes::LUMUI_JSON . '"';
        if ($descriptorHref !== '') {
            $links .= ', <' . $descriptorHref . '>; rel="service-desc"; type="' . MediaTypes::LUMUI_JSON . '"';
        }

        return $this->textResponse($html, MediaTypes::HTML . '; charset=utf-8', $status, false, $request, [
            'Link' => $links,
            'Content-Location' => $path,
        ]);
    }

    public function sendDescriptor(): void
    {
        $request = Request::fromGlobals();
        $this->descriptorResponse($request)->send($request->method() === 'HEAD');
    }

    public function descriptorResponse(?Request $request = null): Response
    {
        $request = $request ?? new Request('GET', '/lumui/descriptor.json');
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $this->problem(405, 2001, 'This resource supports GET and HEAD.', [
                'Allow' => 'GET, HEAD',
            ]);
        }
        $descriptor = $this->provider->descriptor();
        $this->descriptorValidator->validate($descriptor);
        return $this->jsonResponse($descriptor, MediaTypes::LUMUI_JSON, 200, true, $request, [
            'Content-Location' => $request->path(),
        ]);
    }

    public function sendSurface(string $routeId): void
    {
        $request = Request::fromGlobals();
        $this->surfaceResponse($routeId, $request)->send($request->method() === 'HEAD');
    }

    public function surfaceResponse(string $routeId, ?Request $request = null): Response
    {
        $request = $request ?? new Request('GET', '/');
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $this->problem(405, 2001, 'This resource supports GET and HEAD.', [
                'Allow' => 'GET, HEAD',
            ]);
        }
        $known = array_key_exists($routeId, $this->provider->routes());
        $surface = $this->validatedSurface($known ? $routeId : 'not-found');
        return $this->jsonResponse($surface, MediaTypes::LUMUI_JSON, $known ? 200 : 404, true, $request);
    }

    
    public function surface(string $routeId): array
    {
        return $this->validatedSurface($routeId);
    }

    
    public function surfaceById(string $surfaceId): array
    {
        $routeId = $this->provider->routeIdFromSurfaceId($surfaceId);
        if ($routeId === null) {
            throw new RuntimeException('The referenced surface is unavailable.');
        }
        return $this->validatedSurface($routeId);
    }

    public function routeIdFromSurfaceId(string $surfaceId): ?string
    {
        return $this->provider->routeIdFromSurfaceId($surfaceId);
    }

    public function revision(): int
    {
        return $this->provider->revision();
    }

    
    public function componentDeclaresAction($nodes, string $componentId, string $actionId): bool
    {
        return $this->surfaceValidator->componentDeclaresAction(
            $nodes,
            $componentId,
            $actionId
        );
    }

    
    public function surfaceEtag(array $surface): string
    {
        return '"' . hash('sha256', $this->encodeJson($surface)) . '"';
    }

    
    public function routes(): array
    {
        return $this->provider->routes();
    }

    
    public function descriptor(): array
    {
        $descriptor = $this->provider->descriptor();
        $this->descriptorValidator->validate($descriptor);
        return $descriptor;
    }

    
    private function selectRepresentation(Request $request): ?string
    {
        $format = strtolower($request->query('format'));
        if (in_array($format, ['lumui', 'json', 'semantic'], true)) {
            return 'semantic';
        }
        if ($format === 'html') {
            return 'html';
        }

        $accept = strtolower(trim($request->header('Accept')));
        if ($accept === '') {
            return 'html';
        }

        $ranges = $this->acceptRanges($accept);
        $semanticQuality = $this->mediaQuality(MediaTypes::LUMUI_JSON, $ranges);
        $htmlQuality = max(
            $this->mediaQuality(MediaTypes::HTML, $ranges),
            $this->mediaQuality('application/xhtml+xml', $ranges)
        );

        if ($semanticQuality <= 0.0 && $htmlQuality <= 0.0) {
            return null;
        }
        return $semanticQuality > $htmlQuality ? 'semantic' : 'html';
    }

    
    private function acceptRanges(string $accept): array
    {
        $ranges = [];
        foreach (explode(',', $accept) as $order => $range) {
            $parts = array_map('trim', explode(';', $range));
            $mediaType = strtolower((string) array_shift($parts));
            if (strpos($mediaType, '/') === false) {
                continue;
            }
            $quality = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $parameter, $match) === 1) {
                    $quality = max(0.0, min(1.0, (float) $match[1]));
                }
            }
            $ranges[] = ['type' => $mediaType, 'quality' => $quality, 'order' => (int) $order];
        }
        return $ranges;
    }

    
    private function mediaQuality(string $mediaType, array $ranges): float
    {
        [$targetType, $targetSubtype] = explode('/', strtolower($mediaType), 2);
        $bestSpecificity = -1;
        $bestOrder = PHP_INT_MAX;
        $quality = 0.0;
        foreach ($ranges as $range) {
            [$rangeType, $rangeSubtype] = explode('/', $range['type'], 2);
            if (
                $rangeType !== '*'
                && $rangeType !== $targetType
            ) {
                continue;
            }
            if (
                $rangeSubtype !== '*'
                && $rangeSubtype !== $targetSubtype
            ) {
                continue;
            }
            $specificity = ($rangeType === '*' ? 0 : 1) + ($rangeSubtype === '*' ? 0 : 1);
            if ($specificity > $bestSpecificity || ($specificity === $bestSpecificity && $range['order'] < $bestOrder)) {
                $bestSpecificity = $specificity;
                $bestOrder = $range['order'];
                $quality = $range['quality'];
            }
        }
        return $quality;
    }

    
    private function validatedSurface(string $routeId): array
    {
        $surface = $this->provider->surface($routeId);
        $this->surfaceValidator->validate($surface);
        return $surface;
    }

    
    private function jsonResponse(
        array $payload,
        string $type,
        int $status,
        bool $cacheable,
        Request $request,
        array $headers = []
    ): Response {
        $json = $this->encodeJson($payload);
        return $this->textResponse($json, $type . '; charset=utf-8', $status, $cacheable, $request, $headers);
    }

    
    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new RuntimeException('The LUMUI resource could not be encoded.');
        }
        return $json;
    }

    
    private function textResponse(
        string $body,
        string $contentType,
        int $status,
        bool $cacheable,
        Request $request,
        array $headers = []
    ): Response {
        $etag = '"' . hash('sha256', $body) . '"';
        $common = [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept',
            'ETag' => $etag,
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => $cacheable ? 'public, max-age=300' : 'no-cache, must-revalidate',
            'X-LUMUI-Web-Version' => '1.0',
        ];

        if ($this->etagMatches($request->header('If-None-Match'), $etag)) {
            $notModifiedHeaders = array_merge($common, $headers);
            unset($notModifiedHeaders['Content-Length']);
            return new Response(304, $notModifiedHeaders);
        }
        return new Response($status, array_merge($common, $headers), $body);
    }

    private function etagMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (stripos($candidate, 'W/') === 0) {
                $candidate = trim(substr($candidate, 2));
            }
            if ($candidate !== '' && hash_equals($etag, $candidate)) {
                return true;
            }
        }
        return false;
    }

    
    private function problem(int $status, int $code, string $title, array $headers = []): Response
    {
        $body = json_encode([
            'type' => '/problems/request-invalid',
            'title' => $title,
            'status' => $status,
            'lumui_code' => $code,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $encoded = is_string($body) ? $body : '';
        return new Response($status, array_merge([
            'Content-Type' => MediaTypes::PROBLEM_JSON . '; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Length' => (string) strlen($encoded),
        ], $headers), $encoded);
    }

    
    private function relatedHref(array $surface, string $relation): string
    {
        foreach (($surface['links'] ?? []) as $link) {
            if (is_array($link) && (string) ($link['rel'] ?? '') === $relation) {
                return (string) ($link['href'] ?? '');
            }
        }
        return '';
    }
}

