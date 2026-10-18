<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Hosting/Preflight.php';
$app=require dirname(__DIR__).'/app/bootstrap.php';
(new DirectWeb\Http\Controller($app))->run();
