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
 * An Order is a collection of Product entities and their corresponding
 * quantities that a User purchases.
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
     * @PUT
     * @Path("/user/{id}/order")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function createOrderEntity($id)
    {
        UserResource::authorizeUser($id);

        $requestData = json_decode($this->_request->getRequestBody(), true);
        if (is_null($requestData)) {
            $error = 'Malformed entity representation in request body';
            $e = new WebApplicationException(400);
            $e->getResponse()->setHeaders(
                array('X-API-Error' => $error)
            );
            throw $e;
        }

        // retrieve the local session shopping cart
        $cart  = Mage::getModel('checkout/session');
        $quote = $cart->getQuote();

        // add Product Items from request data to the shopping cart
        if (isset($requestData['products']) &&
            is_array($requestData['products']))
        {
            foreach ($requestData['products'] as $requestProduct) {
                // locate the Product ID in the Product URL
                $productUrl = $requestProduct['links'][0]['href'];
                if (($offset = strrpos($productUrl, '/')) !== FALSE) {
                    $productId = substr($productUrl, $offset+1);
                    $product = Mage::getModel('catalog/product');
                    $product->load($productId);

                    $quote->addProduct($product, $requestProduct['qty']);
                }
            }
        }

        // "checkout" the local session shopping cart once payment & address
        // information is available in the request data
        if (isset($requestData['payment'])
            && isset($requestData['addresses'])
        ) {
            // setup the Billing & Shipping address for this order
            foreach ($requestData['addresses'] as $type => $addressInfo) {
                $address = Mage::getModel('customer/address');

                if (isset($addressInfo['links'])) {
                    // fetch existing address
                    $addressUrl = $addressInfo['links'][0]['href'];
                    if (($offset = strrpos($addressUrl, '/')) !== FALSE) {
                        $addressId = substr($addressUrl, $offset+1);
                        $address->load($addressId);
                    }
                } else {
                    // create new address using address info
                    $address->addData($addressInfo);
                }

                $quoteAddress = Mage::getModel('sales/quote_address');
                $quoteAddress->importCustomerAddress($address);
                if ('shipping' == $type) {
                    $quote->setShippingAddress($quoteAddress);
                } else if ('billing' == $type) {
                    $quote->setBillingAddress($quoteAddress);
                }
            }

            // setup the Payment for this order
            $payment = Mage::getModel('sales/quote_payment');
            $payment->addData($requestData['payment']);

            $quote->addPayment($payment);
            $quote->save();

            // create the new order!
            $quoteService = Mage::getModel('sales/service_quote', $quote);
            $quoteService->submitAll();
            $order = $quoteService->getOrder();

            return new Response(
                201,
                json_encode($this->_formatOrderItem($order))
            );
        } else {
            $quote->save();
            return new Response(
                202,
                json_encode($this->_formatCartItem($cart))
            );
        }
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
     * @param $item Mage_Core_Model_Abstract
     * @param $fields array|null
     * @param $links array|null
     * @return array
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $newData = array();

        // construct a 'payment' object that includes credit card information
        // used as well as any points redeemed
        $payment = $item->getPayment();
        $newData['payment'] = array(
            'reward_points_used' => ceil($item->getRewardCurrencyAmount()),
            'creditcard_type' => $payment->getCcType(),
            'creditcard_last4'   => $payment->getCcLast4(),
            'creditcard_exp_month' => $payment->getCcExpMonth(),
            'creditcard_exp_year' => $payment->getCcExpYear(),
        );

        // construct a 'addresses' property with billing & shipping addresses
        $newData['addresses'] = array();

        $address = $item->getBillingAddress();
        if ($address) {
            $builder = $this->_uriInfo->getBaseUriBuilder();
            $builder->resourcePath(
                'Totsy\Resource\AddressResource',
                'getAddressEntity'
            );

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
            $builder->resourcePath(
                'Totsy\Resource\AddressResource',
                'getAddressEntity'
            );

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

        // construct a 'products' property with products that were line items
        // in this order
        $builder = $this->_uriInfo->getBaseUriBuilder();
        $builder->resourcePath(
            'Totsy\Resource\ProductResource',
            'getProductEntity'
        );

        $products = $item->getAllVisibleItems();
        $newData['products'] = array();
        foreach ($products as $productItem) {
            $product = Mage::getModel('catalog/product');
            $product->load($productItem->getProductId());
            $productData = $product->getData();

            $newData['products'][] = array(
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'qty' => $productItem->getQtyOrdered(),
                'weight' => $product->getWeight(),
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/entity/product',
                        'href' => $builder->build(array($product->getId()))
                    )
                )
            );
        }

        $item->addData($newData);

        return parent::_formatItem($item, $this->_fields, $this->_links);
    }

    /**
     * Format a shopping cart entity into an array for output.
     *
     * @param \Mage_Checkout_Model_Session $cart
     * @return array
     */
    protected function _formatCartItem(\Mage_Checkout_Model_Session $cart)
    {
        $formattedData = array();

        $quote = $cart->getQuote();

        $cartShelfLife = $cart->getQuoteItemExpireTime();
        $formattedData['expires'] = date(
            'c',
            strtotime("+900 seconds")
        );

        $quoteData = $quote->getData();
        $formattedData['total'] = $quoteData['grand_total'];

        $builder = $this->_uriInfo->getBaseUriBuilder();
        $builder->resourcePath(
            'Totsy\Resource\ProductResource',
            'getProductEntity'
        );

        $formattedData['products'] = array();
        $cartProducts = $quote->getItemsCollection();
        foreach ($cartProducts as $quoteItem) {
            $product = $quoteItem->getProduct();
            $productData = $product->getData();

            $formattedData['products'][] = array(
                'name' => $productData['name'],
                'price' => $productData['price'],
                'qty' => $quoteItem->getQty(),
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/entity/product',
                        'href' => $builder->build(array($product->getId()))
                    )
                )
            );
        }

        return $formattedData;
    }

    /**
     * Populate a Magento model object with an array of data, and persist the
     * updated object.
     *
     * @param $obj Mage_Core_Model_Abstract
     * @param $data array The data to populate, or NULL which will use the
     *                    incoming request data.
     * @return bool
     * @throws Sonno\Application\WebApplicationException
     */
    protected function _populateModelInstance($obj, $data = NULL)
    {
        if (is_null($data)) {
            $data = json_decode($this->_request->getRequestBody(), true);
            if (is_null($data)) {
                $error = 'Malformed entity representation in request body';
                $e = new WebApplicationException(400);
                $e->getResponse()->setHeaders(
                    array('X-API-Error' => $error)
                );
                throw $e;
            }
        }

        if (isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $productData) {
                // locate the Product ID in the Product URL
                $productUrl = $productData['links'][0]['href'];
                if (($offset = strrpos($productUrl, '/')) !== FALSE) {
                    $productId = substr($productUrl, $offset+1);
                    $product = Mage::getModel('catalog/product')->load($productId);

                    $obj->addProduct($product, $productData['qty']);
                }
            }
        }

        parent::_populateModelInstance($obj, $data);
    }
}
