<?php

namespace Totsy\Resource;

use Sonno\Annotation\GET,
    Sonno\Annotation\Path,
    Sonno\Annotation\Produces,
    Sonno\Annotation\Context,
    Sonno\Annotation\PathParam,

    Mage;

/**
 * A Product is a single item that is available for sale.
 */
class ProductResource extends AbstractResource
{
    protected $_event;

    protected $_fields = array(
        'name',
        'description',
        'department',
        'category',
        'age',
        'vendor_style',
        'sku',
        'price' => array(
            'price',
            'orig' => 'original_price',
            'msrp'
        ),
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/product/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/event',
            'href' => '/event/{event_id}'
        ),
    );

    public function __construct()
    {
        $this->_model = Mage::getModel('catalog/product');
    }

    /**
     * @GET
     * @Path("/product")
     * @Produces({"application/json"})
     */
    public function getProductCollection()
    {
        return $this->getCollection();
    }

    /**
     * @GET
     * @Path("/product/{id}")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getProductEntity($id)
    {
        return $this->getItem($id);
    }

    /**
     * @GET
     * @Path("/product/{id}/quantity")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getProductQuantity($id)
    {

    }

    /**
     * @GET
     * @Path("/event/{id}/product")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getEventProductCollection($id)
    {
        $model = Mage::getModel('catalog/category');
        $this->_event = $model->load($id);
        $products = $this->_event->getProductCollection();

        $results = array();
        foreach ($products as $product) {
            $item = $this->_model->load($product->getId());
            $results[] = $this->_formatItem($item->getData());
            // print_r($item->getData());
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
        $item['event_id'] = $this->_event->getId();
        $item['department'] = explode(',', $this->_event->getDepartments());
        $item['age'] = explode(',', $this->_event->getAges());
        $item['category'] = explode(',', $this->_event->getCategories());

        return parent::_formatItem($item, $fields, $links);
    }
}
