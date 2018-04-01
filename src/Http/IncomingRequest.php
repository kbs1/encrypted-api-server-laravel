<?php

namespace Kbs1\EncryptedApiServerLaravel\Http;

use Kbs1\EncryptedApiBase\Cryptography\Decryptor;

use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\{ReplayAttacksProtectionException, RequestIdAlreadyProcessedException};
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\{InvalidRequestUrlException, InvalidRequestMethodException, InvalidMainRequestException};
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\InvalidFilesException;

use Symfony\Component\HttpFoundation\Cookie;

class IncomingRequest
{
	protected $decryptor, $request, $id;
	protected $secret1, $secret2;

	public function __construct($request, $secret1, $secret2)
	{
		$content = $request->getContent();

		if (strlen($request->getContent()) === 0 && isset($_POST['request']))
			$content = $_POST['request'];

		$this->decryptor = new Decryptor($content, $secret1, $secret2);
		$this->secret1 = $secret1;
		$this->secret2 = $secret2;
		$this->request = $request;
	}

	public function decrypt()
	{
		$input = $this->decryptor->getOriginal();
		$this->id = $input['id'];

		$this->checkRequestId($input);
		$this->checkUrl($input);
		$this->checkMethod($input);
		$this->checkMainRequestValidity($input);

		$this->setRequestData($input);
	}

	public function getId()
	{
		return $this->id;
	}

	protected function checkRequestId($input)
	{
		$dir = storage_path('encrypted_api_requests');

		if (file_exists($dir) && !is_dir($dir))
			throw new ReplayAttacksProtectionException('File is not a directory: ' . $dir);

		if (!file_exists($dir) && !@mkdir($dir))
			throw new ReplayAttacksProtectionException('Unable to create directory: ' . $dir);

		$request_id_file = @fopen($dir . '/' . $input['id'], 'x');
		if ($request_id_file === false)
			throw new RequestIdAlreadyProcessedException;

		$files = glob($dir . '/*');
		$now = time();

		foreach ($files as $file)
			if (is_file($file) && $now - filemtime($file) > 10)
				unlink($file);

		fclose($request_id_file);
	}

	protected function checkUrl($input)
	{
		$parts = parse_url($input['url']);
		if ($parts === false)
			throw new InvalidRequestUrlException;

		if (!isset($parts['scheme']) || !isset($parts['host']))
			throw new InvalidRequestUrlException;

		// discard user, pass and fragment, TODO: see how Laravel handles user and pass if present in $request->fullUrl()
		$url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
		$query = $parts['query'] ?? '';

		if ((string) $query !== '') {
			$query = $this->request->normalizeQueryString($query);
			$url .= '?' . $query;
		}

		if ($this->request->fullUrl() !== $url)
			throw new InvalidRequestUrlException;
	}

	protected function checkMethod($input)
	{
		if (strtolower($this->request->getRealMethod()) !== strtolower($input['method']))
			throw new InvalidRequestMethodException;
	}

	protected function checkMainRequestValidity($input)
	{
		if (strlen($this->request->getContent()) === 0 && !is_array($input['uploads']))
			throw new InvalidMainRequestException;

		if (strlen($this->request->getContent()) > 0 && $input['uploads'] !== null)
			throw new InvalidMainRequestException;
	}

	protected function checkFileValidity($input)
	{
		if ($input['uploads'] !== true)
			throw new InvalidFilesException;
	}

	protected function setRequestData($input)
	{
		// process all file uploads (verify, decrypt and alter $_FILES superglobal)
		if (count($_FILES)) {
			// build expected files map (only valid file form names are included by the client, if uploads array contains unexpected entries, validation will fail)
			$names = [];
			foreach ($input['uploads'] as $upload)
				$names[] = rawurlencode($upload['name']) . '=' . rawurlencode($upload['signature'] . ';' . $upload['filename']);

			parse_str(implode('&', $names), $names);
			$this->processFileUploads($_FILES, $names);

			array_walk_recursive($names, function ($value) {
				if ($value !== true)
					throw new InvalidFilesException; // some files that should be present are missing from the request (original value - signature is left in the map)
			});
		}

		// override $_SERVER variables with headers that are present in encrypted payload
		// code *based* on (not the same!) Symfony\Component\HttpFoundation\Request::overrideGlobals
		foreach ($input['headers'] as $key => $value) {
			$key = strtoupper(str_replace('-', '_', $key));
			if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH']))
				$_SERVER[$key] = implode(', ', $value);

			$_SERVER['HTTP_' . $key] = implode(', ', $value);
		}

		$query = $_GET;

		// compute new POST parameters and request content
		if ($input['data'] !== null && strtolower($_SERVER['CONTENT_TYPE'] ?? '') === 'application/x-www-form-urlencoded') {
			// parse request data same way as PHP natively does (parse_str)
			parse_str($input['data'], $request);
			$content = '';

			// Laravel doesn't support GET requests with a body as a source of input parameters (see laravel Request getInputSource())
			// if we have parameters passed as a GET body, switch query parameters with our body parameters
			if (strtolower($input['method']) === 'get') {
				$query = $request;
				$request = [];
			}
		} else {
			$request = [];
			$content = (string) $input['data'];
		}

		// if the original request didn't contain following headers, unset them
		$lowercase_headers = array_change_key_case($input['headers']);
		foreach (['content-type', 'content-length', 'content-md5'] as $header) {
			$key = strtoupper(str_replace('-', '_', $header));

			if (!isset($lowercase_headers[$header])) {
				unset($_SERVER[$key]); // POST requests
				unset($_SERVER['HTTP_' . $key]); // other requests (http://php.net/manual/en/reserved.variables.server.php#110763)
			}
		}

		// parse cookies from "Cookie" header
		if (isset($_SERVER['HTTP_COOKIE']))
			$cookies = $this->parseCookieHeader((string) $_SERVER['HTTP_COOKIE']);
		else
			$cookies = [];

		// initialize the request with new values, this also clears computed request properties such as "encodings" or "languages"
		$this->request->initialize($query, $request, array(), $cookies, $_FILES, $_SERVER, $content);

		// now that the request is in correct state, override all related PHP globals
		$this->request->overrideGlobals();

		// set Laravel's $request->json to null. This will cause replaced request content to be parsed again upon first json access.
		// helper methods such as isJson will succeed if the original request was json, since the header and server bags are now replaced as well.
		$this->request->setJson(null);
	}

