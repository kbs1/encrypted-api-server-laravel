<?php

namespace Kbs1\EncryptedApiServerLaravel\Http\Middleware;

use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\InvalidClientIpException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\MiddlewarePriorityException;

use Kbs1\EncryptedApiServerLaravel\Http\IncomingRequest;
use Kbs1\EncryptedApiServerLaravel\Http\OutgoingResponse;

use Illuminate\Foundation\Application;

class EncryptedApi
{
	protected $app;

	public function __construct(Application $app)
	{
		$this->app = $app;
	}

	public function handle($request, \Closure $next, $guard = null)
	{
		$this->ensureMiddlewarePriority();

		$this->checkIpWhitelist($request);
		$secrets = $this->getSharedSecrets($request);

		$incoming = new IncomingRequest($request, $secrets['secret1'], $secrets['secret2']);

		try {
			$incoming->decrypt();
			$response = $next($request);
		} catch (\Exception $ex) {
			$handler = $this->app['Illuminate\Contracts\Debug\ExceptionHandler'];
			$handler->report($ex);
			$response = $handler->render($request, $ex);
		}

		$outgoing = new OutgoingResponse($response, $secrets['secret1'], $secrets['secret2'], $incoming->getId());
		$outgoing->encrypt();

		return $response;
	}

	protected function checkIpWhitelist($request)
	{
		$whitelist = (array) $this->getAllowedIps($request);
		if ($whitelist) {
			foreach ($whitelist as $ip) {
				if ($request->ip() == $ip)
					return;
			}

			throw new InvalidClientIpException();
		}
	}

	protected function getSharedSecrets($request)
	{
		return ['secret1' => config('encrypted_api.secret1'), 'secret2' => config('encrypted_api.secret2')];
	}

	protected function getAllowedIps($request)
	{
		return config('encrypted_api.ipv4_whitelist');
	}

	protected function ensureMiddlewarePriority()
	{
		$message = 'Please make sure kbs1.encryptedApi-v1 middleware is the first middleware to run by modifying any service provider that updates the router $middlewarePriority array in the application\'s "booted" callback.';

		if (!isset($this->app['router']->middlewarePriority[0]))
			throw new MiddlewarePriorityException($message);

		if ($this->app['router']->middlewarePriority[0] !== 'kbs1.encryptedApi-v1')
			throw new MiddlewarePriorityException($message);
	}
}
