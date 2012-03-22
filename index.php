<?php

/**
 * @category   Totsy
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

require_once '../app/Mage.php';
require_once 'app/autoload.php';

Mage::app();

use Sonno\Configuration\Driver\AnnotationDriver,
    Sonno\Annotation\Reader\DoctrineReader,
    Sonno\Application\Application,
    Sonno\Http\Request\Request,
    Doctrine\Common\Annotations\AnnotationReader,
    Doctrine\Common\Annotations\AnnotationRegistry;

define('APC_CONFIG_KEY', 'api_config');

// inspect the APC cache for configuration first
if (apc_exists(APC_CONFIG_KEY)) {
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
    );

    $driver = new AnnotationDriver($resources, $annotationReader);
    $config = $driver->parseConfig();

    apc_add(APC_CONFIG_KEY, $config);
}

// run the Sonno application
$application = new Application($config);
$application->run(Request::getInstanceOfCurrentRequest());
