<?php
declare(strict_types=1);

namespace Lumui\Contracts;

interface SurfaceProvider
{
    public function routeIdFromPath(string $path): string;

    public function routeIdFromSurfaceId(string $surfaceId): ?string;

    
    public function surface(string $routeId): array;

    
    public function descriptor(): array;

    
    public function routes(): array;

    public function revision(): int;
}

