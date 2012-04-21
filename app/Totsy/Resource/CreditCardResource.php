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
        'testdata',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/creditcard/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/entity/creditcard',
            'href' => '/user/{parent_id}'
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
            print_r($card->getData());
            //$results[] = $this->_formatItem($card);
        }
exit;
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

        $card = Mage::getModel($this->_modelGroupName);
        $card->setCustomerId($id);
        $this->_populateModelInstance($card);

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

        // ensure that the request is authorized for the address owner
        $this->_user = UserResource::authorizeUser($card->getCustomerId());

        return json_encode($this->_formatItem($card));
    }

    /**
     * Update the record of an existing Credit Card.
     *
     * @PUT
     * @Path("/creditcard/{id}")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function updateEntity($id)
    {
        $card = $this->_model->load($id);

        if ($card->isObjectNew()) {
            return new Response(404);
        }

        // ensure that the request is authorized for the address owner
        $this->_user = UserResource::authorizeUser($card->getCustomerId());

        $this->_populateModelInstance($card);

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

    /**
     * @param $item Mage_Core_Model_Abstract
     * @param $fields array|null
     * @param $links array|null
     * @return array
     */
    protected function _formatItem($item, $fields = NULL, $links = NULL)
    {
        $userData = $this->_user->getData();

        $item->setData(
            'default_billing',
            isset($userData['default_billing'])&& $userData['default_billing'] == $item->getId()
        );

        $item->setData(
            'default_shipping',
            isset($userData['default_shipping']) && $userData['default_shipping'] == $item->getId()
        );

        $item->setData('parent_id', $userData['entity_id']);

        return parent::_formatItem($item, $fields, $links);
    }

    /**
     * Populate a Magento model object with an array of data, and persist the
     * updated object.
     *
     * @param $obj Mage_Core_Model_Abstract
     * @param $data array The data to populate, or NULL which will use the
     *                    incoming request data.
     * @return bool
     * @throws Totsy\Exception\WebApplicationException
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

        // locate the region identifier for the supplied region
        $region = Mage::getModel('directory/region');
        $region->loadByName($data['state'], $data['country']);
        if ($region->isObjectNew()) {
            $region->loadByCode($data['state'], $data['country']);
        }
        $obj->setRegionId($region->getId());

        // the region value supplied could not be found
        if ($region->isObjectNew()) {
            $errorMessage = "Entity Validation Error: Invalid value in 'state'"
                . " field.";
            $e = new WebApplicationException(400);
            $e->getResponse()->setHeaders(
                array('X-API-Error' => $errorMessage)
            );
            throw $e;
        }

        // save the address object
        parent::_populateModelInstance($obj, $data);

        // update the Customer object with default address settings
        if (isset($data['default_billing'])) {
            $this->_user->setData(
                'default_billing',
                $data['default_billing'] ? $obj->getId() : null
            );
        }

        if (isset($data['default_shipping'])) {
            $this->_user->setData(
                'default_shipping',
                $data['default_shipping'] ? $obj->getId() : null
            );
        }

        $this->_user->save();
    }
}
