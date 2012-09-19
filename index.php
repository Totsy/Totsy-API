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

// allow requests from any origin
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization');

/**
 * Setup autoloaders for the API application, and other application settings as
 * global constants.
 */
require_once 'app/autoload.php';

define('API_ENV', getenv('API_ENV') ? getenv('API_ENV') : 'dev');
define('APC_CONFIG_KEY', 'api_config');

/**
 * Bootstrap the Magento environment.
 */

$mageRoot = '/' . trim(getenv('MAGENTO_ROOT'), '/');
if (!is_dir($mageRoot)) {
    throw new Exception("Could not find Magento installation at $mageRoot.");
}

// determine the Magento store to used by inspecting the request's user-agent
$code = '';
if (isset($_SERVER['HTTP_USER_AGENT']) &&
    preg_match('/iPhone/', $_SERVER['HTTP_USER_AGENT'])
) {
    $code = 'iphone';
}
if (isset($_SERVER['HTTP_USER_AGENT']) &&
    preg_match('/Android/', $_SERVER['HTTP_USER_AGENT'])
) {
    $code = 'android';
}

require_once "$mageRoot/app/Mage.php";
Mage::app($code);
if ('dev' === API_ENV) {
    Mage::setIsDeveloperMode(true);
}

/**
 * Authorize the incoming request.
 */

function deny()
{
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Totsy REST API"');
    exit;
}

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorization = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
    if (count($authorization) != 2 || 'Basic' != $authorization[0]) {
        deny();
    }

    $credentials = explode(':', base64_decode($authorization[1]));
    if (count($credentials) != 2 ||
        !Mage::getSingleton('api/user')->authenticate($credentials[0], $credentials[1])
    ) {
        deny();
    }
} else {
    deny();
}

/**
 * Construct a Sonno Configuration object.
 */

// inspect the APC cache for configuration first
if ('dev' !== API_ENV && extension_loaded('apc') && apc_exists(APC_CONFIG_KEY)) {
    $config = apc_fetch(APC_CONFIG_KEY);

// build a new Configuration object using Doctrine Annotations
} else {
    $doctrineReader = new AnnotationReader();
    AnnotationRegistry::registerAutoloadNamespace(
        'Sonno\Annotation',
        __DIR__ . '/lib/vendor/sonno/src'
    );

    $annotationReader = new DoctrineReader($doctrineReader);
    $resources = array(
        'Totsy\Resource\RootResource',
        'Totsy\Resource\EventResource',
        'Totsy\Resource\ProductResource',
        'Totsy\Resource\AuthResource',
        'Totsy\Resource\UserResource',
        'Totsy\Resource\AddressResource',
        'Totsy\Resource\CreditCardResource',
        'Totsy\Resource\OrderResource',
    );

    $driver = new AnnotationDriver($resources, $annotationReader);
    $config = $driver->parseConfig();

    if ('dev' !== API_ENV && extension_loaded('apc')) {
        apc_add(APC_CONFIG_KEY, $config);
    }
}

header("X-API-Environment: " . API_ENV);
header("X-API-Version: " . substr(exec('git rev-parse --verify HEAD'), 0, 8));

/**
 * Run a Sonno application!
 */

$application = new Application($config);
$application->run(Request::getInstanceOfCurrentRequest());
