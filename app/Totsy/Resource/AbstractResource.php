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
    Sonno\Http\Response\Response;

/**
 * The base class for all supported Totsy resource classes.
 */
class AbstractResource
{
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
            $results[] = $this->_formatItem($item->getData());
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

        return json_encode($this->_formatItem($item->getData()));
    }

    /**
     * @param $item array
     * @param $fields array|null
     * @param $links array|null
     * @return array
     */
    protected function _formatItem(array $item, $fields = NULL, $links = NULL)
    {
        $itemData = array();

        if (is_null($fields)) {
            $fields = $this->_fields;
        }
        if (is_null($links)) {
            $links = $this->_links;
        }

        // add selected data from the incoming $item to the output $itemData
        foreach ($fields as $outputFieldName => $dataFieldName) {
            if (is_int($outputFieldName)) {
                $outputFieldName = $dataFieldName;
            }

            // data field is an embedded object
            if (is_array($dataFieldName)) {
                $itemData[$outputFieldName] = $this->_formatItem(
                    $item,
                    $dataFieldName,
                    array()
                );

            // data field is an alias of an existing field
            } else if (is_string($dataFieldName)) {
                $itemData[$outputFieldName] = $item[$dataFieldName];
            }
        }

        // populate hyperlinks if necessary
        if ($links && count($links)) {
            $itemData['links'] = array();

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

                $link['href'] = $builder->buildFromMap($item);
                $itemData['links'][] = $link;
            }
        }

        return $itemData;
    }
}