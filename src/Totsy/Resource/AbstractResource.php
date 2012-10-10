<?php

/**
 * @category   Totsy
 * @package    Totsy\Resource
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

namespace Totsy\Resource;

use Sonno\Annotation\GET,
    Sonno\Annotation\Path,
    Sonno\Annotation\Produces,
    Sonno\Annotation\Context,
    Sonno\Annotation\PathParam,
    Sonno\Annotation\QueryParam,
    Sonno\Http\Response\Response,

    Totsy\Exception\WebApplicationException,

    Doctrine\Common\Cache\CacheProvider,
    Doctrine\Common\Cache\ApcCache,
    Doctrine\Common\Cache\MemcacheCache,

    Monolog\Logger,
    Monolog\Handler\StreamHandler,
    Monolog\Handler\NativeMailerHandler,
    Monolog\Processor\WebProcessor;

/**
 * The base class for all supported Totsy resource classes.
 */
abstract class AbstractResource
{
    /**
     * The Magento model group name that this resource represents.
     *
     * @var string
     */
    protected $_modelGroupName;

    /**
     * The Magento model that this resource represents.
     *
     * @var Magento_Core_Model_Abstract
     */
    protected $_model;

    /**
     * The incoming HTTP request.
     *
     * @var \Sonno\Http\Request\RequestInterface
     * @Context("Request")
     */
    protected $_request;

    /**
     * Information about the incoming URI.
     *
     * @var \Sonno\Uri\UriInfo
     * @Context("UriInfo")
     */
    protected $_uriInfo;

    /**
     * Logging object.
     *
     * @var \Monolog\Logger;
     */
    protected $_logger;

    public function __construct()
    {
        $this->_model = \Mage::getSingleton($this->_modelGroupName);

        $this->_initLogger();
    }

    /**
     * Setter for the incoming HTTP request.
     *
     * @param \Sonno\Http\Request\RequestInterface $request
     */
    public function setRequest(\Sonno\Http\Request\RequestInterface $request)
    {
        $this->_request = $request;
    }

    /**
     * Setter for information about the incoming URI.
     *
     * @param \Sonno\Uri\UriInfo $uriInfo
     */
    public function setUriInfo(\Sonno\Uri\UriInfo $uriInfo)
    {
        $this->_uriInfo = $uriInfo;
    }

    /**
     * Construct a response (application/json) of a entity collection from the
     * local model.
     *
     * @param $filters array The set of Magento ORM filters to apply.
     * @param $order   string The sort criteria.
     * @return string json-encoded
     */
    public function getCollection($filters = array(), $order = null)
    {
        // hollow items are ID values only
        $hollowItems = $this->_model->getCollection();

        if ($hollowItems instanceof \Mage_Eav_Model_Entity_Collection_Abstract) {
            foreach ($filters as $filterName => $condition) {
                $hollowItems->addAttributeToFilter($filterName, $condition);
            }
        } else {
            foreach ($filters as $filterName => $condition) {
                $hollowItems->addFieldToFilter($filterName, $condition);
            }
        }

        if (null != $order) {
            $direction = 'ASC';
            if (strpos($order, ' ') !== false) {
                list($order, $direction) = explode(' ', $order);
            }

            $hollowItems->setOrder($order, $direction);
        }

        $results = array();
        foreach ($hollowItems as $hollowItem) {
            $item = $this->_model->load($hollowItem->getId());
            $formattedItem = $this->_formatItem($item);;
            if (false !== $formattedItem) {
                $results[] = $formattedItem;
            }
        }

        $response = json_encode($results);
        $this->_addCache($response);

        return $response;
    }

    /**
     * Construct a response (application/json) of a entity from the local model.
     *
     * @param $id int
     * @return string json-encoded
     */
    public function getItem($id)
    {
        $item = $this->_model->load($id);

        if ($item->isObjectNew()) {
            return new Response(404);
        }

        return json_encode($this->_formatItem($item, $this->_fields, $this->_links));
    }

