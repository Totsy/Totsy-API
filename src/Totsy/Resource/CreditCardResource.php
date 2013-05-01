<?php

/**
 * @category   Totsy
 * @package    Totsy\Resource
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

namespace Totsy\Resource;

require_once \Mage::getBaseDir('code') . '/community/Litle/LitleSDK/LitleOnline.php';

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
 * A Credit Card represents a payment method whose information is saved by the
 * system for the user for future purchases.
 */
class CreditCardResource extends AbstractResource
{
    /**
     * The user that a credit card belongs to.
     *
     * @var \Mage_Customer_Model_Customer
     */
    protected $_user;

    protected $_modelGroupName = 'palorus/vault';

    protected $_fields = array(
        'type'         => 'type',
        'cc_last4'     => 'last4',
        'cc_exp_year'  => 'expiration_year',
        'cc_exp_month' => 'expiration_month',
        'address',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/creditcard/{vault_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/entity/user',
            'href' => '/user/{customer_id}'
        ),
    );

    /**
     * Retrieve the set of credit cards stored for a specific User.
     *
     * @GET
     * @Path("/user/{id}/creditcard")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getUserCreditCards($id)
    {
        $this->_user = UserResource::authorizeUser($id);

        // collect litle vault credit cards
        $vaults = json_decode($this->getCollection(array('customer_id' => $id)), true);

        // collect legacy cybersource credit card profiles
        $profiles = Mage::getModel('paymentfactory/profile')->getCollection()
            ->addFieldToFilter('customer_id', $id);

        // format cybersource profiles into the new litle format expected
        $formattedProfiles = array();
        foreach ($profiles as $profile) {
            $profile['type'] = $profile['card_type'];
            $profile['last4'] = $profile['last4no'];
            $profile['expiration_year'] = $profile['expire_year'];
            $profile['expiration_month'] = $profile['expire_month'];
            $profile['vault_id'] = $profile['subscription_id'];

            $formattedProfiles[] = $this->_formatItem($profile);
        }

        return json_encode(array_merge($vaults, $formattedProfiles));
    }

    /**
     * Add a new Credit Card to the system.
     *
     * @POST
     * @Path("/user/{id}/creditcard")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function createEntity($id)
    {
        $this->_user = UserResource::authorizeUser($id);

        $data = json_decode($this->_request->getRequestBody(), true);
        if (is_null($data)) {
            throw new WebApplicationException(
                400,
                'Malformed entity representation in request body'
            );
        }

        /** @var $customerAddress \Mage_Customer_Model_Address */
        $customerAddress = Mage::getModel('customer/address');
        if (isset($data['links'])) {
            // fetch the billing address by URL
            $addressId = $this->_getEntityIdFromUrl($data['links'][0]['href']);
            $customerAddress->load($addressId);

        } else if (isset($data['address'])) {
            // create a new billing address
            $address = $data['address'];
            $address['region'] = $address['state'];
            $address['postcode'] = $address['zip'];
            $address['country_id'] = $address['country'];

            // locate the region identifier for the supplied region
            $region = Mage::getModel('directory/region');
            $region->loadByName($address['state'], $address['country']);
            if ($region->isObjectNew()) {
                $region->loadByCode($address['state'], $address['country']);
            }
            $address['region_id'] = $region->getId();

            $customerAddress->addData($address)
                ->setCustomerId($id)
                ->save();
        }

        $expDate = str_pad($data['cc_exp_month'], 2, '0', STR_PAD_LEFT)
            . substr($data['cc_exp_year'], -2);

        $authData = array(
            'orderId'     => $this->_user->getId(),
            'amount'      => 100,
            'orderSource' => 'ecommerce',
            'billToAddress' => array(
                'name'    => $customerAddress->getFirstname() . ' ' . $customerAddress->getLastname(),
                'city'    => $customerAddress->getCity(),
                'state'   => $customerAddress->getRegion(),
                'zip'     => $customerAddress->getPostcode(),
                'country' => 'US'
            ),
            'card' => array(
                'number'  => $data['cc_number'],
                'expDate' => $expDate,
                'cardValidationNum' => $data['cc_cid'],
                'type' => 'AE' == $data['type'] ? 'AX' : $data['type']
            )
        );

        $street = (array) $customerAddress->getStreet();
        foreach ($street as $lineNumber => $streetName) {
            $authData['billToAddress']['addressLine' . ($lineNumber+1)] = $streetName;
        }

        $request = new \LitleOnlineRequest();
        $authResponse = $request->authorizationRequest($authData);

        $response = \XmlParser::getNode($authResponse, 'response');
        $message = \XmlParser::getNode($authResponse, 'message');
        $transactionId =  \XmlParser::getNode($authResponse, 'litleTxnId');

