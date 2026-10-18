<?php
declare(strict_types=1);
if(!defined('DIRECTWEB_WORDPRESS')){http_response_code(404);exit;}
if (PHP_VERSION_ID < 80100) { throw new RuntimeException('PHP 8.1 required.'); }
foreach (['pdo_sqlite','sqlite3','zip','sodium','mbstring','openssl','fileinfo'] as $extension) { if (!extension_loaded($extension)) { throw new RuntimeException('PHP extension required: '.$extension); } }
require dirname(__DIR__) . '/packages/lumui-php/autoload.php';
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'DirectWeb\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 10)) . '.php';
        if (is_file($file)) { require $file; }
    }
});
$config = require dirname(__DIR__) . '/config/app.php';
$installation=rtrim($config['storage'],'/\\').'/database/installation.json';
if(is_file($installation)){$saved=json_decode(file_get_contents($installation),true,16,JSON_THROW_ON_ERROR);foreach(['admin_host','base_path'] as $key){if(getenv('DIRECTWEB_'.strtoupper($key))===false&&isset($saved[$key])){$config[$key]=$saved[$key];}}}
if (is_file(dirname(__DIR__) . '/config/local.php')) { $config = array_replace($config, require dirname(__DIR__) . '/config/local.php'); }
$config['admin_host']=DirectWeb\Core\Files::host($config['admin_host']);
if(!preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D',$config['base_path'])){throw new RuntimeException('Invalid installation path.');}
return new DirectWeb\Core\App(dirname(__DIR__), $config);
