<?php

use Doctrine\Common\Cache\MemcacheCache;

/**
* Setup autoloaders for the API application, and other application settings as
* global constants.
*/
require_once 'app/autoload.php';

define('API_ENV', getenv('API_ENV') ? getenv('API_ENV') : 'dev');

// setup the local cache object by parsing the memcache configuration
$confMemcacheFile = 'etc/' . API_ENV . '/memcache.yaml';
if (file_exists($confMemcacheFile)) {
    $confMemcache = yaml_parse_file($confMemcacheFile);
    $memcache = new Memcache();
    $memcache->addServer($confMemcache['host'], $confMemcache['port']);

    $cache = new MemcacheCache();
    $cache->setMemcache($memcache);
} else {
    $cache = new ApcCache();
}

echo json_encode($cache->getStats());
