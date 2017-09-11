<?php // <meta charset='UTF-8'>

/**
 * Copyright (c) 2015 Aruba Networks, Inc.
 * Copyright (c) 2016 Hewlett Packard Enterprise Development LP
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace ClearPass\Api;

require_once 'httpful.phar';

use Httpful\Http;
use Httpful\Request;

abstract class Url
{
	/**
	 * Credit: This function adapted from http://php.net/manual/en/function.parse-url.php#106731
	 */
	public static function join($parsed)
	{
		$scheme   = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
		$host     = isset($parsed['host']) ? $parsed['host'] : '';
		$port     = isset($parsed['port']) ? ':' . $parsed['port'] : '';
		$user     = isset($parsed['user']) ? $parsed['user'] : '';
		$pass     = isset($parsed['pass']) ? ':' . $parsed['pass']  : '';
		$pass     = ($user || $pass) ? "$pass@" : '';
		$path     = isset($parsed['path']) ? $parsed['path'] : '';
		$query    = isset($parsed['query']) ? '?' . $parsed['query'] : '';
		$fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
		return "$scheme$user$pass$host$port$path$query$fragment";
	}
}

/**
 * Exception thrown indicating there is a configuration issue preventing the API call.
 */
class ConfigurationException extends \Exception
{
}

/**
 * Exception thrown when an API call fails (400 or higher status code).
 *
 * The 'details' member variable contains full information about the problem.
 */
class Error extends \Exception
{
	public $details;

	public function __construct($message, $code, $details)
	{
		parent::__construct($message, $code);
		$this->details = $details;
	}

	public function __toString()
	{
		return parent::__toString() . ', details: ' . var_export($this->details, true);
	}
}

/**
 * API client for calling ClearPass REST/RPC API methods and handling OAuth2
 * authentication.
 *
 * You MUST configure the 'host' member variable and one of the following
 * for authorization:
 *
 *  - access_token (if you already performed OAuth2 authentication)
 *  - client_id, client_secret (implies grant_type=client_credentials)
 *  - client_id, username, password (implies grant_type=password, public client)
 *  - client_id, client_secret, username, password (implies grant_type=password)
 *
 * Call the get(), post(), patch(), put(), or delete() methods to invoke the
 * corresponding API calls.  OAuth2 authorization will be handled automatically
 * using this method.
 *
 * To make an API call without the Authorization: header, use the invoke() method.
 */
class Client
{
	/////////////////////////////////////////////////////////////////////////////
	// Properties

	/** The ClearPass server hostname */
	public $host = '';

	/** Maximum time to wait for HTTP operations */
	public $timeout = 60;

	/** Allow insecure SSL certificate checks */
	public $insecure = false;

	/** Print HTTP request and response traffic */
	public $verbose = false;

	/** Print connection traces */
	public $debug = false;

	/////////////////////////////////////////////////////////////////////////////
	// OAuth2

	/** Token type.  Only supported type is 'Bearer' */
	public $token_type = 'Bearer';

	/** Access token obtained from the OAuth2 server */
	public $access_token = null;

	/** Time the access token will expire (if obtained locally) */
	public $access_token_expires = null;

	/** OAuth2 client identifier; required in all cases */
	public $client_id = '';

	/**
	 * OAuth2 client secret; required unless "public client" option is enabled
	 * on the server
	 */
	public $client_secret = '';

	/** OAuth2 username; required for grant_type "password" */
	public $username = '';

	/** OAuth2 password; required for grant_type "password" */
	public $password = '';

	/////////////////////////////////////////////////////////////////////////////
	// Public methods

	public function get($url, $query_params = array())
	{
		return $this->invoke(Http::GET, $url, $query_params);
	}

	public function post($url, $body = array(), $query_params = array())
	{
		return $this->invoke(Http::POST, $url, $query_params, $body);
	}

	public function patch($url, $body = array(), $query_params = array())
	{
		return $this->invoke(Http::PATCH, $url, $query_params, $body);
	}

