<?php

/**
 * @category   Totsy
 * @author     Tharsan Bhuvanendran <tbhuvanendran@totsy.com>
 * @copyright  Copyright (c) 2012 Totsy LLC
 */

use Doctrine\Common\ClassLoader;

$classLoader = new ClassLoader('Totsy', __DIR__);
$classLoader->register();
unset($classLoader);
