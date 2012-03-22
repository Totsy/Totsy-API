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
 *
 * @Path("/")
 */
class RootResource
{
    /**
     * @Context("UriInfo")
     */
    protected $_uriInfo;

    /**
     * @GET
     * @Produces({"application/json"})
     */
    public function root()
    {
        $builder = $this->_uriInfo->getAbsolutePathBuilder();

        return json_encode(
            array(
                'links' => array(
                    array(
                        'rel' => 'http://rel.totsy.com/user',
                        'href' => $builder->resourcePath(
                            'Totsy\Resource\UserResource'
                        )->build()
                    ),
                    array(
                        'rel' => 'http://rel.totsy.com/event',
                        'href' => $builder->resourcePath(
                            'Totsy\Resource\EventResource'
                        )->build()
                    )
                )
            )
        );
    }
}
