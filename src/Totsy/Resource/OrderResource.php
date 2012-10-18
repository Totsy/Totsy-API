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
        'number' => 'increment_id',
        'status',
        'created' => 'created_at',
        'updated' => 'updated_at',
        'coupon_code',
        'credit_redeemed' => 'reward_currency_amount',
        'total_qty' => 'total_qty_ordered',
        'total_weight' => 'weight',
        'shipping' => 'shipping_amount',
        'tax' => 'tax_amount',
        'discount' => 'discount_amount',
        'subtotal',
        'grand_total',
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
        UserResource::authorizeUser($id);

        return $this->getCollection(
            array(
                'customer_id' => array('eq' => $id),
                'status'      => array('nin' => array('splitted', 'updated'))
            ),
            'updated_at DESC'
        );
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

        $cart = Mage::getModel('checkout/cart');
        $quote = $cart->getQuote();

        $this->_populateModelInstance($cart);

        if (count($quote->getAllVisibleItems()) &&
            isset($requestData['payment'])
        ) {
            if (!$quote->isVirtual()) {
                $errors = $quote->getShippingAddress()->validate();
                if (is_array($errors)) {
                    throw new WebApplicationException(400, 'A valid shipping address must be specified.');
                }
            }

            if (!$quote->getGrandTotal()) {
                $errors = $quote->getBillingAddress()->validate();
                if (is_array($errors)) {
                    throw new WebApplicationException(400, 'A valid billing address must be specified.');
                }
            }

            // create the new order!
            try {
                $payment = $quote->getPayment();

                // when there is no balance to collect on the order, use
                // payment method 'free'
                $requestData['payment']['method'] = $quote->getGrandTotal()
                    ? 'paymentfactory_tokenize'
                    : 'free';

                // parse a saved credit card from the links collection
                if (isset($requestData['payment']['links'])) {
                    $ccUrl = $requestData['payment']['links'][0]['href'];
                    unset($requestData['payment']['links']);

                    $offset = strrpos($ccUrl, '/');
                    if ($offset === FALSE) {
                        throw new WebApplicationException(
                            400,
                            "Invalid Credit Card URI $ccUrl"
                        );
                    }

                    $ccId = substr($ccUrl, $offset + 1);
                    $cc = Mage::getModel('paymentfactory/profile')->load($ccId);
                    if (!$cc->getId()) {
                        throw new WebApplicationException(
                            409,
                            "Invalid Credit Card URI $ccUrl"
                        );
                    }

                    $subId = $cc->getEncryptedSubscriptionId();
                    $requestData['payment']['cybersource_subid'] = $subId;

                    $cardAddress = Mage::getModel('customer/address')
                        ->load($cc->getAddressId());
                    $quoteAddress = Mage::getModel('sales/quote_address')
                        ->importCustomerAddress($cardAddress);
                    $quote->setBillingAddress($quoteAddress);
                }

                $payment->importData($requestData['payment'])->save();

                $quoteService = Mage::getModel('sales/service_quote', $quote);
                $quoteService->submitAll();
                $order = $quoteService->getOrder();

                $response = $this->_formatItem($order);

                // destroy this cart object and reset the local checkout session
                $quote->setIsActive(false);
                $quote->delete();
                Mage::getModel('checkout/session')->clear();

                if ($order->getCanSendNewEmailFlag()) {
                    $order->sendNewOrderEmail();
                }

                return new Response(
                    201,
                    json_encode($response),
                    array('Location' => $response['links'][0]['href'])
                );
            } catch (\Exception $e) {
                $this->_logger->err($e->getMessage(), $e->getTrace());
                throw new WebApplicationException(500, $e->getMessage());
            }
        } else {
            return new Response(
                202,
                json_encode($this->_formatCartItem($quote))
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
            'cc_type'      => $payment->getCcType(),
            'cc_last4'     => $payment->getCcLast4(),
            'cc_exp_month' => $payment->getCcExpMonth(),
            'cc_exp_year'  => $payment->getCcExpYear(),
        );

        // construct a 'addresses' property with billing & shipping addresses
        $newData['addresses'] = array();

        $address = $item->getBillingAddress();
        if ($address) {
            $builder = $this->_uriInfo->getBaseUriBuilder();
            $builder->resourcePath(
                'Totsy\Resource\AddressResource',
                'getEntity'
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
                'getEntity'
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
                'price' => $product->getSpecialPrice(),
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
     * @param \Mage_Sales_Model_Quote $quote
     * @return array
     */
    protected function _formatCartItem(\Mage_Sales_Model_Quote $quote)
    {
        $formattedData = array();

        $cartProducts = $quote->getAllVisibleItems();
        if (!empty($cartProducts)) {
            $cartShelfLife = Mage::getConfig()->getStoresConfigByPath(
                'config/rushcheckout_timer/limit_timer'
            );
            $cartTime = Mage::getSingleton('checkout/session')
                ->getCountDownTimer();
            $formattedData['expires'] = $cartTime
                + $cartShelfLife[0]
                - Mage::getModel('core/date')->timestamp();
        }

        $quoteData = $quote->getData();

        if ($shippingAddress = $quote->getShippingAddress()) {
            $formattedData['shipping_amount'] = $shippingAddress['shipping_amount'];
            $formattedData['tax_amount'] = $shippingAddress['tax_amount'];
        }

        $formattedData['grand_total'] = $quoteData['grand_total'];
        $formattedData['subtotal'] = $quoteData['subtotal'];
        $formattedData['discount_amount'] = isset($quoteData['subtotal_with_discount']) &&
            $quoteData['subtotal_with_discount']
                ? $quoteData['subtotal'] - $quoteData['subtotal_with_discount']
                : 0;
        $formattedData['coupon_code'] = isset($quoteData['coupon_code']) && $quoteData['coupon_code']
            ? $quoteData['coupon_code']
            : null;
        $formattedData['use_credit'] = isset($quoteData['use_reward_points']) && $quoteData['use_reward_points']
            ? intval($quoteData['use_reward_points'])
            : 0;

        $totals = $quote->getTotals();
        $formattedData['credit_used'] = isset($totals['reward'])
            ? -1 * $totals['reward']->getValue()
            : 0;

        $builder = $this->_uriInfo->getBaseUriBuilder();
        $builder->resourcePath(
            'Totsy\Resource\ProductResource',
            'getProductEntity'
        );

        $estimatedShipping = Mage::helper('sales/order')->calculateEstimatedShipDate($quote);

        $formattedData['savings_amount'] = 0;
        $formattedData['products'] = array();
        foreach ($cartProducts as $quoteItem) {
            // ignore this quote item if it's a simple product with a parent
            if ('simple' == $quoteItem->getProductType() &&
                $quoteItem->getParentItemId()
            ) {
                continue;
            }

            $cartItemData = array(
                'name' => $quoteItem->getName(),
                'price' => $quoteItem->getPrice(),
                'qty' => $quoteItem->getQty(),
                'type' => $quoteItem->getProduct()->getTypeId(),
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/entity/product',
                        'href' => $builder->build(array($quoteItem->getProductId()))
                    )
                )
            );

            if ('configurable' == $quoteItem->getProductType()) {
                // compile the map of attributes on this product
                $product = $quoteItem->getProduct()->getTypeInstance();
                $attributes = $product->getSelectedAttributesInfo();
                $productAttributes = array();
                foreach ($attributes as $attr) {
                    $productAttributes[$attr['label']] = $attr['value'];
                }

                $cartItemData['attributes'] = $productAttributes;
            }

            if ('virtual' != $quoteItem->getProductType()) {
                $cartItemData['estimated_shipping'] = date(
                    'Y-m-d',
                    $estimatedShipping
                );
            }

            $formattedData['products'][] = $cartItemData;

            $product = $quoteItem->getProduct();
            $formattedData['savings_amount'] += $quoteItem->getQty()
                * ($product->getPrice() - $product->getSpecialPrice());
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

        $this->_updateProducts($obj, $data);
        $this->_updateAddress($obj, $data);

        $quote = $obj->getQuote();

        // set the toggle for using reward points/credits
        if (isset($data['use_credit'])) {
            $quote->setUseRewardPoints($data['use_credit']);
        }

        // update coupon information
        if (isset($data['coupon_code'])) {
            if (!$data['coupon_code']) {
                $data['coupon_code'] = 0;
            }

            $quote->setCouponCode($data['coupon_code']);
        }

        try {
            if ($shippingAddress = $quote->getShippingAddress()) {
                $shippingAddress->collectTotals();
            }

            $quote->collectTotals()->save();
            $obj->save();
        } catch(\Mage_Core_Exception $e) {
            $this->_logger->err($e->getMessage(), $e->getTrace());
            throw new WebApplicationException(400, $e->getMessage());
        } catch(\Exception $e) {
            $this->_logger->err($e->getMessage(), $e->getTrace());
            throw new WebApplicationException(500, $e->getMessage());
        }

        // setup the countdown timer on the local session
        // this is required to ensure that the current local session
        // cart can be correctly evaluated for timeout/expiry
        $now = Mage::getModel('core/date')->timestamp();
        Mage::getSingleton('checkout/session')->setCountDownTimer($now);
    }

    /**
     * Update an existing cart object with address information.
     *
     * @param Mage_Checkout_Model_Cart $obj
     * @param $data Request data containing address information.
     *
     * @return void
     */
    protected function _updateAddress($obj, $data)
    {
        if (isset($data['addresses']) && is_array($data['addresses'])) {
            $quote = $obj->getQuote();

            // setup the Billing & Shipping address for this order
            foreach ($data['addresses'] as $type => $addressInfo) {
                $address = Mage::getModel('customer/address');

                if (isset($addressInfo['links'])) {
                    // fetch existing address
                    $addressUrl = $addressInfo['links'][0]['href'];
                    if (($offset = strrpos($addressUrl, '/')) !== FALSE) {
                        $addressId = substr($addressUrl, $offset + 1);
                        $address->load($addressId);
                        if (!$address->getId()) {
                            throw new WebApplicationException(
                                400,
                                "Invalid Address URI $addressUrl"
                            );
                        }
                    }
                } else {
                    // create new address using address info
                    $address->addData($addressInfo);
                }

                $quoteAddress = Mage::getModel('sales/quote_address');
                $quoteAddress->importCustomerAddress($address);
                if ('shipping' == $type) {
                    $quote->setShippingAddress($quoteAddress);
                    $shippingAddress = $quote->getShippingAddress()
                        ->setCollectShippingRates(true)
                        ->collectShippingRates();

                    $shippingRates = $shippingAddress->getAllShippingRates();

                    // select the first shipping rate by default
                    $selectedRate = current($shippingRates);
                    $shippingAddress->setShippingMethod($selectedRate->getCode());

                    if ($quote->isVirtual()) {
                        $shippingAddress->setPaymentMethod('paymentfactory_tokenize');
                    }
                } else if ('billing' == $type) {
                    $quote->setBillingAddress($quoteAddress);

                    if (!$quote->isVirtual()) {
                        $quote->getBillingAddress()->setPaymentMethod('paymentfactory_tokenize');
                    }
                }
            }
        }
    }

    /**
     * Update an existing cart object with products.
     *
     * @param Mage_Checkout_Model_Cart $obj
     * @param $data Request data containing products.
     *
     * @return void
     */
    protected function _updateProducts($obj, $data)
    {
        if (isset($data['products']) && is_array($data['products'])) {
            $cartUpdates = array();
            $cartUpdated = false;
            foreach ($data['products'] as $requestProduct) {
                // locate the Product ID in the Product URL
                $productUrl = $requestProduct['links'][0]['href'];
                $productIdOffset = strrpos($productUrl, '/');

                // referenced product not found
                if ($productIdOffset === false) {
                    throw new WebApplicationException(
                        400,
                        "There is no Product Resource at URL $productUrl"
                    );
                }

                // fetch the Mage_Catalog_Model_Product instance for the
                // referenced product
                $productId = substr($productUrl, $productIdOffset + 1);
                $product = Mage::getModel('catalog/product');
                $product->load($productId);

                // referenced product not found
                if ($product->isObjectNew()) {
                    throw new WebApplicationException(
                        400,
                        "There is no Product Resource at URL $productUrl"
                    );
                }

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
                        $attrId = false;
                        foreach ($attr['values'] as $attrVal) {
                            if ($reqAttrVal == $attrVal['label']) {
                                $attrId = $attrVal['value_index'];
                            }
                        }

                        if (false === $attrId) {
                            throw new WebApplicationException(
                                400,
                                "Could not add Product $productUrl -- Attribute "
                                . "value '$reqAttrVal' is invalid for attribute '$attr[label]'"
                            );
                        }

                        $productParams['super_attribute'][$attr['attribute_id']] = $attrId;
                    }
                }

                $productQuoteItemId = false;
                if ($obj->getQuote()->hasProductId($product->getId())) {
                    // find the quote item for this product
                    if ('simple' == $product->getTypeId()) {
                        $productQuoteItemId = $obj->getQuote()
                            ->getItemByProduct($product)
                            ->getId();
                        $cartUpdates[$productQuoteItemId] = array(
                            'qty' => $requestProduct['qty']
                        );
                    } else if ('virtual' == $product->getTypeId()) {
                        // find the quote item by scanning through the quote
                        $found = false;
                        foreach ($obj->getQuote()->getAllItems() as $item) {
                            if ($product->getId() == $item->getProductId()) {
                                $found = $item;
                                $productQuoteItemId = $item->getId();
                            }
                        }

                        if (false !== $found && 0 == $requestProduct['qty']) {
                            $obj->removeItem($found->getId())->save();
                        } else if (false !== $found && $requestProduct['qty'] > 1) {
                            throw new WebApplicationException(
                                409,
                                "The quantity for a virtual product item cannot be modified."
                            );
                        }
                    } else {
                        $quoteItems = $obj->getQuote()->getItemsCollection();
                        foreach ($quoteItems as $quoteItemId => $quoteItem) {
                            if ($quoteItemAttrOption = $quoteItem->getOptionByCode('attributes')) {
                                $quoteItemAttrOption = unserialize($quoteItemAttrOption->getValue());
                                if ($quoteItemAttrOption == $productParams['super_attribute']) {
                                    // add quantity updates for existing cart items
                                    $cartUpdates[$quoteItemId] = array('qty' => $requestProduct['qty']);
                                    $productQuoteItemId = $quoteItemId;
                                }
                            }
                        }
                    }
                }

                // add this product to the cart
                if (false === $productQuoteItemId &&
                    $productParams['qty'] > 0
                ) {
                    try {
                        $item = $obj->addProduct($product, $productParams);
                        $cartUpdated = true;
                    } catch (\Mage_Core_Exception $e) {
                        throw new WebApplicationException(
                            400,
                            "Could not add Product $productUrl -- " . $e->getMessage()
                        );
                    }

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
                try {
                    $cartUpdates = $obj->suggestItemsQty($cartUpdates);
                    $obj->updateItems($cartUpdates)->save();
                    $cartUpdated = true;
                } catch(\Mage_Core_Exception $e) {
                    $this->_logger->info($e->getMessage(), $e->getTrace());
                    throw new WebApplicationException(409, $e->getMessage());
                } catch(\Exception $e) {
                    $this->_logger->err($e->getMessage(), $e->getTrace());
                    throw new WebApplicationException(500, $e->getMessage());
                }
            }

            Mage::getSingleton('checkout/session')->setCartWasUpdated($cartUpdated);
        }
    }
}
