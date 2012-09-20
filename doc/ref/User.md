# Totsy REST API Server: User Resources #

Users are a read/write interface to Totsy customer accounts.

### Authenticate a User ###
`POST /auth` with the e-mail address & plaintext password of a user, which will perform a user login.
The client receives an HTTP cookie along with a link [`rel=http://rel.totsy.com/entity/user`] to the User entity.

### Authenticate a User via Facebook ###
`POST /auth` with the Facebook access token, which will perform a user login (and registration, if necessary).
The client receives an HTTP cookie along with a link [`rel=http://rel.totsy.com/entity/user`] to the User entity.

### End a User session ###
`DELETE /auth` will destroy the current User session and log the user out of the system.

### Retrieve information about a User ###
`GET /user/123` responds with information about a specific user. This URL is provided when a new authorization token is generated, and should not be constructed or created in any other fashion.
The user information includes a set of links to the user's addresses [`rel=http://rel.totsy.com/collection/address`], orders [`rel=http://rel.totsy.com/collection/order`], rewards [`rel=http://rel.totsy.com/collection/reward`], and saved credit cards [`rel=http://rel.totsy.com/collection/creditcard`].

### Create a new User ###
`POST /user` with a partial representation of a User.

### Update an existing User ###
`PUT /user/123` with a partial representation of a User.

### Retrieve Addresses stored for a User ###
`GET /user/123/address` responds with a collection of Addresses. Each entry in the collection contains a a full link [`rel=http://rel.totsy.com/entity/address`] to the original Address resource.

### Create a new Address for a User ###
`POST /user/123/address` with a partial representation of an Address. The `state` field can be either the full name of the state, or the two-letter abbreviation.

### Retrieve Credit Cards stored for a User ###
`GET /user/123/creditcard` responds with a collection of Credit Cards. Each entry in the collection contains a a full link [`rel=http://rel.totsy.com/entity/creditcard`] to the original Credit Card resource.

### Create a new Credit Card for a User ###
`POST /user/123/creditcard` with a full representation of a Credit Card.

### Retrieve Orders stored for a User ###
`GET /user/123/order` responds with a collection of Orders. Each entry in the collection contains a a full link [`rel=http://rel.totsy.com/entity/order`] to the original Order resource.
