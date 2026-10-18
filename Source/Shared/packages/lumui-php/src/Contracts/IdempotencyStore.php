<?php
declare(strict_types=1);

namespace Lumui\Contracts;

use Lumui\Http\Response;

interface IdempotencyStore
{
    
    public function retrieve(string $key, string $requestFingerprint): ?Response;

    public function store(
        string $key,
        string $requestFingerprint,
        Response $response,
        int $ttlSeconds
    ): void;
}

