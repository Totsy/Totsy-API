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
    Sonno\Http\Response\Response,

    Mage;

/**
 * A Product is a single item that is available for sale.
 */
class ProductResource extends AbstractResource
{
    /**
     * The unique identifier for the Event that a product belongs to.
     * This instance variable is set by a resource method, and referred to in
     * _formatItem to setup the event ID for URI template substitution.
     *
     * @var int
     */
    protected $_eventId;

    protected $_modelGroupName = 'catalog/product';

    protected $_fields = array(
        'name',
        'description' => 'short_description',
        'department',
        'category',
        'age',
        'vendor_style',
        'sku',
        'weight',
        'price' => array(
            'price',
            'orig' => 'original_price',
            'msrp'
        ),
        'hot',
        'featured',
        'image',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/product/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/entity/event',
            'href' => '/event/{event_id}'
        ),
    );

    /**
     * A single Product instance.
     *
     * @GET
     * @Path("/product/{id}")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getProductEntity($id)
    {
        $product = $this->_model->load($id);

        $event = $product->getCategoryCollection()->getFirstItem();
        if ($event) {
            $this->_eventId = $event->getId();
        }

        return $this->getItem($id);
    }

    /**
     * The available quantity of a single product.
     *
     * @GET
     * @Path("/product/{id}/quantity")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getProductQuantity($id)
    {
        $product = $this->_model->load($id);

        if ($product->isObjectNew()) {
            return new Response(404);
        }

        return json_encode(
            array('quantity' => $product->getStockItem()->getStockQty())
        );
    }

    /**
     * Products that are part of an Event.
     * 
     * @GET
     * @Path("/event/{id}/product")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getEventProductCollection($id)
    {
        $this->_eventId = $id;

        $model = Mage::getModel('catalog/category');
        $event = $model->load($id);
        $products = $event->getProductCollection();

        $results = array();
        foreach ($products as $product) {
            $item = $this->_model->load($product->getId());
            $results[] = $this->_formatItem($item->getData());
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
     * @return array
     */
    protected function _formatItem(array $item, $fields = NULL, $links = NULL)
    {
        $imageBaseUrl = 'http://' . API_WEB_URL . '/media/catalog/product';

        $item['event_id'] = $this->_eventId;

        $item['department'] = isset($item['departments'])
            ? explode(',', $item['departments'])
            : null;
        $item['category'] = isset($item['categories'])
            ? explode(',', $item['categories'])
            : null;
        $item['age'] = isset($item['ages'])
            ? explode(',', $item['ages'])
            : null;

        $item['hot'] = isset($item['hot_list']) && $item['hot_list'];
        $item['featured'] = isset($item['featured']) && $item['featured'];

        $item['image'] = array();
        foreach ($item['media_gallery']['images'] as $image) {
            $item['image'][] = $imageBaseUrl . $image['file'];
        }

        return parent::_formatItem($item, $fields, $links);
    }
}
