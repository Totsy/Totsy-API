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

    Mage;

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
     * A prefix for the cache key for cache entries.
     * When populated, response bodies will be cached using this prefix,
     * followed by a hash of the incoming request, as the cache key.
     * When empty, response bodies will never be cached.
     *
     * @var string
     */
    protected $_cachePrefix = '';

    /**
     * The frequency with which to update cached response bodies.
     * This is the number of requests to serve cached content to until it
     * is considered stale and is refreshed.
     *
     * @var int
     */
    protected $_cacheRefreshFrequency = 0;

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

    public function __construct()
    {
        $this->_model = Mage::getSingleton($this->_modelGroupName);
    }

    /**
     * Construct a response (application/json) of a entity collection from the
     * local model.
     *
     * @param $filters array The set of Magento ORM filters to apply.
     * @return string json-encoded
     */
    public function getCollection($filters = array())
    {
        // hollow items are ID values only
        $hollowItems = $this->_model->getCollection();

        if ($hollowItems instanceof \Mage_Eav_Model_Entity_Collection_Abstract) {
            foreach ($filters as $filterName => $condition) {
                $hollowItems->addAttributeToFilter($filterName, $condition);
            }
        } else {
            foreach ($filters as $filterName => $condition) {
                $hollowItems->addFilter($filterName, $condition);
            }
        }

        $results = array();
        foreach ($hollowItems as $hollowItem) {
            $item = $this->_model->load($hollowItem->getId());
            $results[] = $this->_formatItem($item);
        }

        $response = json_encode($results);

        if (isset($this->_cachePrefix)) {
            $cacheKey = $this->_cachePrefix . md5($this->_request->getRequestUri());
            apc_add($cacheKey, $response);
        }

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

        return json_encode($this->_formatItem($item));
    }

    /**
     * @param $item Mage_Core_Model_Abstract
     * @param $fields array|null
     * @param $links array|null
     * @return array
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $sourceData    = ($item instanceof \Mage_Core_Model_Abstract)
            ? $item->getData()
            : array();
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
                        ->resourcePath(
                        $link['resource']['class'],
                        $link['resource']['method']
                    )->build();
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
     * @param $obj Mage_Core_Model_Abstract
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

        $validationErrors = $obj->validate();
        if (is_array($validationErrors) && count($validationErrors)) {
            throw new WebApplicationException(
                400,
                "Entity Validation Error: " . $validationErrors[0]
            );
        }

        try {
            $obj->save();
        } catch(\Mage_Core_Exception $mageException) {
            Mage::logException($mageException);
            throw new WebApplicationException(400, $mageException->getMessage());
        } catch(\Exception $e) {
            Mage::logException($e);
            throw new WebApplicationException(500, $e);
        }

        return true;
    }

    /**
     * Check the local cache for a copy of a response body that can fulfill the
     * current request.
     *
     * @return bool|mixed
     */
    protected function _inspectCache()
    {
        // ignore cache
        if ('dev' == API_ENV || // in a development environment
            $this->_cachePrefix || // without a prefix declared
            (isset($this->_cacheRefreshFrequency) &&
                rand(1, $this->_cacheRefreshFrequency) == 1
            )
        ) {
            return false;
        }

        $cacheKey = $this->_cachePrefix . md5($this->_request->getRequestUri());
        if (apc_exists($cacheKey)) {
            return apc_fetch($cacheKey);
        }
    }
}
