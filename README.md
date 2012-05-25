Totsy REST API Server
=====================

The Totsy REST API server implementation is a PHP web application built atop the open-source REST web service framework [Sonno](http://sonno.360i.com) and the [Magento](http://www.magentocommerce.com) framework.

The application expects two environment variables set in the environment (typically provided in the web server configuration):
1. `API_ENV` is the application environment: dev | stg | prod
2. `MAGENTO_ROOT` is the base path to the Magento web application root.

Dependencies
------------

1. Magento: The path to a local Magento installation must be supplied in a `$MAGENTO_ROOT` environment variable.

2. Sonno: Configured as a Git submodule in lib/vendor/sonno.

3. Doctrine-Common: Configured as a Git submodule in lib/vendor/doctrine-common.

4. APC module (PHP): A full copy of the parsed Configuration data is stored in a local APC cache, on all environments except for *dev*.

Documentation
-------------

Reference documentation can be found at https://api.totsy.com/doc/ref/Usage.md and a Web Application Description Language (WADL) specification is located at https://api.totsy.com/doc/wadl/.

To-Do (Future)
--------------
* Add a domain model layer to decouple Magento models from the API server. Use Symfony DI to configure and instantiate classes.
* Cache responses on Event & Product resources for performance reasons. On APC for now, potentially move to Memcached in the future.
