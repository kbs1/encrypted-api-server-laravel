<?php

namespace Kbs1\EncryptedApiServerLaravel\Http;

use Kbs1\EncryptedApiBase\Cryptography\Decryptor;

use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\ReplayAttacksProtectionException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\RequestIdAlreadyProcessedException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\InvalidRequestUrlException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\InvalidRequestMethodException;

use Symfony\Component\HttpFoundation\Cookie;

class IncomingRequest
{
	protected $decryptor, $request, $id;

	public function __construct($request, $secret1, $secret2)
	{
		$this->decryptor = new Decryptor($request->getContent(), $secret1, $secret2);
		$this->request = $request;
	}

	public function decrypt()
	{
		$input = $this->decryptor->decrypt();
		$this->id = $input['id'];

		$this->checkRequestId($input);
		$this->checkUrl($input);
		$this->checkMethod($input);

		$this->setRequestData($input);
	}

	public function getId()
	{
		return $this->id;
	}

	protected function checkRequestId()
	{
		$dir = storage_path('encrypted_api_requests');

		if (file_exists($dir) && !is_dir($dir))
			throw new ReplayAttacksProtectionException('File is not a directory: ' . $dir);

		if (!file_exists($dir) && !@mkdir($dir))
			throw new ReplayAttacksProtectionException('Unable to create directory: ' . $dir);

		$request_id_file = @fopen($dir . '/' . $this->getId(), 'x');
		if ($request_id_file === false)
			throw new RequestIdAlreadyProcessedException();

		$files = glob($dir . '/*');
		$now = time();

		foreach ($files as $file)
			if (is_file($file) && $now - filemtime($file) > 10)
				unlink($file);

		fclose($request_id_file);
	}

	protected function checkUrl($input)
	{
		if ($this->request->fullUrl() !== $input->url)
			throw new InvalidRequestUrlException();
	}

	protected function checkMethod($input)
	{
		if (strtolower($this->request->method()) !== strtolower($input->method))
			throw new InvalidRequestMethodException();
	}

	protected function setRequestData($input)
	{
		// compute new POST parameters and request content
		if (is_array($input['data'])) {
			// parse request data same way as PHP natively does (build HTTP query and then parse_str)
			parse_str(http_build_query($input['data']), $request);
			$content = '';
		} else {
			$request = [];
			$content = (string) $input['data'];
		}

		// parse cookies from "Cookie" header
		$cookies = $this->parseCookieHeader((array) $input['headers']);

		// override $_SERVER variables that would normally be populated if encrypted headers were sent
		// code based on Symfony\Component\HttpFoundation\Request::overrideGlobals
		foreach ($headers as $key => $value) {
			$key = strtoupper(str_replace('-', '_', $key));
			if (in_array($key, array('CONTENT_TYPE', 'CONTENT_LENGTH'))) {
				$_SERVER[$key] = implode(', ', $value);
			} else {
				$_SERVER['HTTP_' . $key] = implode(', ', $value);
			}
		}

		// initialize the request with new values, this also clears computed request properties such as "encodings" or "languages"
		// $_GET is never changed, as query string is never encrypted
		// $_FILES is never changed, as we don't support encrypted files transmission over multipart/form-data
		$this->request->initialize($_GET, $request, array(), $cookies, $_FILES, $_SERVER, $content);

		// finally override all request related PHP globals
		$this->request->overrideGlobals();
	}

	// parses "Cookie" header into multiple cookies and returns cookie names and their values as key-value array
	// resulting cookies are formatted the same way as PHP would register them in the $_COOKIE superglobal (using parse_str)
	// array cookies are supported, see http://php.net/manual/en/function.setcookie.php
	protected function parseCookieHeader(array $headers)
	{
		$headers = array_change_key_case($headers);
		$header = $headers['cookie'] ?? null;

		if (!$header)
			return [];

		$cookies = array_map('trim', explode(';', $header));
		$result = [];

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
}
