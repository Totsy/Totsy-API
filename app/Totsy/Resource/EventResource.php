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
        'max_discount_pct',
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
            $this->_addCache($events, 'FPC'); // @todo use correct cache tag
            return $events;
        } else {
            $filters = array('level' => 3);

            // setup filters on event start & end dates using the 'when'
            // request parameter
            if ($when = $this->_request->getQueryParam('when')) {
                switch ($when) {
                    case 'past':
                        $filters['event_end_date'] = array(
                            'to' => date('Y-m-d H:m:s', $this->_getCurrentTime()),
                            'datetime' => true,
                        );
                        break;
                    case 'current':
                        $filters['event_start_date'] = array(
                            'to' => date('Y-m-d H:m:s', $this->_getCurrentTime()),
                            'datetime' => true,
                        );
                        $filters['event_end_date'] = array(
                            'from' => date('Y-m-d H:m:s', $this->_getCurrentTime()),
                            'datetime' => true,
                        );
                        break;
                    case 'upcoming':
                        $filters['event_start_date'] = array(
                            'from' => date('Y-m-d H:m:s', $this->_getCurrentTime()),
                            'to'   => date('Y-m-d H:m:s', $this->_getCurrentTime() + 5 * 24 * 60 * 60),
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
        if ($response = $this->_inspectCache()) {
            return $response;
        }

        $item = $this->_model->load($id);

        if ($item->isObjectNew()) {
            return new Response(404);
        }

        $response = json_encode($this->_formatItem($item));
        $this->_addCache($response, $item->getCacheTags());

        return $response;
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

        if (is_null($sortEntry)) {
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
            $formattedEvent = $this->_formatItem($categoryInfo);
            if (false !== $formattedEvent &&
                (
                    'upcoming' == $this->_request->getQueryParam('when') ||
                    strtotime($categoryInfo['event_start_date']) < $this->_getCurrentTime()
                )
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
     * @param $item array
     * @param $fields null|array
     * @param $links null|array
     * @return array|bool
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $data = array();
        if ($item instanceof \Mage_Core_Model_Abstract) {
            $data = $item->getData();
        } else if (is_array($item)) {
            $data = $item;
        }

        $imageBaseUrl = \Mage::getBaseUrl() . 'media/catalog/category/';

        // construct an object literal for event images
        $skinPlaceholderUrl = \Mage::getBaseUrl()
            . 'skin/frontend/enterprise/harapartners/images/catalog/product/placeholder/';
        $data['image'] = array(
            'default'   => $skinPlaceholderUrl . 'image.jpg',
            'small'     => $skinPlaceholderUrl . 'small.jpg',
            'thumbnail' => $skinPlaceholderUrl . 'thumbnail.jpg',
        );

        // 'default' image
        if (isset($data['default_image']) && !is_array($data['default_image'])) {
            $data['image']['default'] = $imageBaseUrl . $data['default_image'];
        } else if (isset($data['image']) && !is_array($data['image'])) {
            $data['image']['default'] = $imageBaseUrl . $data['image'];
        }

        // 'small' image
        if (isset($data['small_image'])) {
            $data['image']['small'] = $imageBaseUrl . $data['small_image'];
        }

        // 'thumbnail' image
        if (isset($data['thumbnail'])) {
            $data['image']['thumbnail'] = $imageBaseUrl . $data['thumbnail'];
        }

        // 'logo' image
        if (isset($data['logo'])) {
            $data['image']['logo'] = $imageBaseUrl . $data['logo'];
        }

        if (empty($links)) {
            $links = $this->_links;
        }

        $eventUrl = \Mage::getBaseUrl() . 'sales/' . $data['url_key'] . '.html';
        $links[] = array(
            'rel' => 'alternate',
            'href' => $eventUrl
        );

        return parent::_formatItem($data, $fields, $links);
    }
}
