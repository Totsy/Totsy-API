<?php

/**
 * @category   Totsy
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

require_once '../app/Mage.php';
require_once 'app/autoload.php';

Mage::app('default');

use Sonno\Configuration\Driver\AnnotationDriver,
    Sonno\Annotation\Reader\DoctrineReader,
    Sonno\Application\Application,
    Sonno\Http\Request\Request,
    Doctrine\Common\Annotations\AnnotationReader,
    Doctrine\Common\Annotations\AnnotationRegistry;

define(
    'API_ENV',
    getenv('API_ENV') ? getenv('API_ENV') : 'dev'
);
define(
    'API_WEB_URL',
    getenv('API_WEB_URL') ? getenv('API_WEB_URL') : $_SERVER['SERVER_NAME']
);

define('APC_CONFIG_KEY', 'api_config');

/**
 * Authorize the incoming request.
 *
 * @todo Store authorization identifiers in a persistence layer (sqlite)
 */

$authToken = $_SERVER['HTTP_AUTHORIZATION'];
if (!$authToken || '08c59d86-ec9b-4cfd-b783-71a51e718b65' != $authToken) {
    header('HTTP/1.0 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Totsy API"');
    exit;
}

/**
 * Construct a Sonno Configuration object.
 */

// inspect the APC cache for configuration first
if ('dev' !== getenv('API_ENV') && apc_exists(APC_CONFIG_KEY)) {
    $config = apc_fetch(APC_CONFIG_KEY);

// build a new Configuration object using Doctrine Annotations
} else {
    $doctrineReader = new AnnotationReader();
    AnnotationRegistry::registerAutoloadNamespace(
        'Sonno\Annotation',
        BP . DS . 'lib' . DS . 'sonno' . DS . 'src'
    );

    $annotationReader = new DoctrineReader($doctrineReader);
    $resources = array(
        'Totsy\Resource\RootResource',
        'Totsy\Resource\EventResource',
        'Totsy\Resource\ProductResource',
        'Totsy\Resource\AuthResource',
        'Totsy\Resource\UserResource',
    );

    $driver = new AnnotationDriver($resources, $annotationReader);
    $config = $driver->parseConfig();

    if ('dev' !== getenv('API_ENV')) {
        apc_add(APC_CONFIG_KEY, $config);
    }
}

/**
 * Run a Sonno application!
 */

$application = new Application($config);
$application->run(Request::getInstanceOfCurrentRequest());
