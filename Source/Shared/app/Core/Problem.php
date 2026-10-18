<?php
declare(strict_types=1);
namespace DirectWeb\Core;
final class Problem extends \RuntimeException
{
    public function __construct(public readonly string $key,public readonly int $status=422,public readonly array $parameters=[])
    {
        parent::__construct($parameters?vsprintf($key,$parameters):$key);
    }
}
