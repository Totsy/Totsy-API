# Totsy REST API Server: General Usage #

The official Totsy API is a REST web service that exposes a discrete set of resources that can be manipulated over HTTP.

Client Authorization
--------------------

The REST API web service uses standard HTTP Basic authentication for authenticating HTTP requests made to the server.
Each API client is assigned a secret username & password pair that are used to generate a digest, that is submitted in the HTTP `Authorization` request header.

For more information about HTTP Basic authentication, please read the original [RFC 2617](http://tools.ietf.org/html/rfc2617#section-2).

General Usage
-------------

A client should be able to interact with the web service using just two pieces of information:
1. The API base URL and
2. A set of link relations to identify related resources.

An API client should not define or construct URLs to resources. Instead, the client should begin interacting with the service using the base URL and then examine the "links" collection in resource representations to discover URLs to related resources in order to make forward progress in carrying out a task.
Most link relations are of the form: `http://rel.totsy.com/<type>/<resourceName>`, where `<type>` indicates the type of the expected response (either `collection` or `entity`) and `resourceName` is a generic name used to identify the expected resource.

Error Handling
--------------

Errors generated on the server are communicated to the client by populating the `X-API-Error` HTTP header in a response. API clients may inspect the value of this header for error information pertaining to an unsuccessful request.



