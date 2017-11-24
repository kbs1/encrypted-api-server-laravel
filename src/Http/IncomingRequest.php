<?php

namespace Kbs1\EncryptedApiServerLaravel\Http;

use Kbs1\EncryptedApiBase\Cryptography\Decryptor;

use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\ReplayAttacksProtectionException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\RequestIdAlreadyProcessedException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\InvalidRequestUrlException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\InvalidRequestMethodException;

// TODO: user BrowserKit\CookieJar::updateFromSetCookie instead?
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

		$this->setInputData($input);
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

	protected function setInputData($input)
	{
		// query string is correctly parsed, as it is always unencrypted, no need to modify $request->query
		// request attributes are not normally populated by Laravel, so don't touch them
		// don't touch $request->files, since we don't support encrypted file uploads

		// replace body parameters and request content
		if (is_array($input['data'])) {
			$this->request->request->replace($input['data']);
			$this->request->setContent('');
		} else {
			$this->request->setContent((string) $input['data']);
		}

		// replace request headers
		if (is_array($input['headers']))
			$this->request->headers->replace($input['headers']);

		// parse and replace any cookies present
		$cookies = $this->parseCookie($this->request->headers->get('COOKIE')); // TODO: decode cookies?
		$this->request->cookies->replace($cookies); // TODO: test array cookies http://php.net/manual/en/function.setcookie.php

		// finally override PHP globals
		$this->request->overrideGlobals();
	}

	// parse received "Cookie" header into multiple cookies, and returns valid cookies as an array
	// code based on Symfony\Component\BrowserKit\CookieJar::updateFromSetCookie
	protected function parseCookie($header)
	{
		$parsed_cookies = $cookies = [];

		foreach (explode(',', $header) as $i => $part) {
			if (0 === $i || preg_match('/^(?P<token>\s*[0-9A-Za-z!#\$%\&\'\*\+\-\.^_`\|~]+)=/', $part)) {
				$parsed_cookies[] = ltrim($part);
			} else {
				$parsed_cookies[count($cookies) - 1] .= ','.$part;
			}
		}

		foreach ($parsed_cookies as $cookie) {
			try {
				$cookie = Cookie::fromString($cookie); // TODO: decode?
			} catch (\InvalidArgumentException $ex) {
				// ignore invalid cookies
				continue;
			}

			if ($cookie->isCleared())
				continue; // ignore expired cookies

			// TODO: implement checks for getDomain(), getPath(), isSecure(), isHttpOnly(), isRaw(), getSameSite() [lax, strict]?

			$cookies[$cookie->getName()] = $cookie->getValue();
		}

		return $cookies;
	}
}
