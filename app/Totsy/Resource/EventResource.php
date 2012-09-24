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
        $sortEntry = \Mage::getModel('categoryevent/sortentry')->loadCurrent();

        // fetch the event information for the desired event group
        $when = $this->_request->getQueryParam('when');
        switch ($when) {
            case 'current':
                $queue = json_decode($sortEntry->getLiveQueue(), true);
                break;
            case 'upcoming':
                $queue = json_decode($sortEntry->getUpcomingQueue(), true);
                break;
            default:
                throw new WebApplicationException(
                    400,
                    "Invalid value for 'when' parameter: $when"
                );
        }

        // build the result, ignoring events without products or upcoming events
        $results = array();
        foreach ($queue as $categoryInfo) {
            $category = \Mage::getModel('catalog/category')
                ->load($categoryInfo['entity_id']);
            if ('upcoming' == $when
                || (strtotime($categoryInfo['event_start_date']) < $this->_getCurrentTime()
                    && count($category->getProductCollection()) > 0
                )
            ) {
                $formattedEvent = $this->_formatItem($categoryInfo);
                $results[] = $formattedEvent;
            }
        }

        return json_encode($results);
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
