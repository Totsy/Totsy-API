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

    protected $_cacheEntryLifetime = 300;

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
        if ($response = $this->_inspectCache()) {
            return $response;
        }

        // look for a category event sort entry
        // this is a cached version of all events, indexed by date
        if ($events = $this->_getEventsFromSortEntry()) {
            $this->_addCache($events);
            return $events;
        } else {
            $filters = array('level' => 3);

            // setup filters on event start & end dates using the 'when'
            // request parameter
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
                        $errorMessage = "Invalid value for 'when' parameter: "
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
     * Get the event collection to fulfill the current request from the local
     * event cache (category sort entry).
     *
     * @return bool|string The JSON response for the current request, or false
     *                     if there is no available sort entry.
     */
    protected function _getEventsFromSortEntry()
    {
        $sortEntry = \Mage::getModel('categoryevent/sortentry')
            ->getCollection()
            ->addFilter('date', date('Y-m-d'))
            ->addFilter('store_id', 1)
            ->getFirstItem();

        if(is_null($sortEntry)) {
            return false;
        }

        // fetch the event information for the desired event group
        $queue = json_decode($sortEntry->getLiveQueue(), true);
        if ('upcoming' == $this->_request->getQueryParam('when')) {
            $queue = json_decode($sortEntry->getUpcomingQueue(), true);
        }

        if (empty($queue)) {
            return false;
        }

        // look for any preconditions satisfiable in the incoming request
        $result = $this->_request->evaluatePreconditions($sortEntry->getUpdatedAt());
        if (null !== $result) {
            return $result;
        }

        // build the result, ignoring events without products or upcoming events
        $results = array();
        foreach ($queue as $categoryInfo) {
            $category = $this->_model->load($categoryInfo['entity_id']);
            $formattedEvent = $this->_formatItem($category);
            if (false !== $formattedEvent &&
                strtotime($categoryInfo['event_start_date']) < $this->_getCurrentTime()
            ) {
                $results[] = $formattedEvent;
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
     * @return array|bool
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $sourceData    = $item->getData();
        $formattedData = array();

        $imageBaseUrl = \Mage::getBaseUrl() . 'media/catalog/category/';

        // scrape together department & age data from event products
        $formattedData['department'] = array();
        $formattedData['age'] = array();
        $formattedData['discount_pct'] = 0;

        $products = $item->getProductCollection()
            ->addAttributeToSelect('departments')
            ->addAttributeToSelect('ages')
            ->addAttributeToSelect('price')
            ->addAttributeToSelect('special_price');

        if (!count($products)) {
            return false;
        }

        foreach ($products as $product) {
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

            // calculate discount percentage for the event
            $priceDiff = $product->getPrice() - $product->getSpecialPrice();
            if ($product->getPrice()) {
                $discount = round($priceDiff / $product->getPrice() * 100);
                $formattedData['discount_pct'] = max(
                    $formattedData['discount_pct'],
                    $discount
                );
            }
        }

        $formattedData['department'] = array_values(
            array_unique($formattedData['department'])
        );
        $formattedData['age'] = array_values(
            array_unique($formattedData['age'])
        );

        // construct an object literal for event images
        $skinPlaceholderUrl = \Mage::getBaseUrl()
            . 'skin/frontend/enterprise/harapartners/images/catalog/product/placeholder/';
        $formattedData['image'] = array(
            'default'   => $skinPlaceholderUrl . 'image.jpg',
            'small'     => $skinPlaceholderUrl . 'small.jpg',
            'thumbnail' => $skinPlaceholderUrl . 'thumbnail.jpg',
        );

        // 'default' image
        if (isset($sourceData['default_image']) &&
            !is_array($sourceData['default_image'])
        ) {
            $formattedData['image']['default'] = $imageBaseUrl
                . $sourceData['default_image'];
        } else if (isset($sourceData['image']) &&
            !is_array($sourceData['image'])
        ) {
            $formattedData['image']['default'] = $imageBaseUrl
                . $sourceData['image'];
        }

        // 'small' image
        if (isset($sourceData['small_image'])) {
            $formattedData['image']['small'] = $imageBaseUrl
                . $sourceData['small_image'];
        }

        // 'thumbnail' image
        if (isset($sourceData['thumbnail'])) {
            $formattedData['image']['thumbnail'] = $imageBaseUrl
                . $sourceData['thumbnail'];
        }

        // 'logo' image
        if (isset($sourceData['logo'])) {
            $formattedData['image']['logo'] = $imageBaseUrl
                . $sourceData['logo'];
        }

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
