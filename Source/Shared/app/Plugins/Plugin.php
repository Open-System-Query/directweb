<?php
declare(strict_types=1);
namespace DirectWeb\Plugins;
use DirectWeb\Core\App;
interface Plugin
{
    public function register(App $app, Registry $registry): void;
}
