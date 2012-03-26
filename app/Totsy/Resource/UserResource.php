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
    Sonno\Annotation\Path,
    Sonno\Annotation\Consumes,
    Sonno\Annotation\Produces,
    Sonno\Annotation\Context,
    Sonno\Annotation\PathParam,
    Sonno\Application\WebApplicationException,
    Sonno\Http\Response\Response,

    Mage;

/**
 * A system User represents an account that enables the holder to perform
 * certain activities, including purchasing Products.
 */
class UserResource extends AbstractResource
{
    protected $_modelGroupName = 'customer/customer';

    protected $_fields = array(
        'email',
        'firstname',
        'lastname',
    );

    protected $_links = array(
        array(
            'rel' => 'self',
            'href' => '/user/{entity_id}'
        ),
        array(
            'rel' => 'http://rel.totsy.com/collection/address',
            'href' => '/user/{entity_id}/address'
        ),
        array(
            'rel' => 'http://rel.totsy.com/collection/order',
            'href' => '/user/{entity_id}/order'
        ),
        array(
            'rel' => 'http://rel.totsy.com/collection/reward',
            'href' => '/user/{entity_id}/reward'
        ),
        array(
            'rel' => 'http://rel.totsy.com/collection/creditcard',
            'href' => '/user/{entity_id}/creditcard'
        ),
    );

    /**
     * Add a new User to the system.
     *
     * @POST
     * @Path("/user")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     */
    public function createUserEntity()
    {
        $user = Mage::getModel($this->_modelGroupName);
        $this->_populateModelInstance($user);

        return json_encode(
            $this->_formatItem($user->getData(), $this->_fields, $this->_links)
        );
    }

    /**
     * A single system User instance.
     *
     * @GET
     * @Path("/user/{id}")
     * @Produces({"application/json"})
     * @PathParam("id")
     */
    public function getUserEntity($id)
    {
        $user = self::authorizeUser($id);

        return json_encode(
            $this->_formatItem($user->getData(), $this->_fields, $this->_links)
        );
    }

    /**
     * Update the record of an existing system User.
     *
     * @PUT
     * @Path("/user/{id}")
     * @Produces({"application/json"})
     * @Consumes({"application/json"})
     * @PathParam("id")
     */
    public function updateUserEntity($id)
    {
        $user = self::authorizeUser($id);
        $this->_populateModelInstance($user);

        return json_encode(
            $this->_formatItem($user->getData(), $this->_fields, $this->_links)
        );
    }

    /**
     * Verify that an incoming request is logged in for a specific user.
     *
     * @param $user_id int The user ID that the request is made on behalf of.
     * @return Mage_Customer_Model_Customer
     * @throws Sonno\Application\WebApplicationException
     */
    public static function authorizeUser($user_id)
    {
        $session = Mage::getSingleton('customer/session');

        if ($session->isLoggedIn()) {
            // verify that the logged-in user is also the user requested
            $user = $session->getCustomer();
            if ($user->getId() !== $user_id) {
                throw new WebApplicationException(403);
            }

            return $user;
        } else {
            throw new WebApplicationException(403);
        }
    }
}
