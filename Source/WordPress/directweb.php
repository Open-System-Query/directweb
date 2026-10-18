<?php
$directwebMetadata = <<<'PLUGIN'
Plugin Name: DirectWeb
Description: Website hosting, versions, backups and optional plugins.
Version: 1.0
Requires at least: 6.6
Requires PHP: 8.1
Text Domain: directweb
Update URI: false
PLUGIN;
unset($directwebMetadata);
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/includes/Website.php';
require_once __DIR__.'/includes/Bridge.php';
register_activation_hook(__FILE__, [DirectWebWordPress\Bridge::class, 'activate']);
register_deactivation_hook(__FILE__, [DirectWebWordPress\Bridge::class, 'deactivate']);
DirectWebWordPress\Bridge::register();
