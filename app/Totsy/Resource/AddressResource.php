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
        $user = UserResource::authorizeUser($id);

        $addresses = $user->getAddressesCollection();
        $results = array();
        foreach ($addresses as $address) {
            $results[] = $this->_formatItem($address->getData());
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
        $user = UserResource::authorizeUser($id);

        $address = Mage::getModel($this->_modelGroupName);
        $address->setCustomerId($id);
        $this->_populateModelInstance($address);

        $response = $this->_formatItem(
            $address->getData(),
            $this->_fields,
            $this->_links
        );
        $responseBody = json_encode($response);

        return new Response(201,
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
        UserResource::authorizeUser($address->getCustomerId());

        return json_encode(
            $this->_formatItem(
                $address->getData(),
                $this->_fields,
                $this->_links
            )
        );
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
        UserResource::authorizeUser($address->getCustomerId());

        $this->_populateModelInstance($address);

        return json_encode(
            $this->_formatItem(
                $address->getData(),
                $this->_fields,
                $this->_links
            )
        );
    }
}
