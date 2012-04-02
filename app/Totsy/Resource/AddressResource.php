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
 * An Address represents a physical real-world location that is used to ship
 * products to, or as a billing address.
 */
class AddressResource extends AbstractResource
{
    /**
     * The user that an address belongs to.
     *
     * @var Mage_Customer_Model_Customer
     */
    protected $_user;

    protected $_modelGroupName = 'customer/address';

    protected $_fields = array(
        'firstname',
        'lastname',
        'company',
        'street',
        'city',
        'state' => 'region',
        'zip' => 'postcode',
        'country' => 'country_id',
        'telephone',
        'fax',
        'default_billing',
        'default_shipping',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/address/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/entity/user',
            'href' => '/user/{parent_id}'
        ),
    );

    /**
     * Retrieve the set of addresses stored for a specific User.
     *
     * @GET
     * @Path("/user/{id}/address")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getUserAddresses($id)
    {
        $this->_user = UserResource::authorizeUser($id);

        $addresses = $this->_user->getAddressesCollection();
        $results = array();
        foreach ($addresses as $address) {
            $results[] = $this->_formatItem($address);
        }

        return json_encode($results);
    }

    /**
     * Add a new Address to the system.
     *
     * @POST
     * @Path("/user/{id}/address")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function createAddressEntity($id)
    {
        $this->_user = UserResource::authorizeUser($id);

        $address = Mage::getModel($this->_modelGroupName);
        $address->setCustomerId($id);
        $this->_populateModelInstance($address);

        $response = $this->_formatItem($address);
        $responseBody = json_encode($response);

        return new Response(
            201,
            $responseBody,
            array('Location' => $response['links'][0]['href'])
        );
    }

    /**
     * A single Address instance.
     *
     * @GET
     * @Path("/address/{id}")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getAddressEntity($id)
    {
        $address = $this->_model->load($id);

        if ($address->isObjectNew()) {
            return new Response(404);
        }

        // ensure that the request is authorized for the address owner
        $this->_user = UserResource::authorizeUser($address->getCustomerId());

        return json_encode($this->_formatItem($address));
    }

    /**
     * Update the record of an existing Address.
     *
     * @PUT
     * @Path("/address/{id}")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function updateAddressEntity($id)
    {
        $address = $this->_model->load($id);

        if ($address->isObjectNew()) {
            return new Response(404);
        }

        // ensure that the request is authorized for the address owner
        $this->_user = UserResource::authorizeUser($address->getCustomerId());

        $this->_populateModelInstance($address);

        return json_encode($this->_formatItem($address));
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
