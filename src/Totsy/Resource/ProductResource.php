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
            'price' => 'special_price',
            'orig'  => 'price'
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
        if ($response = $this->_inspectCache()) {
            return $response;
        }

        $product = $this->_model->load($id);
        $event   = $product->getCategoryCollection()->getFirstItem();

        if ($event) {
            $this->_eventId = $event->getId();
        }

        $result = $this->getItem($id);
        if ($response = $this->_addCache($result, $product->getCacheTags(), 3600)) {
            return $response;
        }

        return $result;
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
        if ($response = $this->_inspectCache()) {
            return $response;
        }

        $this->_eventId = $id;

        $model = Mage::getModel('catalog/category');
        $event = $model->load($id);

        $layer = Mage::getSingleton('catalog/layer');
        $layer->setCurrentCategory($event);
        $products = $layer->getProductCollection()->addAttributeToSelect(
            array(
                'description',
                'shipping_returns',
                'vendor_style',
                'weight',
                'departments',
                'ages',
                'hot_list',
                'featured',
                'color',
                'size',
            )
        );

        $sortby = array_keys(
            Mage::getModel('catalog/config')->getAttributeUsedForSortByArray()
        );
        if (!empty($sortby)) {
            $products->addAttributeToSort($sortby[0]);
        }

        $results = array();
        $cacheTags = $event->getCacheTags() ?: array();
        foreach ($products as $i => $product) {
            if (!$product->isSalable()) {
                continue;
            }

            // this loads the media gallery to the product object
            // hat tip: http://www.magentocommerce.com/boards/viewthread/17414/P15/#t400258
            $attributes = $product->getTypeInstance(true)->getSetAttributes($product);
            $gallery = $attributes['media_gallery'];
            $gallery->getBackend()->afterLoad($product);

            $cacheTags = array_merge($cacheTags, $product->getCacheTags());
            $results[] = $this->_formatItem($product, $this->_fields, $this->_links);
        }

        $result = json_encode($results);
        $cacheTags = array_unique($cacheTags);
        $cacheTags = array_map('strtoupper', $cacheTags);
        if ($response = $this->_addCache($result, $cacheTags, 3600)) {
            return $response;
        }

        return $result;
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
            strip_tags(html_entity_decode($sourceData['shipping_returns']))
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

        // rewrite the 'shipping_returns' field with data from a CMS block
        $cmsBlockId = 'shipping_and_return';
        if ('virtual' == $item->getTypeId()) {
            $cmsBlockId .= '_virtual';
        }

        $block = \Mage::getModel('cms/block')->getCollection()
            ->addFieldToFilter('identifier', $cmsBlockId)
            ->getFirstItem();

        if (null != $block) {
            $formattedData['shipping_returns'] = trim(strip_tags($block->getContent()));
        }

        // setup 'attributes' object for configurable products
        if ('configurable' == $item->getTypeId()) {
            $formattedData['attributes'] = array();

            $productAttrs = $item->getTypeInstance()
                ->getConfigurableAttributesAsArray();

            foreach ($productAttrs as $attr) {
                $formattedData['attributes'][$attr['label']] = array();
            }

            $allProducts = $item->getTypeInstance(true)->getUsedProducts(null, $item);
            foreach ($allProducts as $product) {
                if ($product->isSalable()) {
                    foreach ($productAttrs as $attr) {
                        $attrLabel = $attr['label'];
                        $attrVal   = $product->getAttributeText($attr['attribute_code']);
                        if (!in_array($attrVal, $formattedData['attributes'][$attrLabel])) {
                            $formattedData['attributes'][$attrLabel][] = $attrVal;
                        }
                    }
                }
            }
        } else if ('simple' == $item->getTypeId()) {
            $formattedData['attributes'] = array();
            if ($value = $item->getAttributeText('color')) {
                $formattedData['attributes']['Color'] = $value;
            }
            if ($value = $item->getAttributeText('size')) {
                $formattedData['attributes']['Size'] = $value;
            }
        }

        // build the product's static web site page URL
        $now = Mage::getModel('core/date')->timestamp();
        $category = $item->getCategoryCollection()
            ->addAttributeToSelect('url_key')
            ->addAttributeToFilter('event_start_date', array('to' => $now, 'datetime' => true))
            ->addAttributeToFilter('event_end_date', array('from' => $now, 'datetime' => true))
            ->getFirstItem();

        $productUrl = Mage::getBaseUrl() .
            $category->getUrlKey() . '/' .
            $sourceData['url_key'] . '.html';

        $links[] = array(
            'rel' => 'alternate',
            'href' => $productUrl
        );

        $formattedData['type'] = $item->getTypeId();

        $item->addData($formattedData);

        return parent::_formatItem($item, $fields, $links);
    }
}
