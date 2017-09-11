#!/usr/bin/env php
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

version_compare(PHP_VERSION, '5.4.0', '>=') or die("Requires PHP >= 5.4.0\n");

require_once 'ClearPassApi.php';
require_once 'docopt.php';
require_once 'httpful.phar';

use Httpful\Http;

class CommandLineInterface
{
	public $usage = <<<END_USAGE
ClearPass API client tool.  Calls a single API specified by METHOD and URL
and prints the result.

Usage:
  cpapi.php (-? | --help)
  cpapi.php [options] METHOD URL [PARAMS...]

Options:
  -? --help               Show this screen.
  -h --host HOSTNAME      Set the ClearPass server hostname.
  -k --insecure           Allow insecure SSL certificate checks.
  --access-token TOKEN    Use TOKEN as the OAuth2 Bearer access_token.
  --client-id CLIENT      OAuth2 client identifier.
  --client-secret SECRET  OAuth2 client secret.
  --username USERNAME     OAuth2 username, for grant_type password.
  --password PASSWORD     OAuth2 password, for grant_type password.
  -z --unauthorized       Skip OAuth2 authorization.  Only useful for /oauth.
  -v --verbose            Print HTTP request and response traffic.
  --debug                 Print connection traces.

PARAMS may be expressed as:
  * name=value     for JSON body parameters (POST, PATCH, PUT)
  * name==value    for query string parameters (GET)

Authorization requires ONE of the following:
  * --access-token (if you already performed OAuth2 authentication)
  * --client-id, --client-secret (for grant_type=client_credentials)
  * --client-id, --username, --password (for grant_type=password, public client)
  * --client-id, --client-secret, --username, --password (for grant_type=password)

Most options can be stored in environment variables; use _ in place of -.

Examples:
  # Get an access_token
  cpapi.php --host clearpass.example.com -z POST /oauth grant_type=client_credentials client_id=Client1 client_secret=ClientSecret

  # Create a guest account; show full request/response
  export host=clearpass.example.com
  export access_token=...
  cpapi.php -v POST /guest username=demo@example.com password=123456 role_id=2 visitor_name='Demo User'

  # Lookup a guest account by ID
  cpapi.php get /guest/3001

  # Modify a guest account
  cpapi.php patch /guest/3001 password=654321

END_USAGE;

	public $args;

	public function main()
	{
		$this->args = Docopt::handle($this->usage);
		$exit_status = 0;
		try {
			$api = new ClearPass\Api\Client;
			$api->host = $this->argStr('host');
			$api->insecure = $this->argBool('insecure');
			$api->verbose = $this->argBool('verbose');
			$api->debug = $this->argBool('debug');
			$api->access_token = $this->argStr('access_token');
			$api->client_id = $this->argStr('client_id');
			$api->client_secret = $this->argStr('client_secret');
			$api->username = $this->argStr('username');
			$api->password = $this->argStr('password');
			list($query, $body) = $this->parseParams($this->args['PARAMS']);
			$result = $api->invoke($this->validateMethod($this->args['METHOD']),
				$this->args['URL'], $query, $body, !$this->args['--unauthorized']);
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
		} catch (\ClearPass\Api\ConfigurationException $e) {
			fprintf(STDERR, "ERROR: Configuration error: %s\n", $e->getMessage());
			$exit_status = 3;
		} catch (\Httpful\Exception\ConnectionErrorException $e) {
			fprintf(STDERR, "FATAL: Connection error: %s\n", $e->getMessage());
			$exit_status = 2;
		} catch (\ClearPass\Api\Error $e) {
			fprintf(STDERR, "ERROR: API error: %s\n", $e->getMessage());
			fprintf(STDERR, "%s\n", json_encode($e->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			$exit_status = 1;
		}
		return $exit_status;
	}

	protected function validateMethod($method)
	{
		$method = strtoupper($method);
		if (!in_array($method, [Http::GET, Http::POST, Http::PATCH, Http::PUT, Http::DELETE])) {
			throw new \ClearPass\Api\ConfigurationException("Invalid HTTP method: {$method}", 405);
		}
		return $method;
	}

	protected function parseParams($params)
	{
		$query = [];
		$body = [];
		$bad_params = [];
		foreach ($params as $str) {
			if (preg_match('/^(?P<name>\w+)(?P<op>==|=)(?P<value>.*)$/s', $str, $match)) {
				if ($match['op'] == '==') {
					$query[$match['name']] = $match['value'];
				} else {
					$body[$match['name']] = $match['value'];
				}
			} else {
				$bad_params[] = $str;
			}
		}
		if (!empty($bad_params)) {
			throw new \ClearPass\Api\ConfigurationException("Invalid parameter(s): " . implode(', ', $bad_params), 400);
		}
		if (empty($query)) {
			$query = null;
		}
		if (empty($body)) {
			$body = null;
		}
		return array($query, $body);
	}

	protected function argBool($name)
	{
		$flag = '--' . str_replace('_', '-', $name);
		$env = getenv($name);
		if ($env !== false) {
			$env = filter_var($env, FILTER_VALIDATE_BOOLEAN);
		}
		return $this->args[$flag] ? $this->args[$flag] : $env;
	}

	protected function argStr($name, $default = '')
	{
		$flag = '--' . str_replace('_', '-', $name);
		$env = getenv($name);
		if ($env === false) {
			$env = $default;
		}
		return $this->args[$flag] !== null ? $this->args[$flag] : $env;
	}
}

/////////////////////////////////////////////////////////////////////////////
// Main entry point

$cli = new CommandLineInterface;
exit($cli->main());
