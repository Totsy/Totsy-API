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

    protected $_cachePrefix = 'REST_API_PRODUCT_';

    protected $_cacheRefreshFrequency = 10;

    protected $_modelGroupName = 'catalog/product';

    protected $_fields = array(
        'name',
        'description',
        'short_description',
        'shipping_returns',
        'department',
        'age',
        'attributes',
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
        'type',
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
        array(
            'rel' => 'alternate',
            'href' => '{$web_base_url}/{$url_key}.html'
        )
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
        $event   = $product->getCategoryCollection()->getFirstItem();

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
            $results[] = $this->_formatItem($item, $this->_fields, $this->_links);
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
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $sourceData    = $item->getData();
        $formattedData = array();

        $imageBaseUrl = trim(Mage::getBaseUrl(), '/')
            . '/media/catalog/product';

        $formattedData['event_id'] = $this->_eventId;

        $formattedData['shipping_returns'] = trim(
            strip_tags(html_entity_decode($item->getShippingReturns()))
        );

        // scrape together department & age data
        $departments = $item->getAttributeText('departments');
        $formattedData['department'] = $departments ?
            (array) $departments
            : array();

        $ages = $item->getAttributeText('ages');
        $formattedData['age'] = $ages ? (array) $ages : array();

        $formattedData['hot'] = isset($item['hot_list'])
            && $item['hot_list'];
        $formattedData['featured'] = isset($item['featured'])
            && $item['featured'];

        $formattedData['image'] = array();
        foreach ($item['media_gallery']['images'] as $image) {
            $formattedData['image'][] = $imageBaseUrl . $image['file'];
        }

        if ('configurable' == $item->getTypeId()) {
            $formattedData['attributes'] = array();

            // unsure why the item needs to be reloaded, but strange attribute
            // values appear from $item
            $product = Mage::getModel('catalog/product')->load($item->getId());
            $productAttrs = $product->getTypeInstance()
                ->getConfigurableAttributesAsArray();

            foreach ($productAttrs as $attr) {
                $formattedData['attributes'][$attr['label']] = array();
                foreach ($attr['values'] as $attrVal) {
                    $formattedData['attributes'][$attr['label']][] = $attrVal['label'];
                }
            }
        }

        $productUrl = \Mage::getBaseUrl() . $sourceData['url_key'] . '.html';
        if (is_array($links)) {
            foreach ($links as &$link) {
                if ('alternate' == $link['rel']) {
                    $link['href'] = $productUrl;
                }
            }
        }

        $formattedData['type'] = $item->getTypeId();

        $item->addData($formattedData);
        return parent::_formatItem($item, $fields, $links);
    }
}
