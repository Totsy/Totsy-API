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
        'blurb'  => 'description',
        'start'  => 'event_start_date',
        'end'    => 'event_end_date',
        'department',
        'category',
        'age',
        'image',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/event/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/product',
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
        $item['department'] = explode(',', $item['departments']);
        $item['age'] = explode(',', $item['ages']);

        $item['image'] = array(
            'med' => "http://magento.totsy.com/media/product/category/$item[image]"
        );

        return parent::_formatItem($item, $fields, $links);
    }
}
