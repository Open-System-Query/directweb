<?php
declare(strict_types=1);

namespace Lumui\Http;

use Lumui\Contracts\ActionHandler;
use Lumui\Contracts\ConfirmationService;
use Lumui\Contracts\IdempotencyStore;
use Lumui\Protocol\JsonSchemaValidator;
use Lumui\Protocol\MediaTypes;
use Lumui\Protocol\SpecificationResources;
use RuntimeException;
use UnexpectedValueException;

final class ActionDispatcher
{
    
    private $publisher;
    
    private $handler;
    
    private $maxBytes;
    
    private $idempotencyStore;
    
    private $confirmationService;
    
    private $invokeSchemaValidator;
    
    private $resultSchemaValidator;
    
    private $messageVersion;
    
    private $invokeMessageType;
    
    private $resultMessageType;

    public function __construct(
        WebPublisher $publisher,
        ?ActionHandler $handler = null,
        int $maxBytes = 65536,
        ?IdempotencyStore $idempotencyStore = null,
        ?ConfirmationService $confirmationService = null
    ) {
        $this->publisher = $publisher;
        $this->handler = $handler ?? new ValidationOnlyActionHandler();
        $this->maxBytes = $maxBytes;
        $this->idempotencyStore = $idempotencyStore;
        $this->confirmationService = $confirmationService;

        $schemaPath = SpecificationResources::file(
            'schemas/action-message.schema.json'
        );
        $schema = json_decode((string) file_get_contents($schemaPath), true);
        $definitions = is_array($schema) && is_array($schema['$defs'] ?? null)
            ? $schema['$defs']
            : [];
        $invoke = $definitions['invoke'] ?? null;
        $result = $definitions['result'] ?? null;
        if (
            !is_array($invoke)
            || !is_array($result)
            || !is_array($invoke['properties'] ?? null)
            || !is_array($result['properties'] ?? null)
        ) {
            throw new RuntimeException('The LUMUI action message schema is unavailable.');
        }
        $invoke['$defs'] = $definitions;
        $result['$defs'] = $definitions;
        $this->invokeSchemaValidator = new JsonSchemaValidator($invoke);
        $this->resultSchemaValidator = new JsonSchemaValidator($result);
        $this->messageVersion = (string) ($invoke['properties']['lumui_message']['const'] ?? '');
        $this->invokeMessageType = (string) ($invoke['properties']['message_type']['const'] ?? '');
        $this->resultMessageType = (string) ($result['properties']['message_type']['const'] ?? '');
        if (
            $this->messageVersion === ''
            || $this->invokeMessageType === ''
            || $this->resultMessageType === ''
        ) {
            throw new RuntimeException('The LUMUI action message constants are unavailable.');
        }
    }

    public function handleCurrentRequest(): void
    {
        $request = Request::fromGlobals();
        $this->handle($request)->send($request->method() === 'HEAD');
    }

