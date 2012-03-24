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

    Mage;

/**
 * An Event is a time-limited sale of zero or more Product items.
 */
class EventResource extends AbstractResource
{
    protected $_fields = array(
        'name',
        'description',
        'department',
        'tag',
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

    public function __construct()
    {
        $this->_model = Mage::getModel('catalog/category');
    }

    /**
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
            switch($when) {
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
            }
        }

        if ($tag = $this->_request->getQueryParam('tag')) {
            $filters['tags'] = array('in' => explode(',', $tag));
        }

        if ($age = $this->_request->getQueryParam('age')) {
            $filters['ages'] = array('in' => explode(',', $age));
        }

        if ($department = $this->_request->getQueryParam('department')) {
            $filters['departments'] = array('in' => explode(',', $department));
        }

        return $this->getCollection($filters);
    }

    /**
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
     * Add formatted fields to item data before deferring to the default
     * item formatting.
     *
     * @param $item array
     * @param $fields null|array
     * @param $links null|array
     * @return array
     */
    protected function _formatItem(array $item, $fields = NULL, $links = NULL)
    {
        $imageBaseUrl = 'http://' . API_WEB_URL . '/media/catalog/category/';

        $item['department'] = isset($item['departments'])
            ? explode(',', $item['departments'])
            : null;
        $item['tag'] = isset($item['tags'])
            ? explode(',', $item['tags'])
            : null;
        $item['age'] = isset($item['ages'])
            ? explode(',', $item['ages'])
            : null;

        if (isset($item['image'])) {
            $item['default_image'] = $item['image'];
        }
        $item['image'] = array();
        if (isset($item['default_image'])) {
            $item['image']['default'] = $imageBaseUrl . $item['default_image'];
        }
        if (isset($item['small_image'])) {
            $item['image']['small'] = $imageBaseUrl . $item['small_image'];
        }
        if (isset($item['thumbnail'])) {
            $item['image']['thumbnail'] = $imageBaseUrl . $item['thumbnail'];
        }
        if (isset($item['logo'])) {
            $item['image']['logo'] = $imageBaseUrl . $item['logo'];
        }

        return parent::_formatItem($item, $fields, $links);
    }
}
