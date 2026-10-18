<?php
declare(strict_types=1);

namespace Lumui\Contracts;

interface ConfirmationService
{
    
    public function issue(array $message, int $ttlSeconds = 300): array;

    
    public function verify(array $message, string $token): bool;
}

