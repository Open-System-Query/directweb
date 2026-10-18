<?php
declare(strict_types=1);

namespace Lumui\Http;

use Lumui\Contracts\IdempotencyStore;
use RuntimeException;
use UnexpectedValueException;


final class FileIdempotencyStore implements IdempotencyStore
{
    
    private $directory;

    public function __construct(string $directory)
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new RuntimeException('The idempotency store directory is empty.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('The idempotency store directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The idempotency store directory is not writable.');
        }
        $this->directory = $directory;
    }

    public function retrieve(string $key, string $requestFingerprint): ?Response
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The stored action response could not be opened.');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('The stored action response could not be locked.');
            }
            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $entry = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($entry) || !isset($entry['fingerprint'], $entry['expires_at'], $entry['response'])) {
            @unlink($path);
            return null;
        }
        if ((int) $entry['expires_at'] < time()) {
            @unlink($path);
            return null;
        }
        if (!hash_equals((string) $entry['fingerprint'], $requestFingerprint)) {
            throw new UnexpectedValueException('An idempotency key was reused for a different request.');
        }

        $stored = $entry['response'];
        if (
            !is_array($stored)
            || !is_int($stored['status'] ?? null)
            || !is_array($stored['headers'] ?? null)
            || !is_string($stored['body'] ?? null)
        ) {
            @unlink($path);
            return null;
        }

        $headers = [];
        foreach ($stored['headers'] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }
        $headers['X-LUMUI-Idempotent-Replay'] = 'true';
        return new Response($stored['status'], $headers, $stored['body']);
    }

    public function store(
        string $key,
        string $requestFingerprint,
        Response $response,
        int $ttlSeconds
    ): void {
        $path = $this->path($key);
        $lock = fopen($path . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('The action replay lock could not be acquired.');
        }
        try {
            if (is_file($path)) {
                $existing = $this->retrieve($key, $requestFingerprint);
                if ($existing !== null) {
                    return;
                }
            }
            $entry = json_encode([
                'fingerprint' => $requestFingerprint,
                'expires_at' => time() + max(60, $ttlSeconds),
                'response' => [
                    'status' => $response->status(),
                    'headers' => $response->headers(),
                    'body' => $response->body(),
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($entry)) {
                throw new RuntimeException('The action response could not be stored.');
            }

            $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            if (file_put_contents($temporary, $entry, LOCK_EX) === false) {
                throw new RuntimeException('The action response could not be written.');
            }
            @chmod($temporary, 0660);
            if (!rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('The action response could not be committed.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}