	// parses "Cookie" header into multiple cookies and returns cookie names and their values as key-value array
	// resulting cookies are formatted the same way as PHP would register them in the $_COOKIE superglobal (using parse_str)
	// array cookies are supported, see http://php.net/manual/en/function.setcookie.php
	protected function parseCookieHeader($header)
	{
		$result = [];

		$cookies = array_map('trim', explode(';', $header));

		foreach ($cookies as $cookie) {
			// use Symfony\Component\HttpFoundation\Cookie to make sure cookie name is valid
			try {
				new Cookie(explode('=', $cookie)[0]);
			} catch (\InvalidArgumentException $ex) {
				continue;
			}

			// also replaces some characters into undersores, as PHP would natively do (http://php.net/manual/en/language.variables.external.php#81080)
			parse_str($cookie, $parsed);
			$result = array_merge_recursive($result, $parsed);
		}

		return $result;
	}

	protected function processFileUploads(array $files, &$names, $keys = [])
	{
		$level = count($keys);

		foreach ($files as $key => $file) {
			$keys[] = $key;
			if ($level === 0 && is_array($file['name'])) {
				$this->processFileUploads($file['name'], $names, $keys);
				array_pop($keys);
				continue;
			}

			if ($level > 0 && is_array($file)) {
				$this->processFileUploads($file, $names, $keys);
				array_pop($keys);
				continue;
			}

			// processing file, check if this file is in expected files map
			$current = &$names;
			foreach ($keys as $key) {
				if (!isset($current[$key]))
					throw new InvalidFilesException;

				$current = &$current[$key];
			}

			if ($current === true)
				throw new InvalidFilesException;

			$upload_data = explode(';', $current);
			if (count($upload_data) !== 2)
				throw new InvalidFilesException;

			// process the file
			if ($this->getFileProperty($keys, 'name') !== $upload_data[1])
				throw new InvalidFilesException;

			$tmp_name = $this->getFileProperty($keys, 'tmp_name');

			if ($this->getFileProperty($keys, 'error') !== UPLOAD_ERR_OK || !is_uploaded_file($tmp_name))
				throw new InvalidFilesException;

			$decryptor = new Decryptor(@file_get_contents($tmp_name), $this->secret1, $this->secret2);
			$input = $decryptor->getOriginal();

			if ($decryptor->getSignature() !== $upload_data[0])
				throw new InvalidFilesException;

			$this->checkRequestId($input);
			$this->checkUrl($input);
			$this->checkMethod($input);
			$this->checkFileValidity($input);

			// replace the file with decrypted contents
			file_put_contents($tmp_name, $input['data']);

			// replace file size
			$this->setFileProperty($keys, 'size', strlen($input['data']));

			// replace file type if Content-Type header was provided
			$lowercase_headers = array_change_key_case($input['headers']);
			if (isset($lowercase_headers['content-type']))
				$this->setFileProperty($keys, 'type', implode('; ', $lowercase_headers['content-type']));
			else
				$this->setFileProperty($keys, 'type', null); // otherwise force Symfony to guess content type

			$current = true;

			array_pop($keys);
		}
	}

	protected function getFileProperty($keys, $property)
	{
		if (!isset($_FILES[$keys[0]]))
			throw new InvalidFilesException;

		$current = $_FILES[$keys[0]][$property];
		array_shift($keys);

		foreach ($keys as $key) {
			if (!isset($current[$key]))
				throw new InvalidFilesException;

			$current = $current[$key];
		}

		return $current;
	}

	protected function setFileProperty($keys, $property, $value)
	{
		if (!isset($_FILES[$keys[0]]))
			throw new InvalidFilesException;

		$current = &$_FILES[$keys[0]][$property];
		array_shift($keys);

		foreach ($keys as $key) {
			if (!isset($current[$key]))
				throw new InvalidFilesException;

			$current = &$current[$key];
		}

		$current = $value;
	}
}