    /**
     * @param $item \Mage_Core_Model_Abstract
     * @param $fields array|null
     * @param $links array|null
     * @return array
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $sourceData = array();
        if ($item instanceof \Mage_Core_Model_Abstract) {
            $sourceData = $item->getData();
        } else if (is_array($item)) {
            $sourceData = $item;
        }

        $formattedData = array();

        if (is_null($fields)) {
            $fields = isset($this->_fields) ? $this->_fields : array();
        }
        if (is_null($links)) {
            $links = isset($this->_links) ? $this->_links : array();
        }

        // add selected data from incoming $sourceData to output $formattedData
        foreach ($fields as $outputFieldName => $dataFieldName) {
            if (is_int($outputFieldName)) {
                $outputFieldName = $dataFieldName;
            }

            // data field is an embedded object
            if (is_array($dataFieldName)) {
                $formattedData[$outputFieldName] = $this->_formatItem(
                    $item,
                    $dataFieldName,
                    array()
                );

                // the formatItem call for the embedded element may have added
                // links, which don't belong in embedded elements
                unset($formattedData[$outputFieldName]['links']);

            // data field is an alias of an existing field
            } else if (is_string($dataFieldName)) {
                $formattedData[$outputFieldName] = isset($sourceData[$dataFieldName])
                    ? $sourceData[$dataFieldName]
                    : NULL;
            }
        }

        // populate hyperlinks if necessary
        if ($links && count($links)) {
            $formattedData['links'] = array();

            foreach ($links as $link) {
                $builder = $this->_uriInfo->getBaseUriBuilder();

                // the link's "href" was provided explicitly
                if (isset($link['href'])) {
                    // as a relative URI
                    if (strpos($link['href'], '://') === false) {
                        $link['href'] = $builder->replaceQuery(null)
                            ->path($link['href'])
                            ->buildFromMap($sourceData);
                    }
                } else if (isset($link['resource'])) {
                    $link['href'] = $builder->replaceQuery(null)
                        ->resourcePath($link['resource']['class'], $link['resource']['method'])
                        ->build();
                    unset($link['resource']);
                }

                $formattedData['links'][] = $link;
            }
        }

        return $formattedData;
    }

    /**
     * Populate a Magento model object with an array of data, and persist the
     * updated object.
     *
     * @param $obj \Mage_Core_Model_Abstract
     * @param $data array The data to populate, or NULL which will use the
     *                    incoming request data.
     * @return bool
     * @throws Sonno\Application\WebApplicationException
     */
    protected function _populateModelInstance($obj, $data = NULL)
    {
        if (is_null($data)) {
            $data = json_decode($this->_request->getRequestBody(), true);
            if (is_null($data)) {
                throw new WebApplicationException(
                    400,
                    'Malformed entity representation in request body'
                );
            }
        }

        // rewrite keys in the data array for any aliased keys
        foreach ($this->_fields as $outputFieldName => $dataFieldName) {
            if (is_string($outputFieldName) && isset($data[$outputFieldName])) {
                $data[$dataFieldName] = $data[$outputFieldName];
                unset($data[$outputFieldName]);
            }
        }

        $obj->addData($data);

        if (method_exists($obj, 'validate')) {
            $validationErrors = $obj->validate();
            if (is_array($validationErrors) && count($validationErrors)) {
                throw new WebApplicationException(400, $validationErrors[0]);
            }
        }

        try {
            $obj->save();
        } catch(\Mage_Core_Exception $mageException) {
            $this->_logger->err($mageException->getMessage());
            throw new WebApplicationException(400, $mageException->getMessage());
        } catch(\Exception $e) {
            $this->_logger->err($e->getMessage(), $e->getTrace());
            throw new WebApplicationException(500, $e);
        }

        return true;
    }

    /**
     * Check the local cache for a copy of a response body that can fulfill the
     * current request.
     *
     * @return mixed
     */
    protected function _inspectCache()
    {
        // ignore cache
        if ('dev' == API_ENV || // in a development environment
            $this->_request->getQueryParam('skipCache')
        ) {
            return false;
        }

        $cache = \Mage::app()->getCache();

        $cacheKey = $this->_getCacheKey();

        if ($cache->test($cacheKey)) {
            // look to fulfill a conditional GET request first
            $metadata = $cache->getMetadatas($cacheKey);
            $result = $this->_request->evaluatePreconditions(
                date('c', $metadata['mtime'])
            );
            if (null !== $result) {
                return $result;
            }

            return new Response(
                200,
                $cache->load($cacheKey),
                array('Cache-Control' => 'max-age=' . ($metadata['expire'] - time()))
            );
        }

        return false;
    }

    /**
     * Add a new entry to the local cache store.
     *
     * @param mixed $value The object to store.
     *
     * @return \Sonno\Http\Response\Response|false
     */
    protected function _addCache($value, $tags = array(), $lifetime = false)
    {
        if (!is_array($tags)) {
            $tags = array($tags);
        }

        $cacheKey = $this->_getCacheKey();

        $cache = \Mage::app()->getCache();

        // add a generic tag for this application
        $tags[] = 'RESTAPI';

        if ('dev' != API_ENV && !$cache->test($cacheKey)) {
            $cache->save($value, $cacheKey, $tags, $lifetime);
            return new Response(
                200,
                $value,
                array('Cache-Control' => "max-age=$lifetime")
            );
        }

        return false;
    }

    /**
     * Create and return the cache key that represents this request.
     *
     * @return string
     */
    protected function _getCacheKey()
    {
        return 'RESTAPI_' . md5(
            $this->_request->getRequestUri() . http_build_query($this->_request->getQueryParams())
        );
    }

    /**
     * Parse the integer entity ID value from a resource URL.
     *
     * @param $url string The resource URL.
     *
     * @return int
     * @throws \Totsy\Exception\WebApplicationException
     */
    protected function _getEntityIdFromUrl($url)
    {
        $offset = strrpos($url, '/');
        if ($offset === false) {
            throw new WebApplicationException(
                400,
                "Invalid Resource URL $link[href]"
            );
        }

        return intval(substr($url, $offset+1));
    }

    /**
     * Setup the local logger object based on settings in an external file.
     *
     * @param string $configFile The file containing logging settings.
     * @return void
     */
    protected function _initLogger($configFile = 'etc/logger.yaml')
    {
        if (extension_loaded('yaml') && file_exists($configFile)) {
            $config = yaml_parse_file($configFile);
            $config = $config[API_ENV];

            $this->_logger = new Logger('restapi');

            // setup a processor that adds request information to log records
            $request = &$this->_request;
            $this->_logger->pushProcessor(new WebProcessor());

            // add handlers for each specified handler in the config file
            foreach ($config as $confLogger) {
                $level = isset($confLogger['level'])
                    ? constant('\Monolog\Logger::' . $confLogger['level'])
                    : Logger::NOTICE;

                switch ($confLogger['handler']) {
                    case 'file':
                        $this->_logger->pushHandler(
                            new StreamHandler($confLogger['filename']),
                            $level
                        );
                        break;
                    case 'mail':
                        $this->_logger->pushHandler(
                            new NativeMailerHandler(
                                $confLogger['recipient'],
                                $confLogger['subject'],
                                $confLogger['sender']
                            ),
                            $level
                        );
                }
            }
        }
    }
}
