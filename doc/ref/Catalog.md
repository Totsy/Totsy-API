# Totsy REST API Server: Catalog Resources #

Events and Products are a read-only interface to the Totsy product catalog.

### Retrieve Events ###
`GET /event` responds with a collection of Events. The event information contained in this collection includes a link [`rel=http://rel.totsy.com/collection/product`] to a collection of Products that are part of the event.

### Retrieve Products ###
`GET /event/123/product` responds with a collection of Products that are part of some event.
`GET /product/567` responds with a single Product.

### Retrieve Product Quantity ###
`GET /product/567/quantity` responds with the current quantity of a product.
