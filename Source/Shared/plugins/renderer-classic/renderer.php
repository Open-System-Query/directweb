<?php
declare(strict_types=1);
require_once __DIR__.'/src/Renderer.php';
return static fn(DirectWeb\Core\App $app): Lumui\Contracts\SurfaceRenderer => new DirectWebClassic\Renderer($app);
