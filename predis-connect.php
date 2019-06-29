<?php
if (!defined('WORKER_TYPE_HANDLER') )
    exit;

require 'predis/autoload.php';
Predis\Autoloader::register();

// Создание объекта Redis и коннект к серверу
try { $oRedis = new Predis\Client(['scheme' => $sRedisScheme, 'host' => $sRedisHost, 'port' => $nRedisPort], ['read_write_timeout' => 0]); }
catch (Exception $e) { die($e->getMessage() ); }
?>