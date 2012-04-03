<?php

/**
 * @category   Totsy
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

use Doctrine\Common\ClassLoader;
require 'Doctrine/Common/ClassLoader.php';

$classLoader = new ClassLoader(
    'Totsy',
    __DIR__
);
$classLoader->register();
unset($classLoader);

$sonnoLoader = new ClassLoader(
    'Sonno',
    __DIR__ . '/../lib/sonno/src'
);
$sonnoLoader->register();
unset($sonnoLoader);

$commonLoader = new ClassLoader(
    'Doctrine\Common'
);
$commonLoader->register();
unset($commonLoader);
