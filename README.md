Totsy REST API Server
=====================

The Totsy REST API server implementation is a PHP web application built atop the open-source REST web service framework [Sonno](http://sonno.360i.com) and the [Magento](http://www.magentocommerce.com) e-commerce platform.

The application expects two environment variables set (typically provided in the web server configuration):

1. `API_ENV` is the application environment: dev | stg | prod

2. `MAGENTO_ROOT` is the base path to the Magento web application root.

Dependencies
------------

The project uses PHP [Composer](http://www.getcomposer.com) for dependency management. Specific dependencies can be found in the `package.json` file.

However, the only other dependency of the project, outside the scope of composer, is a Magento installation. The path to a local Magento installation must be supplied in a `$MAGENTO_ROOT` environment variable.

Documentation
-------------

All documentation is located in the `doc` directory.

Reference documentation (usage instructions) are located at `doc/ref/`.

The official Web Application Description Language (WADL) specification document is located at `doc/wadl/totsy.wadl`. A human-readable version of this document can be generated from this WADL file using the `doc/wadl/totsy_wadl_doc-2006-10.xsl` XSL stylesheet.

To-Do (Future)
--------------

* Add a suite of integration tests using [Guzzle](http://guzzlephp.org) and unit tests using the EcomDev_PHPUnit module.
* Add analytics.
