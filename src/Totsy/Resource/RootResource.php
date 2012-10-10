<?php

/**
 * @category   Totsy
 * @package    Totsy\Resource
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

namespace Totsy\Resource;

use Sonno\Annotation\GET,
    Sonno\Annotation\Path,
    Sonno\Annotation\Produces,
    Sonno\Annotation\Context,
    Sonno\Annotation\PathParam;

/**
 * The root resource is the default resource.
 */
class RootResource extends AbstractResource
{
    /**
     * @GET
     * @Path("/")
     * @Produces({"application/json"})
     */
    public function root()
    {
        $links = array(
            array(
                'rel' => 'http://rel.totsy.com/entity/auth',
                'resource' => array(
                    'class' => 'Totsy\Resource\AuthResource',
                    'method' => 'login'
                )
            ),
            array(
                'rel' => 'http://rel.totsy.com/collection/event',
                'resource' => array(
                    'class' => 'Totsy\Resource\EventResource',
                    'method' => 'getEventCollection'
                )
            ),
        );

        return json_encode($this->_formatItem(null, null, $links));
    }
}