        if ($response != '000') {
            if ($message) {
                throw new WebApplicationException(400, $message);
            } else {
                throw new WebApplicationException(500);
            }
        }

        if (empty($transactionId)) {
            $authData['card']['number'] = str_repeat('X', 12) . substr($authData['card']['number'], -4);
            $this->_logger->err("Received an empty transaction ID from Litle Online", array('request' => $authData, 'response' => $authResponse->saveXML()));
            throw new WebApplicationException(500, "Received an empty transaction ID from Litle Online");
        }

        if ('VI' != $authData['card']['type']) {
            $authReversalData = array(
                'litleTxnId' => $transactionId,
                'amount'     => 100
            );
            $request = new \LitleOnlineRequest();
            $request->authReversalRequest($authReversalData);
        }

        $vault  = Mage::getModel($this->_modelGroupName);

        try {
            $exists = Mage::getModel('palorus/vault')->getCustomerToken(
                $this->_user,
                Mage::getModel('Litle_CreditCard_Model_PaymentLogic')->getUpdater($authResponse, 'tokenResponse', 'litleToken')
            );

            if ($exists) {
                $vault->load(Mage::getModel('Litle_CreditCard_Model_PaymentLogic')->getUpdater($authResponse, 'tokenResponse', 'litleToken'), 'token');
            } else {
                $vault->setData('token', Mage::getModel('Litle_CreditCard_Model_PaymentLogic')->getUpdater($authResponse, 'tokenResponse', 'litleToken'))
                    ->setData('bin', Mage::getModel('Litle_CreditCard_Model_PaymentLogic')->getUpdater($authResponse, 'tokenResponse', 'bin'))
                    ->setData('customer_id', $this->_user->getId())
                    ->setData('type', $data['type'])
                    ->setData('last4', substr($data['cc_number'], -4))
                    ->setData('expiration_month', $data['cc_exp_month'])
                    ->setData('expiration_year', $data['cc_exp_year'])
                    ->setData('is_visible', '1')
                    ->setData('address_id', $customerAddress->getId())
                    ->save();
            }
        } catch (\Exception $e) {
            $this->_logger->err($e->getMessage(), $e->getTrace());
            throw new WebApplicationException(500, $e->getMessage());
        }

        $response = $this->_formatItem($vault);

        return new Response(
            201,
            json_encode($response),
            array('Location' => $response['links'][0]['href'])
        );
    }

    /**
     * A single Credit Card instance.
     *
     * @GET
     * @Path("/creditcard/{id}")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getEntity($id)
    {
        $card = $this->_model->load($id);

        if ($card->isObjectNew()) {
            $card = Mage::getModel('paymentfactory/profile')->load($id, 'subscription_id');
            if ($card->isObjectNew()) {
                return new Response(404);
            }

            $card['type'] = $card['card_type'];
            $card['last4'] = $card['last4no'];
            $card['expiration_year'] = $card['expire_year'];
            $card['expiration_month'] = $card['expire_month'];
            $card['vault_id'] = $card['subscription_id'];
        }

        // ensure that the request is authorized for the card owner
        $this->_user = UserResource::authorizeUser($card->getCustomerId());

        return json_encode($this->_formatItem($card));
    }

    /**
     * Delete the record of an existing Credit Card.
     *
     * @DELETE
     * @Path("/creditcard/{id}")
     * @Produces({"*\/*"})
     * @PathParam("id")
     */
    public function deleteEntity($id)
    {
        $card = $this->_model->load($id);

        if ($card->isObjectNew()) {
            $card = Mage::getModel('paymentfactory/profile')->load($id, 'subscription_id');
            if ($card->isObjectNew()) {
                return new Response(404);
            }
        }

        // ensure that the request is authorized for the address owner
        $this->_user = UserResource::authorizeUser($card->getCustomerId());

        try {
            $card->delete();
        } catch(\Exception $e) {
            $this->_logger->err($e->getMessage(), $e->getTrace());
            throw new WebApplicationException(500, $e->getMessage());
        }

        return new Response(200);
    }

    /**
     * Add address information to item data.
     *
     * @param \Mage_Core_Model_Abstract $item
     * @param null                      $fields
     * @param null                      $links
     *
     * @return array
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        if ($addressId = $item->getAddressId()) {
            $resource = new AddressResource();
            $resource->setRequest($this->_request);
            $resource->setUriInfo($this->_uriInfo);

            $address = Mage::getModel('customer/address')->load($addressId);
            $address = json_decode($resource->formatItem($address), true);

            unset($address['links']);
            unset($address['default_billing']);
            unset($address['default_shipping']);

            $item->setData('address', $address);
        }

        return parent::_formatItem($item, $fields, $links);
    }
}
