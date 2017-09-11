# clearpass-api-php

This directory contains sample PHP code demonstrating how to interact with
the ClearPass REST API.

A test driver (cpapi.php) is included that allows command-line usage of the
API.


# License

Apache 2 license; see `LICENSE`

Httpful (see http://phphttpclient.com/) is licensed under the MIT license.

	Copyright (c) 2012 Nate Good <me@nategood.com>

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in all
	copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
	SOFTWARE.

PHP Docopt (see https://github.com/docopt/docopt.php) is licensed under the MIT license:

	Copyright (c) 2012 Vladimir Keleshev, <vladimir@keleshev.com>
	                   Blake Williams, <code@shabbyrobe.org>

	Permission is hereby granted, free of charge, to any person obtaining a copy of
	this software and associated documentation files (the "Software"), to deal in
	the Software without restriction, including without limitation the rights to
	use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
	of the Software, and to permit persons to whom the Software is furnished to do
	so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in all
	copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
	SOFTWARE.


# Quick Start

You will need at least PHP 5.4 installed:

	$ php --version

Run the script:

	$ ./cpapi.php --help


# OAuth2 Authentication

To use the REST API, first create an API client using the ClearPass Admin UI:

* Navigate to Guest > Administration > API Services > API Clients
* Create a new API Client
* Select the appropriate operator profile
* Select the `client_credentials` grant type
* Make a note of the client ID and client secret

To obtain an access token, use a command such as the following:

	$ ./cpapi.php --host clearpass.example.com -z POST /oauth \
	    grant_type=client_credentials \
	    client_id=Client1 \
	    client_secret=TcErfNRQ4e1g4wWg/YotZH5lktAVDgIJYDKshW4A2ysA

This should produce a result such as:

	{
	    "access_token": "9d368230c61fbe6e505e6da3e55447a401b47bf2",
	    "expires_in": 28800,
	    "scope": null,
	    "token_type": "Bearer"
	}

To verify the access token, perform an API request using it:

	$ ./cpapi.php --host clearpass.example.com GET /oauth/me \
	    --access-token 9d368230c61fbe6e505e6da3e55447a401b47bf2

HTTP 403 Forbidden will be returned if the token is invalid or expired.

This is the basic `client_credentials` authorization grant type.  Other grant
types are possible with OAuth2; for details, refer to RFC 6749.

For convenience, you can save the access token in the environment
variable `access_token`:

    $ export access_token=9d368230c61fbe6e505e6da3e55447a401b47bf2
    $ ./cpapi.php --host clearpass.example.com GET /oauth/me


# API Usage

Start with this PHP `require_once` statement:

	require_once 'ClearPassApi.php';

You can then create a `ClearPass\Api\Client` object to make API calls:

	$client = new ClearPass\Api\Client;
	$client->host = 'clearpass.example.com';
	$client->access_token = '9d368230c61fbe6e505e6da3e55447a401b47bf2';

The client provides methods for GET, POST, PATCH, PUT, and DELETE requests:

	// GET method:
	$result = $client->get('/oauth/me');
	// $result->info and $result->name will now be set

	// POST method:
	$user = ['username' => 'demo@example.com', 'password' => '123456'];
	$result = $client->post('/guest', $user);
	// $result will contain guest account properties

Errors will generate an exception.  This may be:

* A `ClearPass\Api\ConfigurationException`, indicating configuration problems
* An exception from the underlying Httpful library, e.g.
  `Httpful\Exception\ConnectionErrorException`
* A `ClearPass\Api\Error` object containing details of the error.

	// PATCH generating a validation error:
	try {
		$result = $client->patch('/guest/' . $result->id, ['username' => '']);
	} catch (ClearPass\Api\Error $e) {
		echo "Result: {$e->getCode()} {$e->getMessage()}\n";
		echo "Details: ";
		var_export($e->details);
		echo "\n";
	}

APIs are not wrapped or bundled; refer to the ClearPass API Explorer for
details on what API to call and what parameters are expected.
