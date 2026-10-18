<?php
declare(strict_types=1);
namespace DirectWeb\Plugins;
use DirectWeb\Core\App;
interface Lifecycle
{
    public function activate(App $app,array $user): void;
    public function deactivate(App $app,array $user): void;
}
