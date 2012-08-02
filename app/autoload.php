<?php

/**
 * @category   Totsy
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

require 'lib/vendor/doctrine-common/lib/Doctrine/Common/ClassLoader.php';
use Doctrine\Common\ClassLoader;

$classLoader = new ClassLoader('Totsy', __DIR__);
$classLoader->register();
unset($classLoader);

$sonnoLoader = new ClassLoader('Sonno', __DIR__ . '/../lib/vendor/sonno/src');
$sonnoLoader->register();
unset($sonnoLoader);

$monologLoader = new ClassLoader(
    'Monolog',
    __DIR__ . '/../lib/vendor/monolog/src'
);
$monologLoader->register();
unset($monologLoader);

$commonLoader = new ClassLoader(
    'Doctrine\Common',
    __DIR__ . '/../lib/vendor/doctrine-common/lib'
);
$commonLoader->register();
unset($commonLoader);
