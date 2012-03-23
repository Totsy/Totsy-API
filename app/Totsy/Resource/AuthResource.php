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
    Sonno\Annotation\Produces;

/**
 * The root resource is the default resource.
 * @Path("/auth")
 */
class AuthResource extends AbstractResource
{
    public function __construct()
    {
    }

    /**
     * @POST
     * @Consumes({"application/json"})
     * @Produces({"application/json"})
     */
    public function login()
    {
    }

    /**
     * @DELETE
     * @Path("{id}")
     */
    public function logout()
    {
    }
}
