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

define('APC_CONFIG_KEY', 'api_config');

/**
 * Authorize the incoming request.
 */

if (isset($_SERVER['HTTP_AUTHORIZATION']) &&
    $authToken = $_SERVER['HTTP_AUTHORIZATION']
) {
    $db = new SQLite3(__DIR__ . '/db/api.db');
    $result = $db->querySingle(
        "SELECT id FROM client WHERE authorization = '$authToken'"
    );

    if (!$result) {
        header('HTTP/1.0 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="Totsy API"');
        exit;
    } else {
        $now = date('Y-m-d H:i:s');
        $db->exec("UPDATE client SET last_request='$now' WHERE id = $result");
    }
} else {
    header('HTTP/1.0 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Totsy API"');
    exit;
}

/**
 * Bootstrap the Magento environment.
 */

$mageRoot = '/' . trim(getenv('MAGENTO_ROOT'), '/');
if (!is_dir($mageRoot)) {
    throw new Exception("Invalid $mageRoot environment variable!");
}

require_once "$mageRoot/app/Mage.php";
Mage::app();
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
        __DIR__ . '/lib/sonno/src'
    );

    $annotationReader = new DoctrineReader($doctrineReader);
    $resources = array(
        'Totsy\Resource\RootResource',
        'Totsy\Resource\EventResource',
        'Totsy\Resource\ProductResource',
        'Totsy\Resource\AuthResource',
        'Totsy\Resource\UserResource',
        'Totsy\Resource\AddressResource',
        'Totsy\Resource\OrderResource',
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
