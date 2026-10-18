<?php
declare(strict_types=1);

namespace Lumui\Contracts;

interface SurfaceRenderer
{
    
    public function render(array $surface): string;
}