	public function put($url, $body = array(), $query_params = array())
	{
		return $this->invoke(Http::PUT, $url, $query_params, $body);
	}

	public function delete($url, $query_params = array())
	{
		return $this->invoke(Http::DELETE, $url, $query_params);
	}

	/**
	 * @throws Httpful\Exception\ConnectionErrorException
	 * @throws ClearPass\Api\Error
	 */
	public function invoke($method, $uri, $query_params, $body = null, $authz = true)
	{
		$invoke_url = empty($query_params) ? $uri : ($uri . '?' . http_build_query($query_params));
		$request = Request::init()
			->timeout($this->timeout)
			->strictSSL(!$this->insecure)
			->method($method)
			->uri($this->getUrl($invoke_url));
		if ($this->debug) {
			$request->_debug = true;
		}
		if ($authz) {
			$request->withAuthorization($this->authorizationHeader());
		}
		if ($body !== null) {
			$request->sendsJson()->body($body);
		}
		if ($this->verbose) {
			$request->_curlPrep();
			echo str_replace("\r\n", "\n", $request->raw_headers), "\n";
			echo $request->serialized_payload, "\n\n";
		}
		$response = $request->send();
		if ($this->verbose) {
			echo str_replace("\r\n", "\n", $response->raw_headers), "\n\n";
			echo $response->raw_body, "\n\n";
		}
		if ($response->hasErrors()) {
			$parsed_url = parse_url($request->uri);
			throw new Error("{$request->method} {$parsed_url['path']} failed with status {$response->code}", $response->code, array(
				'api_host' => $parsed_url['host'] . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : ''),
				'api_path' => $parsed_url['path'],
				'request' => array(
					'method' => $request->method,
					'url' => $request->uri,
					'query_params' => $query_params,
					'body' => $body,
				),
				'response' => array(
					'status' => $response->code,
					// 'metadata' => $response->meta_data, // curl info
					'raw_headers' => $response->raw_headers,
					'raw_body' => $response->raw_body,
					'headers' => $response->headers->toArray(),
					'body' => is_object($response->body) ? (array) $response->body : null,
				),
			));
		}
		return $response->body;
	}

	/////////////////////////////////////////////////////////////////////////////
	// Implementation

	protected function getUrl($uri)
	{
		if ($this->host == '') {
			throw new ConfigurationException("Hostname must be provided");
		}
		$rel = parse_url($uri);
		if (isset($rel['path'])) {
			if ($rel['path'][0] !== '/') {
				$rel['path'] = '/' . $rel['path'];
			}
			if (substr($rel['path'], 0, 4) !== '/api') {
				$rel['path'] = '/api' . $rel['path'];
			}
		}
		return Url::join(array_merge(['scheme' => 'https', 'host' => $this->host], $rel));
	}

	protected function authorizationHeader()
	{
		if (!$this->token_type || !$this->access_token) {
			if ($this->client_id !== '' && $this->username !== '' && $this->password !== '') {
				$data = [
					'grant_type' => 'password',
					'client_id' => $this->client_id,
					'username' => $this->username,
					'password' => $this->password,
				];
				// Public client has no client_secret
				if ($this->client_secret !== '') {
					$data['client_secret'] = $this->client_secret;
				}
			} else if ($this->client_id !== '' && $this->client_secret !== '') {
				$data = [
					'grant_type' => 'client_credentials',
					'client_id' => $this->client_id,
				];
				if ($this->client_secret !== '') {
					$data['client_secret'] = $this->client_secret;
				}
			} else {
				throw new ConfigurationException("Cannot authenticate: need (client_id, client_secret) or (client_id, username, password)", 400);
			}
			$oauth = $this->invoke(Http::POST, '/oauth', null, $data, false);
			$this->token_type = $oauth->token_type;
			$this->access_token = $oauth->access_token;
			$this->access_token_expires = time() + $oauth->expires_in;
		}
		return $this->token_type . ' ' . $this->access_token;
	}
}
