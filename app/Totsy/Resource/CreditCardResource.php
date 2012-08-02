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
 * A Credit Card represents a payment method whose information is saved by the
 * system for the user for future purchases.
 */
class CreditCardResource extends AbstractResource
{
    /**
     * The user that a credit card belongs to.
     *
     * @var Mage_Customer_Model_Customer
     */
    protected $_user;

    protected $_modelGroupName = 'paymentfactory/profile';

    protected $_fields = array(
        'type'         => 'card_type',
        'cc_last4'     => 'last4no',
        'cc_exp_year'  => 'expire_year',
        'cc_exp_month' => 'expire_month',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/creditcard/{entity_id}'
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

        $creditCards = $this->_model->loadByCustomerId($id);
        $results = array();
        foreach ($creditCards as $card) {
            $results[] = $this->_formatItem($card);
        }

        return json_encode($results);
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

        // setup some default data for creating a payment profile
        $data['saved_by_customer'] = 1;
        $data['email'] = $this->_user->getEmail();
        $data['cc_type'] = $data['type'];
        unset($data['type']);


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

        try {
            Mage::getModel('paymentfactory/tokenize')->createProfile(
                new \Varien_Object($data),
                new \Varien_Object($customerAddress->getData()),
                $id,
                $customerAddress->getId()
            );
        } catch (\Mage_Core_Exception $mageException) {
            Mage::logException($mageException);
            throw new WebApplicationException(500, $mageException->getMessage());
        } catch (\Exception $e) {
            throw new WebApplicationException(500, $e->getMessage());
        }

        $card = Mage::getModel($this->_modelGroupName)->loadByCcNumberWithId(
            $data['cc_number'] . $id . $data['cc_exp_year'] . $data['cc_exp_month']
        );

        $response = $this->_formatItem($card);

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
            return new Response(404);
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
            return new Response(404);
        }

        // ensure that the request is authorized for the address owner
        $this->_user = UserResource::authorizeUser($card->getCustomerId());

        try {
            $card->delete();
        } catch(\Exception $e) {
            Mage::logException($e);
            throw new WebApplicationException(500, $e->getMessage());
        }

        return new Response(200);
    }
}
