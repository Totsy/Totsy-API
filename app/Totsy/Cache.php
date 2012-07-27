<?php
/**
 * @category    Totsy
 * @package     Totsy
 * @author      Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright   Copyright (c) 2012 Totsy LLC
 */

namespace Totsy;

use Totsy\Exception\UnsupportedCacheBackendException,

    Doctrine\Common\Cache\CacheProvider,
    Doctrine\Common\Cache\MemcacheCache,

    Memcache;

class Cache
{
    /**
     * The name of the cache backend.
     *
     * @var string
     */
    protected $_cacheBackend;

    /**
     * The local cache object.
     *
     * @var \Doctrine\Common\Cache\CacheProvider
     */
    protected $_cache;

    public function __construct()
    {
        $confCacheFile = 'etc/cache.yaml';
        if (extension_loaded('yaml') && file_exists($confCacheFile)) {
            $confCache = yaml_parse_file($confCacheFile);
            $confCache = $confCache[API_ENV];

            switch ($confCache['backend']) {
                case 'memcache':
                    $memcache = new Memcache();
                    foreach ($confCache['servers'] as $server) {
                        $memcache->addServer($server['host'], $server['port']);
                    }

                    $this->_cache = new MemcacheCache();
                    $this->_cache->setMemcache($memcache);
                    break;
                default:
                    throw new UnsupportedCacheBackendException;
            }

            $this->_cacheBackend = $confCache['backend'];
        }
    }

    /**
     * Get the name of the cache backend type used.
     *
     * @return string
     */
    public function getCacheBackend()
    {
        return $this->_cacheBackend;
    }

    /**
     * Get the cache object.
     *
     * @return \Doctrine\Common\Cache\CacheProvider
     */
    public function getCache()
    {
        return $this->_cache;
    }
}
