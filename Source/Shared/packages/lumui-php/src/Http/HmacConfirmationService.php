<?php
declare(strict_types=1);

namespace Lumui\Http;

use Lumui\Contracts\ConfirmationService;
use InvalidArgumentException;


final class HmacConfirmationService implements ConfirmationService
{
    
    private $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('The confirmation secret must contain at least 32 bytes.');
        }
        $this->secret = $secret;
    }

    public function issue(array $message, int $ttlSeconds = 300): array
    {
        $expires = time() + max(30, min($ttlSeconds, 900));
        $claims = $this->claims($message, $expires);
        $payload = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new InvalidArgumentException('The confirmation challenge could not be encoded.');
        }
        $encoded = $this->base64UrlEncode($payload);
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret, true));
        return [
            'token' => $encoded . '.' . $signature,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
        ];
    }

    public function verify(array $message, string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $parts[0], $this->secret, true));
        if (!hash_equals($expectedSignature, $parts[1])) {
            return false;
        }
        $payload = $this->base64UrlDecode($parts[0]);
        $claims = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($claims) || !is_int($claims['expires_at'] ?? null) || $claims['expires_at'] < time()) {
            return false;
        }
        return hash_equals(
            hash('sha256', (string) json_encode($this->claims($message, $claims['expires_at']))),
            hash('sha256', (string) json_encode($claims))
        );
    }

    
    private function claims(array $message, int $expires): array
    {
        return [
            'message_id' => (string) ($message['message_id'] ?? ''),
            'surface_id' => (string) ($message['surface_id'] ?? ''),
            'revision' => (int) ($message['revision'] ?? 0),
            'component_id' => (string) ($message['component_id'] ?? ''),
            'action_id' => (string) ($message['action_id'] ?? ''),
            'input_hash' => hash(
                'sha256',
                (string) json_encode($message['input'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ),
            'expires_at' => $expires,
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    
    private function base64UrlDecode(string $value)
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}