    public function handle(Request $request): Response
    {
        $baseHeaders = [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Allow' => 'POST, OPTIONS',
            'Vary' => 'Origin',
            'X-LUMUI-Message-Version' => $this->messageVersion,
        ];

        if ($request->method() === 'OPTIONS') {
            return new Response(204, $baseHeaders);
        }
        if ($request->method() !== 'POST') {
            return $this->problem(405, 4005, 'Use POST for LUMUI action requests.', null, [], $baseHeaders);
        }

        $contentLength = (int) $request->header('Content-Length', '0');
        if ($contentLength > $this->maxBytes || strlen($request->body()) > $this->maxBytes) {
            return $this->problem(413, 3008, 'The action message exceeds the configured limit.', null, [], $baseHeaders);
        }

        $contentType = strtolower($request->header('Content-Type'));
        if (
            strpos($contentType, MediaTypes::LUMUI_JSON) !== 0
            && strpos($contentType, 'application/json') !== 0
        ) {
            return $this->problem(415, 3002, 'Use application/lumui+json or application/json.', null, [], $baseHeaders);
        }

        if (!$this->sameOrigin($request)) {
            return $this->problem(403, 5003, 'Cross-origin action requests are not allowed.', null, [], $baseHeaders);
        }

        if ($request->body() === '') {
            return $this->problem(400, 3001, 'The action message is empty.', null, [], $baseHeaders);
        }

        $message = json_decode($request->body(), true, 32);
        if (!is_array($message) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->problem(400, 3002, 'The action message is not valid JSON.', null, [], $baseHeaders);
        }

        $correlationId = is_string($message['message_id'] ?? null) ? $message['message_id'] : null;
        if (
            (
                array_key_exists('lumui_message', $message)
                && $message['lumui_message'] !== $this->messageVersion
            )
            || (
                array_key_exists('message_type', $message)
                && $message['message_type'] !== $this->invokeMessageType
            )
        ) {
            return $this->problem(422, 3004, 'The message version or type is unsupported.', $correlationId, [], $baseHeaders);
        }
        $schemaErrors = $this->invokeSchemaValidator->errors($message, 'message');
        if ($schemaErrors !== []) {
            return $this->problem(
                422,
                3005,
                'The action message does not match the protocol schema.',
                $correlationId,
                $this->protocolErrors($schemaErrors),
                $baseHeaders
            );
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey !== '' && !hash_equals((string) $message['message_id'], $idempotencyKey)) {
            return $this->problem(422, 3014, 'The idempotency key must match message_id.', $correlationId, [], $baseHeaders);
        }
        $requestFingerprint = hash('sha256', $request->body());
        if ($idempotencyKey !== '' && $this->idempotencyStore !== null) {
            try {
                $stored = $this->idempotencyStore->retrieve($idempotencyKey, $requestFingerprint);
            } catch (UnexpectedValueException $exception) {
                return $this->problem(
                    409,
                    3014,
                    'The idempotency key was already used for a different request.',
                    $correlationId,
                    [],
                    $baseHeaders
                );
            } catch (\Throwable $exception) {
                error_log('[LUMUI idempotency] ' . $exception->getMessage());
                return $this->problem(503, 4006, 'The action replay store is unavailable.', $correlationId, [], $baseHeaders);
            }
            if ($stored !== null) {
                return $stored;
            }
        }

        if (!is_int($message['revision']) || $message['revision'] !== $this->publisher->revision()) {
            return $this->problem(409, 4009, 'The action references a stale surface revision.', $correlationId, [], $baseHeaders);
        }

        try {
            $surface = $this->publisher->surfaceById((string) $message['surface_id']);
        } catch (RuntimeException $exception) {
            return $this->problem(409, 4009, 'The referenced surface is unavailable.', $correlationId, [], $baseHeaders);
        }
        $ifMatch = $request->header('If-Match');
        if (
            $ifMatch !== ''
            && !$this->strongEtagMatches($ifMatch, $this->publisher->surfaceEtag($surface))
        ) {
            return $this->problem(
                412,
                4009,
                'The action precondition does not match the current surface.',
                $correlationId,
                [],
                $baseHeaders
            );
        }

        $actionId = (string) $message['action_id'];
        $definition = $surface['actions'][$actionId] ?? null;
        if (!is_array($definition)) {
            return $this->problem(422, 3012, 'The action is not declared by the referenced surface.', $correlationId, [], $baseHeaders);
        }
        if (!$this->publisher->componentDeclaresAction(
            $surface['pages'] ?? [],
            (string) $message['component_id'],
            $actionId
        )) {
            return $this->problem(422, 3012, 'The component does not declare the requested action.', $correlationId, [], $baseHeaders);
        }
        $idempotent = ($definition['idempotent'] ?? false) === true;
        if ($idempotent && $idempotencyKey === '') {
            return $this->problem(422, 3014, 'This action requires an Idempotency-Key equal to message_id.', $correlationId, [], $baseHeaders);
        }
        if ($idempotent && $this->idempotencyStore === null) {
            return $this->problem(503, 4006, 'The action replay store is not configured.', $correlationId, [], $baseHeaders);
        }

        $input = $message['input'] ?? [];
        $inputErrors = $this->validateInput($input, $definition['input_schema'] ?? null);
        if ($inputErrors !== []) {
            return $this->problem(422, 3005, 'The action input is invalid.', $correlationId, $inputErrors, $baseHeaders);
        }

        $confirmation = (string) ($definition['confirmation'] ?? 'none');
        if (in_array($confirmation, ['explicit', 'dangerous'], true) && ($message['confirmed'] ?? false) !== true) {
            if ($this->confirmationService === null) {
                return $this->problem(503, 4006, 'Action confirmation is not configured.', $correlationId, [], $baseHeaders);
            }
            $challenge = $this->confirmationService->issue($message);
            return $this->result([
                'lumui_message' => $this->messageVersion,
                'message_type' => $this->resultMessageType,
                'correlation_id' => $correlationId,
                'status' => 'requires_confirmation',
                'status_code' => 4102,
                'confirmation' => [
                    'kind' => $confirmation,
                    'action_id' => $actionId,
                    'token' => $challenge['token'],
                    'expires_at' => $challenge['expires_at'],
                ],
            ], $baseHeaders);
        }
        if (in_array($confirmation, ['explicit', 'dangerous'], true)) {
            $token = is_string($message['confirmation_token'] ?? null)
                ? $message['confirmation_token']
                : '';
            if (
                $this->confirmationService === null
                || $token === ''
                || !$this->confirmationService->verify($message, $token)
            ) {
                return $this->problem(422, 3015, 'The action confirmation is missing, invalid or expired.', $correlationId, [], $baseHeaders);
            }
        }

        try {
            $handled = $this->handler->invoke($message, $surface, $definition);
        } catch (\Throwable $exception) {
            error_log('[LUMUI action] ' . $exception->getMessage());
            return $this->problem(500, 4006, 'The action could not be completed.', $correlationId, [], $baseHeaders);
        }

        $defaultSurfaceHref = $this->relatedHref($surface, 'self');
        $payload = array_merge([
            'lumui_message' => $this->messageVersion,
            'message_type' => $this->resultMessageType,
            'correlation_id' => $correlationId,
            'status' => 'completed',
            'status_code' => 1000,
            'surface' => [
                'href' => $defaultSurfaceHref,
                'revision' => $this->publisher->revision(),
            ],
        ], $handled);
        $payload['lumui_message'] = $this->messageVersion;
        $payload['message_type'] = $this->resultMessageType;
        $payload['correlation_id'] = $correlationId;

        $response = $this->result($payload, $baseHeaders);
        if ($idempotent && $this->idempotencyStore !== null) {
            try {
                $this->idempotencyStore->store($idempotencyKey, $requestFingerprint, $response, 86400);
            } catch (\Throwable $exception) {
                error_log('[LUMUI idempotency] ' . $exception->getMessage());
                return $this->problem(503, 4006, 'The action result could not be stored safely.', $correlationId, [], $baseHeaders);
            }
        }
        return $response;
    }

    
    private function validateInput($input, $schema): array
    {
        if (!is_array($schema)) {
            return [];
        }
        $validator = new JsonSchemaValidator($schema);
        return $this->protocolErrors($validator->errors($input, 'input'));
    }

