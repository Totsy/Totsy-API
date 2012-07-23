Totsy REST API Server
=====================

The Totsy REST API server implementation is a PHP web application built atop the open-source REST web service framework [Sonno](http://sonno.360i.com) and the [Magento](http://www.magentocommerce.com) e-commerce platform.

The application expects two environment variables set in the environment (typically provided in the web server configuration):
1. `API_ENV` is the application environment: dev | stg | prod
2. `MAGENTO_ROOT` is the base path to the Magento web application root.

Dependencies
------------

1. Magento: The path to a local Magento installation must be supplied in a `$MAGENTO_ROOT` environment variable.

2. Sonno: Configured as a Git submodule in `lib/vendor/sonno`.

3. Doctrine-Common: Configured as a Git submodule in `lib/vendor/doctrine-common`.

4. APC module (PHP): A full copy of the parsed Configuration data is stored in a local APC cache, on all environments except for *dev*.

5. Memcached (PHP): If available, response bodies will be stored in Memcached. Server settings are configured via file `etc/{API_ENV}/memcached.yaml`. In the absence of this file, the local APC cache will be used instead.

Documentation
-------------

All documentation is located in the `doc` directory.

Reference documentation (usage instructions) is located at `doc/ref/Usage.md`.

The official Web Application Description Language (WADL) specification document is located at `doc/wadl/totsy.wadl`. A human-readable version of this document can be generated from this WADL file using the `doc/wadl/totsy_wadl_doc-2006-10.xsl` XSL stylesheet.

To-Do (Future)
--------------

* Add a domain model layer to decouple Magento models from the API server. Use Symfony DI to configure and instantiate classes.
* Add logging using Monolog.
* Integrate Magento Web Service Role ACL for resource access.

