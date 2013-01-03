<?php
/**
 * @category    Totsy
 * @package     Totsy\Exception
 * @author      Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright   Copyright (c) 2012 Totsy LLC
 */

namespace Totsy\Exception;

use Sonno\Http\Response\Response;

/**
 * Totsy API application exceptions are thrown by API resource classes to force
 * an immediate HTTP response with a specified status and message.
 */
class WebApplicationException
    extends \Sonno\Application\WebApplicationException
{
    /**
     * Create a new Totsy API web application Exception.
     * This will create a new \Sonno\Http\Response\Response object with the
     * given status, and populate the 'X-API-Error' header with the message.
     *
     * @param int              $status  HTTP status code to respond to the
     *                                  request with.
     * @param string|Exception $message Response entity to respond to the
     *                                  request with.
     */
    public function __construct($status, $message = NULL)
    {
        $headers = array();

        if (is_string($message)) {
            $headers['X-Api-Error'] = preg_replace("/\n/", ' ', $message);
        } else if ($message instanceof \Exception) {
            $headers['X-Api-Error'] = preg_replace("/\n/", ' ', $message->getMessage());
        }

        $this->_response = new Response($status, '', $headers);
    }
}
