<?php
declare(strict_types=1);
return [
    'name' => 'DirectWeb',
    'version' => '1.0',
    'storage' => getenv('DIRECTWEB_STORAGE') ?: dirname(__DIR__) . '/storage',
    'admin_host' => getenv('DIRECTWEB_ADMIN_HOST') ?: ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8787'),
    'base_path' => getenv('DIRECTWEB_BASE_PATH') !== false ? getenv('DIRECTWEB_BASE_PATH') : (PHP_SAPI==='cli'?'':rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/index.php')),'/.')),
    'secure' => getenv('DIRECTWEB_LOCAL') !== '1',
    'max_upload' => 134217728,
    'max_site' => 536870912,
    'max_files' => 10000,
    'retention' => 20,
    'backup_destination' => getenv('DIRECTWEB_BACKUP_DESTINATION') ?: '',
    'preview_base' => getenv('DIRECTWEB_PREVIEW_BASE') ?: (getenv('DIRECTWEB_LOCAL')==='1'?'localhost:'.(parse_url('http://'.(getenv('DIRECTWEB_ADMIN_HOST')?:'127.0.0.1:8787'),PHP_URL_PORT)?:8787):''),
];