    private function sameOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');
        $host = $request->header(
            'X-LUMUI-Request-Host',
            $request->header('Host')
        );
        if ($origin === '' || $host === '') {
            return true;
        }

        $originScheme = parse_url($origin, PHP_URL_SCHEME);
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $requestScheme = $this->requestScheme($request);
        $requestParts = parse_url($requestScheme . '://' . $host);
        if (
            !is_string($originScheme)
            || !is_string($originHost)
            || !is_array($requestParts)
            || !is_string($requestParts['host'] ?? null)
        ) {
            return false;
        }

        $requestPort = $requestParts['port'] ?? ($requestScheme === 'https' ? 443 : 80);
        $resolvedOriginPort = is_int($originPort) ? $originPort : (strtolower($originScheme) === 'https' ? 443 : 80);

        return strcasecmp($originScheme, $requestScheme) === 0
            && strcasecmp($originHost, $requestParts['host']) === 0
            && $resolvedOriginPort === $requestPort;
    }

    private function requestScheme(Request $request): string
    {
        return strtolower($request->header('X-LUMUI-Request-Scheme')) === 'http' ? 'http' : 'https';
    }

    private function strongEtagMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (
                $candidate !== ''
                && stripos($candidate, 'W/') !== 0
                && hash_equals($etag, $candidate)
            ) {
                return true;
            }
        }
        return false;
    }

    
    private function problem(
        int $status,
        int $code,
        string $title,
        ?string $correlationId,
        array $errors,
        array $headers
    ): Response {
        return $this->json($status, [
            'type' => '/problems/action-invalid',
            'title' => $title,
            'status' => $status,
            'lumui_code' => $code,
            'correlation_id' => $correlationId,
            'errors' => $errors,
        ], $headers, MediaTypes::PROBLEM_JSON);
    }

    
    private function result(array $payload, array $headers): Response
    {
        $schemaErrors = $this->resultSchemaValidator->errors($payload, 'result');
        if ($schemaErrors !== []) {
            error_log(
                '[LUMUI action] Invalid action result: '
                . $schemaErrors[0]['path']
                . ': '
                . $schemaErrors[0]['message']
            );
            $correlationId = is_string($payload['correlation_id'] ?? null)
                ? $payload['correlation_id']
                : null;
            return $this->problem(
                500,
                4006,
                'The action handler returned an invalid result.',
                $correlationId,
                $this->protocolErrors($schemaErrors),
                $headers
            );
        }
        return $this->json(200, $payload, $headers, MediaTypes::LUMUI_JSON);
    }

    
    private function protocolErrors(array $schemaErrors): array
    {
        $errors = [];
        foreach ($schemaErrors as $schemaError) {
            $errors[] = [
                'path' => $schemaError['path'],
                'code' => 3102,
                'severity' => 'error',
                'message' => $schemaError['message'],
            ];
        }
        return $errors;
    }

    
    private function json(int $status, array $payload, array $headers, string $mediaType): Response
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $encoded = is_string($body) ? $body : '';
        return new Response($status, array_merge($headers, [
            'Content-Type' => $mediaType . '; charset=utf-8',
            'Content-Length' => (string) strlen($encoded),
        ]), $encoded);
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
