<?php

/**
 * @category   Totsy
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

use Sonno\Configuration\Driver\AnnotationDriver,
    Sonno\Annotation\Reader\DoctrineReader,
    Sonno\Application\Application,
    Sonno\Http\Request\Request,
    Doctrine\Common\Annotations\AnnotationReader,
    Doctrine\Common\Annotations\AnnotationRegistry;

/**
 * Setup autoloaders for the API application, and other application settings as
 * global constants.
 */
require_once 'app/autoload.php';

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
 * Bootstrap the Magento environment.
 */

require_once '../app/Mage.php';
Mage::app('default');
if ('dev' === API_ENV) {
    Mage::setIsDeveloperMode(true);
}

/**
 * Construct a Sonno Configuration object.
 */

// inspect the APC cache for configuration first
if ('dev' !== API_ENV && apc_exists(APC_CONFIG_KEY)) {
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
        'Totsy\Resource\AddressResource',
    );

    $driver = new AnnotationDriver($resources, $annotationReader);
    $config = $driver->parseConfig();

    if ('dev' !== API_ENV) {
        apc_add(APC_CONFIG_KEY, $config);
    }
}

/**
 * Run a Sonno application!
 */

$application = new Application($config);
$application->run(Request::getInstanceOfCurrentRequest());
