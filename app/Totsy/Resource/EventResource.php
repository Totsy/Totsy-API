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
    Sonno\Application\WebApplicationException;

/**
 * An Event is a time-limited sale of zero or more Product items.
 */
class EventResource extends AbstractResource
{
    protected $_modelGroupName = 'catalog/category';

    protected $_fields = array(
        'name',
        'description',
        'short_description',
        'discount_pct',
        'department',
        'age',
        'start'  => 'event_start_date',
        'end'    => 'event_end_date',
        'image',
        'web_url',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/event/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/collection/product',
            'href' => '/event/{entity_id}/product'
        ),
    );

    /**
     * Retrieve a collection of all active/current Event instances.
     *
     * @GET
     * @Path("/event")
     * @Produces({"application/json"})
     */
    public function getEventCollection()
    {
        $filters = array(
            'level' => array('gt' => 0),
            'event_start_date' => array('notnull' => true)
        );

        // setup filters on event start & end dates using the 'when' parameter
        if ($when = $this->_request->getQueryParam('when')) {
            switch ($when) {
                case 'past':
                    $filters['event_end_date'] = array(
                        'to' => date('Y-m-d H:m:s'),
                        'datetime' => true,
                    );
                    break;
                case 'current':
                    $filters['event_start_date'] = array(
                        'to' => date('Y-m-d H:m:s'),
                        'datetime' => true,
                    );
                    $filters['event_end_date'] = array(
                        'from' => date('Y-m-d H:m:s'),
                        'datetime' => true,
                    );
                    break;
                case 'upcoming':
                    $filters['event_start_date'] = array(
                        'from' => date('Y-m-d H:m:s'),
                        'datetime' => true,
                    );
                    break;
                default:
                    $errorMessage = "Invalid value for the 'when' parameter: "
                        . $when;
                    $e = new WebApplicationException(400);
                    $e->getResponse()->setHeaders(
                        array('X-API-Error' => $errorMessage)
                    );
                    throw $e;
            }
        }

        return $this->getCollection($filters);
    }

    /**
     * A single sale Event instance.
     *
     * @GET
     * @Path("/event/{id}")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getEventEntity($id)
    {
        return $this->getItem($id);
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
            $productCount = count($item->getProductCollection());
            if ($productCount && $formattedItem = $this->_formatItem($item)) {
                $results[] = $formattedItem;
            }
        }

        return json_encode($results);
    }

    /**
     * Add formatted fields to item data before deferring to the default
     * item formatting.
     *
     * @param $item array|Mage_Core_Model_Abstract
     * @param $fields null|array
     * @param $links null|array
     * @return array
     *
     * @todo Populate a real value for the "discount_pct" field.
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $sourceData    = $item->getData();
        $formattedData = array();

        $imageBaseUrl = \Mage::getBaseUrl() . '/media/catalog/category/';

        // scrape together department & age data from event products
        $formattedData['department'] = array();
        $formattedData['age'] = array();

        $products = $item->getProductCollection();
        foreach ($products as $product) {
            $product->load($product->getId());

            $departments = $product->getAttributeText('departments');
            $ages = $product->getAttributeText('ages');

            if (is_array($departments)) {
                $formattedData['department'] = $formattedData['department']
                    + $departments;
            } else if (is_string($departments)) {
                $formattedData['department'][] = $departments;
            }

            if (is_array($ages)) {
                $formattedData['age'] = $formattedData['age']
                    + $ages;
            } else if (is_string($ages)) {
                $formattedData['age'][] = $ages;
            }
        }

        $formattedData['department'] = array_unique(
            $formattedData['department']
        );
        $formattedData['age'] = array_unique(
            $formattedData['age']
        );

        // construct an object literal for event images
        if (isset($sourceData['image'])) {
            $formattedData['default_image'] = $sourceData['image'];
        }
        $formattedData['image'] = array();
        if (isset($sourceData['default_image'])) {
            $formattedData['image']['default'] = $imageBaseUrl
                . $sourceData['default_image'];
        }
        if (isset($sourceData['small_image'])) {
            $formattedData['image']['small'] = $imageBaseUrl
                . $sourceData['small_image'];
        }
        if (isset($sourceData['thumbnail'])) {
            $formattedData['image']['thumbnail'] = $imageBaseUrl
                . $sourceData['thumbnail'];
        }
        if (isset($sourceData['logo'])) {
            $formattedData['image']['logo'] = $imageBaseUrl
                . $sourceData['logo'];
        }

        $formattedData['discount_pct'] = 50;

        if (empty($links)) {
            $links = $this->_links;
        }

        $eventUrl = \Mage::getBaseUrl() .
            'sales/' . $sourceData['url_key'] . '.html';
        $links[] = array(
            'rel' => 'alternate',
            'href' => $eventUrl
        );

        $item->addData($formattedData);
        return parent::_formatItem($item, $fields, $links);
    }
}
