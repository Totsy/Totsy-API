<?php

use Doctrine\Common\Cache\MemcacheCache;

/**
* Setup autoloaders for the API application, and other application settings as
* global constants.
*/
require_once 'app/autoload.php';

define('API_ENV', getenv('API_ENV') ? getenv('API_ENV') : 'dev');

$cache = new \Totsy\Cache;
$stats = $cache->getCache()->getStats();

$stats['type'] = $cache->getCacheBackend();

header('Content-Type: application/json');
echo json_encode($stats);
