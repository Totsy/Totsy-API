# Totsy REST API Server: Shopping and Placing Orders #

A Totsy Order is defined by a set of products (and associated quantities), payment, and address information. In order for a new Order resource to be successfully created, it must contain all three of these critical pieces of information.

The process of creating a new order on Totsy using the REST API is typically performed in two different steps. Although an order can be created using a single HTTP POST request, the typical use case involves reserving products for a customer using a session shopping cart, and then finalizing that shopping cart by providing payment information.


## Creating and Populating a Cart ##

Product inventory can be reserved for a user using a `POST /user/345/order` request with a partial order representation containing only the `products` array. The server will respond with a `202 Accepted` response. An Order will not be created, but the requested products will be reserved in a server-side session shopping cart. This shopping cart does have a limited lifetime, indicated by the `expires` property in the server's response representation. This cart can be updated an unlimited number of times using the same `POST /user/345/order` with an updated `products` array, which also updates the cart's `expires` property.

### Using a coupon code ###

Submit a `POST /user/345/order` cart update request. Only the `coupon_code` field is necessary:

    {
        "coupon_code": "DISCOUNT"
    }

If the code supplied is valid, the server will respond with the usual `202 Accepted` status, and the code supplied will be reflected in the `coupon_code` field of the cart representation in the response.
However, if the code supplied is invalid, the server will respond with a `409 Conflict` status.

### Using credits ###

Submit a `POST /user/345/order` cart update request. Only the `use_credit` field is necessary:

    {
        "use_credit": 1
    }

The value of this field is boolean (1 or 0). The updated value of this field will be reflected in the `use_credit` field of the cart representation in the response, along with the `grand_total` field.

### Submitting Payment ###

Once the final pieces of information are added (specifically the `payment` and `addresses` object), an Order resource will be created and the server will respond with a `201 Created` response, along with a Location header specifying the URL for the newly created resource.

When the `grand_total` field of the cart representation is 0, an empty `payment` object will suffice to complete the order.


## Error Handling ##

Any order update (`PUT /user/345/order`) could possibly respond with a `409 Conflict` status in the following scenarios:
1. One or more of the items in the order are not available (out of stock).
2. The coupon code specified is invalid.
3. One of the resource URIs specified in the request are invalid.

When a `409 Conflict` status is returned, an `X-API-Error` response header will also be supplied with a specific error message.
