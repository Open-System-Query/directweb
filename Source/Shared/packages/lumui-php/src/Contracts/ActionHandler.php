<?php
declare(strict_types=1);

namespace Lumui\Contracts;

interface ActionHandler
{
    
    public function invoke(array $message, array $surface, array $definition): array;
}

