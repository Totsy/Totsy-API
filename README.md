Totsy REST API Server
=====================

The Totsy REST API server implementation is a PHP web application built atop the open-source REST web service framework [Sonno](http://sonno.360i.com) and the [Magento](http://www.magentocommerce.com) framework.

The application expects two environment variables set in the environment (typically provided in the web server configuration):
1. `API_ENV` is the application environment: dev | stg | prod
2. `API_WEB_URL` is the base URL for static web assets.

The application stores a copy of the full Configuration in a local APC cache (on all environments except for *dev*).
The server uses a local SQLite3 database for storing client credentials.

Dependencies
------------

1. Magento: This project belongs inside the `$MAGENTO_ROOT` directory.

2. Sonno: A working copy of Sonno is expected in `$MAGENTO_ROOT/lib/sonno`.

3. Doctrine-Common: Must be installed and available on the default PHP include path (installation via PEAR is the easiest method).

4. SQLite3 module (PHP)

Documentation
-------------

Reference documentation can be found in `doc/README.md` and a Web Application Description Language (WADL) specification is located in `doc/wadl/`.

To-Do (Future)
--------------
* Add a domain model layer to decouple Magento models from the API server.

