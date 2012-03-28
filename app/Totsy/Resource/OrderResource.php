<?php

/**
 * @category   Totsy
 * @package    Totsy\Resource
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

namespace Totsy\Resource;

use Sonno\Annotation\GET,
    Sonno\Annotation\POST,
    Sonno\Annotation\PUT,
    Sonno\Annotation\DELETE,
    Sonno\Annotation\Path,
    Sonno\Annotation\Consumes,
    Sonno\Annotation\Produces,
    Sonno\Annotation\Context,
    Sonno\Annotation\PathParam,
    Sonno\Application\WebApplicationException,
    Sonno\Http\Response\Response,

    Mage;

/**
 * An Order is a collection of Product entities and their corresponding quantities that a User purchases.
 */
class OrderResource extends AbstractResource
{
    protected $_modelGroupName = 'sales/order';

    protected $_fields = array(
        'status',
        'created' => 'created_at',
        'updated' => 'updated_at',
        'coupon_code',
        'total_qty' => 'total_qty_ordered',
        'total_weight' => 'weight',
        'shipping' => 'shipping_amount',
        'tax' => 'tax_amount',
        'discount' => 'discount_amount',
        'subtotal',
        'total' => 'grand_total',
        'products',
        'payment',
        'addresses',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/order/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/entity/user',
            'href' => '/user/{customer_id}'
        ),
    );

    /**
     * Retrieve the set of orders stored for a specific User.
     *
     * @GET
     * @Path("/user/{id}/order")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getUserOrders($id)
    {
        $user = UserResource::authorizeUser($id);

        return $this->getCollection(array('customer_id' => array('eq' => $id)));
    }

    /**
     * Add a new Order to the system.
     *
     * @POST
     * @Path("/user/{id}/order")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function createOrderEntity($id)
    {
        $user = UserResource::authorizeUser($id);

        $order = Mage::getModel($this->_modelGroupName);
        $order->setCustomerId($id);
        $this->_populateModelInstance($order);

        $response = $this->_formatItem($order);
        $responseBody = json_encode($response);

        return new Response(201,
            $responseBody,
            array('Location' => $response['links'][0]['href'])
        );
    }

    /**
     * A single Order instance.
     *
     * @GET
     * @Path("/order/{id}")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getOrderEntity($id)
    {
        $order = $this->_model->load($id);

        if ($order->isObjectNew()) {
            return new Response(404);
        }

        // ensure that the request is authorized for the address owner
        UserResource::authorizeUser($order->getCustomerId());

        return json_encode($this->_formatItem($order));
    }

    /**
     * Update the record of an existing Order.
     *
     * @PUT
     * @Path("/order/{id}")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function updateOrderEntity($id)
    {
        $order = $this->_model->load($id);

        if ($order->isObjectNew()) {
            return new Response(404);
        }

        // ensure that the request is authorized for the address owner
        UserResource::authorizeUser($order->getCustomerId());

        $this->_populateModelInstance($order);

        return json_encode($this->_formatItem($order));
    }

    /**
     * @param $item Mage_Core_Model_Abstract
     * @param $fields array|null
     * @param $links array|null
     * @return array
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $newData = array();

        // populate the "payment" property with credit card info and reward points redeemed
        $payment = $item->getPayment()->getData();
        $newData['payment'] = array(
            'reward_points_used' => ceil($item->getRewardCurrencyAmount()),
            'creditcard_type' => $payment['cc_type'],
            'creditcard_last4'   => $payment['cc_last4'],
            'creditcard_exp_month' => $payment['cc_exp_month'],
            'creditcard_exp_year' => $payment['cc_exp_year'],
        );

        // populate the "addresses" property with billing & shipping addresses
        $newData['addresses'] = array();

        $address = $item->getBillingAddress();
        if ($address) {
            $builder = $this->_uriInfo->getBaseUriBuilder();
            $builder->resourcePath('Totsy\Resource\AddressResource', 'getAddressEntity');

            $newData['addresses'][] = array(
                'type' => 'billing',
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/entity/address',
                        'href' => $builder->build(array($address->getId()))
                    )
                )
            );
        }

        $address = $item->getShippingAddress();
        if ($address) {
            $builder = $this->_uriInfo->getBaseUriBuilder();
            $builder->resourcePath('Totsy\Resource\AddressResource', 'getAddressEntity');

            $newData['addresses'][] = array(
                'type' => 'shipping',
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/entity/address',
                        'href' => $builder->build(array($address->getId()))
                    )
                )
            );
        }

        // populate the "items" property with products that are part of this order
        // @todo get the product options also
        $builder = $this->_uriInfo->getBaseUriBuilder();
        $builder->resourcePath('Totsy\Resource\ProductResource', 'getProductEntity');

        $products = $item->getItemsCollection();
        $newData['products'] = array();
        foreach ($products as $product) {
            $productData = $product->getData();
            $newData['products'][] = array(
                'name' => $productData['name'],
                'price' => $productData['price'],
                'qty' => $productData['qty_ordered'],
                'tax_pct' => $product['tax_percent'],
                'tax' => $productData['tax_amount'],
                'weight' => $productData['weight'],
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/entity/product',
                        'href' => $builder->build(array($productData['item_id']))
                    )
                )
            );
        }

        $item->addData($newData);
        return parent::_formatItem($item, $this->_fields, $this->_links);
    }
}
