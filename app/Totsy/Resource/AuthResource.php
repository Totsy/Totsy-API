<?php

/**
 * @category   Totsy
 * @package    Totsy\Resource
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

namespace Totsy\Resource;

use Sonno\Annotation\POST,
    Sonno\Annotation\DELETE,
    Sonno\Annotation\Path,
    Sonno\Annotation\Consumes,
    Sonno\Annotation\Produces,
    Sonno\Http\Response\Response;

/**
 * A user authorization is the analog to logging into the system on behalf
 * of an existing system User in order to perform sensitive operations on
 * their behalf.
 *
 * @Path("/auth")
 */
class AuthResource extends AbstractResource
{
    protected $_modelGroupName = 'customer/session';

    protected $_links = array(
        array(
            'rel' => 'http://rel.totsy.com/entity/user',
            'href' => '/user/{entity_id}'
        ),
    );

    /**
     * Login to the system on behalf of an existing system User.
     *
     * @POST
     * @Consumes({"application/json"})
     * @Produces({"application/json"})
     */
    public function login()
    {
        $request = json_decode($this->_request->getRequestBody());

        if (!$this->_model->isLoggedIn()) {
            try {
                $this->_model->login($request->email, $request->password);
            } catch(\Mage_Core_Exception $e) {
                return new Response(
                    403,
                    null,
                    array('X-API-Error' => 'Invalid login credentials.')
                );
            }
        }

        $user = $this->_model->getCustomer();
        return json_encode(
            $this->_formatItem($user, null, $this->_links)
        );
    }

    /**
     * Destroy an existing user session and log the system User out of the
     * system.
     *
     * @DELETE
     * @Produces({"*\/*"})
     */
    public function logout()
    {
        $this->_model->logout();
    }
}
