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
    Sonno\Http\Response\Response,
    Totsy\Exception\WebApplicationException,

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
     * A collection of Products.
     *
     * @GET
     * @Path("/product")
     * @Produces({"application/json"})
     */
    public function getProductCollection()
    {
        // only respond to requests that query by slug
        $slug = $this->_request->getQueryParam('slug');
        if (empty($slug)) {
            throw new WebApplicationException(400, "No 'slug' query parameter supplied.");
        }

        /** @var $rewrite \Mage_Core_Model_Url_Rewrite */
        $rewrite = Mage::getModel('core/url_rewrite')
            ->setStoreId(Mage::app()->getStore()->getId())
            ->loadByRequestPath($slug);

        if (!$rewrite || !$rewrite->getId()) {
            return new Response(200, '[]');
        }

        $targetPath = explode('/', $rewrite->getTargetPath());
        $this->_eventId = $targetPath[6];

        // wrap the result in an array to return a collection of entities
        $result = json_decode($this->getItem($targetPath[4]), true);
        $result = array($result);
        $result = json_encode($result);

        return new Response(200, $result);
     }

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
            $response->setHeaders(array('Cache-Control' => 'max-age=3600'));
            return $response;
        }

        $product = $this->_model->load($id);
        $event   = $product->getCategoryCollection()->getFirstItem();

        if ($event) {
            $this->_eventId = $event->getId();
        }

        $result = $this->getItem($id);
        $this->_addCache($result, $product->getCacheIdTags());

        return new Response(200, $result, array('Cache-Control' => 'max-age=3600'));
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
            $response->setHeaders(array('Cache-Control' => 'max-age=3600'));
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
        $cache = Mage::app()->getCacheInstance();
        $cacheTags = $event->getCacheIdTags() ?: array();
        foreach ($products as $product) {
            // first look for a cached version of this lone item
            $cacheKey = 'RESTAPI_PRODUCT_ITEM_' . $product->getId();
            if ($formattedItem = $cache->load($cacheKey)) {
                $results[] = json_decode($formattedItem);
                continue;
            }

            if (!$product->isSalable()) {
                continue;
            }

            // this loads the media gallery to the product object
            // hat tip: http://www.magentocommerce.com/boards/viewthread/17414/P15/#t400258
            $attributes = $product->getTypeInstance(true)->getSetAttributes($product);
            $gallery = $attributes['media_gallery'];
            $gallery->getBackend()->afterLoad($product);

            $cacheTags = array_merge($cacheTags, $product->getCacheIdTags());

            $formattedItem = $this->_formatItem($product, $this->_fields, $this->_links);;
            $results[] = $formattedItem;

            $cache->save(json_encode($formattedItem), $cacheKey, $product->getCacheIdTags());
        }

        $result = json_encode($results);
        $cacheTags = array_unique($cacheTags);
        $cacheTags = array_map('strtoupper', $cacheTags);
        $this->_addCache($result, $cacheTags);

        return new Response(200, $result, array('Cache-Control' => 'max-age=3600'));
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
