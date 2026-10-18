<?php
$missing=[];
if(PHP_VERSION_ID<80100){$missing[]='PHP 8.1';}
foreach(['pdo_sqlite','sqlite3','zip','sodium','mbstring','openssl','fileinfo'] as $extension){if(!extension_loaded($extension)){$missing[]=$extension;}}
if(!defined('PASSWORD_ARGON2ID')){$missing[]='Argon2id';}
if($missing){http_response_code(503);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>DirectWeb</title><h1>DirectWeb</h1><p>Required: '.htmlspecialchars(implode(', ',$missing),ENT_QUOTES,'UTF-8').'</p></html>';exit;}
