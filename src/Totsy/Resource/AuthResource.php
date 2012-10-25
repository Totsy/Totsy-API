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
    Sonno\Http\Response\Response,

    Totsy\Exception\WebApplicationException;

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
        $request = json_decode($this->_request->getRequestBody(), true);

        if (!$this->_model->isLoggedIn()) {
            if (isset($request['email']) && isset($request['password'])) {
                try {
                    $this->_model->login($request['email'], $request['password']);
                } catch(\Mage_Core_Exception $e) {
                    throw new WebApplicationException(403, 'Invalid login credentials.');
                }
            } else if (isset($request['facebook_access_token'])) {
                // login as Facebook user
                $fbSession = \Mage::getModel('inchoo_facebook/session');
                $fbClient  = $fbSession->getClient();
                $fbClient->setAccessToken($request['facebook_access_token']);

                // search for an existing customer with this facebook_uid
                try {
                    $fbUser   = $fbClient->graph('/me');
                } catch (\Mage_Core_Exception $e) {
                    throw new WebApplicationException(403, 'Invalid login credentials.');
                }

                $customer = \Mage::getModel('customer/customer')->getCollection()
                    ->addAttributeToFilter('facebook_uid', $fbUser['id'])
                    ->addAttributeToFilter('store_id', \Mage::app()->getStore()->getId())
                    ->getFirstItem();

                // fallback to searching by e-mail
                if (!$customer || !$customer->getId()) {
                    $customer = \Mage::getModel('customer/customer')->getCollection()
                        ->addAttributeToFilter('email', $fbUser['email'])
                        ->addAttributeToFilter('store_id', \Mage::app()->getStore()->getId())
                        ->getFirstItem();
                }

                // create a new customer because one does not exist for this
                // Facebook user
                if (!$customer || !$customer->getId()) {
                    $customerData = array(
                        'facebook_uid' => $fbUser['id'],
                        'firstname'    => $fbUser['first_name'],
                        'lastname'     => $fbUser['last_name'],
                        'email'        => $fbUser['email'],
                    );

                    $customer = \Mage::getModel('customer/customer');
                    $randomPassword = $customer->generatePassword(8);

                    try {
                        $customer->setData($customerData)
                            ->setPassword($randomPassword)
                            ->setConfirmation($randomPassword)
                            ->save();

                        $customer->sendNewAccountEmail('confirmed');
                    } catch(\Mage_Core_Exception $e) {
                        $this->_logger->err($e->getMessage());
                        return new Response(
                            500,
                            null,
                            $e->getMessage()
                        );
                    }
                }

                $this->_model->setCustomerAsLoggedIn($customer);
            } else {
                throw new WebApplicationException(403, 'Invalid login credentials.');
            }
        }

        $user = $this->_model->getCustomer();
        return json_encode($this->_formatItem($user, null, $this->_links));
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

        return new Response(204);
    }
}
