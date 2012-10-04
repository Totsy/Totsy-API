# Totsy REST API Server: Catalog Resources #

Events and Products are a read-only interface to the Totsy product catalog.

### Retrieve Events ###
`GET /event` responds with a collection of Events. The event information contained in this collection includes a link [`rel=http://rel.totsy.com/collection/product`] to a collection of Products that are part of the event.

### Retrieve Products ###
`GET /event/123/product` responds with a collection of Products that are part of some event.
`GET /product/567` responds with a single Product.

### Product Attributes ###
There are three types of products. The type of product is specified in a product representation's `type` field.

When the `type` field has a value of `"configurable"`, then the `attributes` object will map attribute names to a flat array of possible attribute values. When adding these products to a cart, the `attributes` object must be present with the values.

When the `type` field has a value of `"simple"`, then the `attributes` object will map attribute names to a string attribute value. When adding these products to a cart, the `attributes` object must *not* be present.

When the `type` field has a value of `"virtual"`, then the `attributes` object will not be present (or null). When adding these products to a cart, the `attributes` object must *not* be present, and the quantity must be restricted to 1 at all times.

### Retrieve Product Quantity ###
`GET /product/567/quantity` responds with the current quantity of a product.
