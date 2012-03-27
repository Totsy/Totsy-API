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
    Sonno\Application\WebApplicationException,

    Mage;

/**
 * The base class for all supported Totsy resource classes.
 */
class AbstractResource
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
     * @var Sonno\Http\Request\RequestInterface
     * @Context("Request")
     */
    protected $_request;

    /**
     * Information about the incoming URI.
     *
     * @var Sonno\Uri\UriInfo
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

        foreach ($filters as $filterName => $condition) {
            $hollowItems->addAttributeToFilter($filterName, $condition);
        }

        $results = array();
        foreach ($hollowItems as $hollowItem) {
            $item = $this->_model->load($hollowItem->getId());
            $results[] = $this->_formatItem($item);
        }

        return json_encode($results);
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
        $sourceData    = $item->getData();
        $formattedData = array();

        if (is_null($fields)) {
            $fields = isset($this->_fields) ? $this->_fields : array();
        }
        if (is_null($links)) {
            $links = isset($this->_links) ? $this->_links : array();
        }

        // add selected data from the incoming $sourceData to the output $formattedData
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
                $builder->replaceQuery(null);

                if (isset($link['href'])) {
                    $builder->path($link['href']);
                } else if (isset($link['resource'])) {
                    $builder->resourcePath(
                        $link['resource']['class'],
                        $link['resource']['method']
                    );
                    unset($link['resource']);
                }

                $link['href'] = $builder->buildFromMap($sourceData);
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
                $error = 'Malformed entity representation in request body';
                $e = new WebApplicationException(400);
                $e->getResponse()->setHeaders(
                    array('X-API-Error' => $error)
                );
                throw $e;
            }
        }

        // rewrite keys in the data array for any aliased keys
        foreach ($this->_fields as $outputFieldName => $dataFieldName) {
            if (is_string($outputFieldName)) {
                $data[$dataFieldName] = $data[$outputFieldName];
                unset($data[$outputFieldName]);
            }
        }

        $obj->addData($data);

        try {
            $obj->save();
        } catch(\Mage_Core_Exception $mageException) {
            Mage::logException($mageException);

            $e = new WebApplicationException(400);
            $e->getResponse()->setHeaders(
                array('X-API-Error' => $mageException->getMessage())
            );
            throw $e;
        } catch(\Exception $mageException) {
            Mage::logException($mageException);

            $e = new WebApplicationException(500);
            $e->getResponse()->setHeaders(
                array('X-API-Error' => $mageException->getMessage())
            );
            throw $e;
        }

        return true;
    }
}