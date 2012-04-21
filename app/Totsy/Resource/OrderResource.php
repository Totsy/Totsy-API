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
    Sonno\Http\Response\Response,

    Totsy\Exception\WebApplicationException,

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
     * @Path("/user/{id}/order")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     *
     * @throws \Totsy\Exception\WebApplicationException
     * @return \Sonno\Http\Response\Response
     */
    public function createOrderEntity($id)
    {
        UserResource::authorizeUser($id);

        $requestData = json_decode($this->_request->getRequestBody(), true);
        if (is_null($requestData)) {
            throw new WebApplicationException(
                400,
                'Malformed entity representation in request body'
            );
        }

        // setup the countdown timer on the local session
        // this is required to ensure that the current local session cart can
        // be correctly evaluated for timeout/expiry
        Mage::getSingleton('checkout/session')->setCountDownTimer(
            $this->_getCurrentTime()
        );

        // retrieve the local session shopping cart
        $cart = Mage::getSingleton('checkout/cart');

        $this->_populateModelInstance($cart);

        Mage::getSingleton('checkout/session')->setCartWasUpdated(true)->getData();

        return new Response(202, json_encode($this->_formatCartItem($cart)));
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
     * @param \Mage_Checkout_Model_Cart $cart
     * @return array
     */
    protected function _formatCartItem(\Mage_Checkout_Model_Cart $cart)
    {
        $formattedData = array();

        $cartShelfLife = $cart->getQuoteItemExpireTime();
        $formattedData['expires'] = date(
            'c',
            strtotime('+' . $cartShelfLife . ' seconds')
        );

        $quoteData = $quote->getData();
        $formattedData['subtotal'] = $quoteData['grand_total'];

        $builder = $this->_uriInfo->getBaseUriBuilder();
        $builder->resourcePath(
            'Totsy\Resource\ProductResource',
            'getProductEntity'
        );

        $formattedData['products'] = array();
        $cartProducts = $quote->getItemsCollection();
        foreach ($cartProducts as $quoteItem) {
            if ('simple' == $quoteItem->getProductType()) {
                continue;
            }

            // compile the map of attributes on this product
            $product = $quoteItem->getProduct()->getTypeInstance();
            $attributes = $product->getSelectedAttributesInfo();
            $productAttributes = array();
            foreach ($attributes as $attr) {
                $productAttributes[$attr['label']] = $attr['value'];
            }

            $formattedData['products'][] = array(
                'name' => $quoteItem->getName(),
                'price' => $quoteItem->getPrice(),
                'qty' => $quoteItem->getQty(),
                'attributes' => $productAttributes,
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/entity/product',
                        'href' => $builder->build(array($quoteItem->getProductId()))
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
     * @throws \Totsy\Exception\WebApplicationException
     */
    protected function _populateModelInstance($obj, $data = NULL)
    {
        if (is_null($data)) {
            $data = json_decode($this->_request->getRequestBody(), true);
            if (is_null($data)) {
                throw new WebApplicationException(
                    400,
                    'Malformed entity representation in request body'
                );
            }
        }

        // add Product Items from request data to the shopping cart
        if (isset($data['products']) &&
            is_array($data['products']))
        {
            $cartUpdates = array();
            foreach ($data['products'] as $requestProduct) {
                // locate the Product ID in the Product URL
                $productUrl = $requestProduct['links'][0]['href'];
                $productIdOffset = strrpos($productUrl, '/');

                // referenced product not found
                if ($productIdOffset === false) {
                    if ($product->isObjectNew()) {
                        throw new WebApplicationException(
                            400,
                            "There is no Product Resource at URL $productUrl"
                        );
                    }
                }

                // fetch the Mage_Catalog_Model_Product instance for the
                // referenced product
                $productId = substr($productUrl, $productIdOffset+1);
                $product = Mage::getModel('catalog/product');
                $product->load($productId);

                // setup cart parameters for this product
                $productParams = array('qty' => $requestProduct['qty']);

                if (isset($requestProduct['attributes']) &&
                    count($requestProduct['attributes']) &&
                    'configurable' == $product->getTypeId()
                ) {
                    $productParams['super_attribute'] = array();

                    $productConfigAttributes = $product->getTypeInstance()
                        ->getConfigurableAttributesAsArray();

                    foreach ($productConfigAttributes as $attr) {
                        if (!isset($requestProduct['attributes'][$attr['label']])) {
                            throw new WebApplicationException(
                                400,
                                "Could not add Product $productUrl -- Missing attribute $attr[label]"
                            );
                        }

                        $reqAttrVal = $requestProduct['attributes'][$attr['label']];
                        $attrId = 0;
                        foreach ($attr['values'] as $attrVal) {
                            if ($reqAttrVal == $attrVal['label']) {
                                $attrId = $attrVal['value_index'];
                            }
                        }

                        $productParams['super_attribute'][$attr['attribute_id']] = $attrId;
                    }
                }

                $cartContainsProduct = false;
                if ($obj->getQuote()->hasProductId($product->getId())) {
                    // find the quote item for this product
                    $quoteItems = $obj->getQuote()->getItemsCollection();
                    foreach ($quoteItems as $quoteItemId => $quoteItem) {
                        if ($quoteItemAttrOption = $quoteItem->getOptionByCode('attributes')) {
                            $quoteItemAttrOption = unserialize($quoteItemAttrOption->getValue());
                            if ($quoteItemAttrOption == $productParams['super_attribute']) {
                                // add quantity updates for existing cart items
                                $cartUpdates[$quoteItemId] = array('qty' => $requestProduct['qty']);
                                $cartContainsProduct = true;
                            }
                        }
                    }
                }

                // add this product to the cart
                if (!$cartContainsProduct) {
                    $item = $obj->addProduct($product, $productParams);

                    if (is_string($item)) {
                        throw new WebApplicationException(
                            400,
                            "Could not add Product $productUrl -- $item"
                        );
                    }
                }
            }

            // process any cart updates
            if (count($cartUpdates)) {
                $obj->suggestItemsQty($cartUpdates);
                $obj->updateItems($cartUpdates);
            }
        }

        // "checkout" the local session shopping cart once payment & address
        // information is available in the request data
        if (isset($data['payment'])
            && isset($data['addresses'])
        ) {
            $quote = $obj->getQuote();

            // setup the Billing & Shipping address for this order
            foreach ($data['addresses'] as $type => $addressInfo) {
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
            $payment->addData($data['payment']);

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
        }

        try {
            $obj->save();
        } catch(\Mage_Core_Exception $mageException) {
            Mage::logException($mageException);
            throw new WebApplicationException(400, $mageException->getMessage());
        } catch(\Exception $e) {
            Mage::logException($e);
            throw new WebApplicationException(500, $e);
        }
    }

    /**
     * Get the current time in the Magento-configured local timezone.
     *
     * @return int
     */
    protected function _getCurrentTime()
    {
        // remember the currently configured timezone
        $defaultTimezone = date_default_timezone_get();

        // find Magento's configured timezone, and set that as the date timezone
        date_default_timezone_set(
            Mage::getStoreConfig(
                \Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE
            )
        );

        $time = now();

        // return the default timezone to the originally configured one
        date_default_timezone_set($defaultTimezone);

        return strtotime($time);
    }
}
