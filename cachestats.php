<?php

use Doctrine\Common\Cache\MemcacheCache;

/**
* Setup autoloaders for the API application, and other application settings as
* global constants.
*/
require_once 'app/autoload.php';

define('API_ENV', getenv('API_ENV') ? getenv('API_ENV') : 'dev');

// setup the local cache object by parsing the memcache configuration
$stats = array('type' => 'none');
$confMemcacheFile = 'etc/' . API_ENV . '/memcache.yaml';
if (extension_loaded('yaml') && file_exists($confMemcacheFile)) {
    $confMemcache = yaml_parse_file($confMemcacheFile);
    $memcache = new Memcache();
    foreach ($confMemcache['servers'] as $server) {
        $memcache->addServer($server['host'], $server['port']);
    }

    $cache = new MemcacheCache();
    $cache->setMemcache($memcache);
    $stats['type'] = 'memcache';
} else {
    $cache = new ApcCache();
    $stats['type'] = 'apc';
}

$stats = array_merge($stats, $cache->getStats());

echo json_encode($stats);
