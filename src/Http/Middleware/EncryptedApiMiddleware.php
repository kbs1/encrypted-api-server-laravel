<?php

namespace Kbs1\EncryptedApiServerLaravel\Http\Middleware;

use Kbs1\EncryptedApiServerLaravel\Client\ClientConfigurationProviderInterface;
use Kbs1\EncryptedApiServerLaravel\Exceptions\Middleware\{InvalidClientIpException, MiddlewareOrderException};
use Kbs1\EncryptedApiServerLaravel\Http\{IncomingRequest, OutgoingResponse};

use Illuminate\Foundation\Application;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;

class EncryptedApiMiddleware
{
	protected $app;

	public function __construct(Application $app)
	{
		$this->app = $app;
	}

	public function handle($request, \Closure $next)
	{
		if (!$this->shouldProcessRequest($request))
			return $next($request);

		$this->ensureMiddlewareOrder();

		$config = $this->app->make(ClientConfigurationProviderInterface::class);
		$this->checkIpv4Whitelist($request, $config->getIpv4Whitelist());
		$secrets = $config->getSharedSecrets();

		$incoming = new IncomingRequest($request, $secrets['secret1'], $secrets['secret2']);

		try {
			$incoming->decrypt();
			$response = $next($request);
		} catch (\Exception $ex) {
			$handler = $this->app->make(ExceptionHandler::class);
			$handler->report($ex);
			$response = $handler->render($request, $ex);
		}

		$outgoing = new OutgoingResponse($response, $secrets['secret1'], $secrets['secret2'], $incoming->getId());
		return $outgoing->encrypted();
	}

	protected function shouldProcessRequest($request)
	{
		$included = (array) config('encrypted_api.routes.include');
		$excluded = (array) config('encrypted_api.routes.exclude');

		if (!$included && !$excluded)
			return false;

		foreach ($excluded as $pattern)
			if ($request->is($pattern !== '/' ? trim($pattern, '/') : $pattern))
				return false;

		foreach ($included as $pattern)
			if ($request->is($pattern !== '/' ? trim($pattern, '/') : $pattern))
				return true;

		return false;
	}

	protected function checkIpv4Whitelist($request, $whitelist)
	{
		if (is_string($whitelist))
			$whitelist = [$whitelist];

		if (is_array($whitelist) && count($whitelist) > 0) {
			foreach ($whitelist as $ip) {
				if ($request->ip() == $ip)
					return;
			}

			throw new InvalidClientIpException();
		}
	}

	protected function ensureMiddlewareOrder()
	{
		$message = 'Please make sure the "' . __CLASS__ . '" kernel middleware is the first middleware to run by modifying any service provider that prepends the kernel middleware in application\'s "booted" callback.';
		$kernel = $this->app->make(Kernel::class);

		$properties = (array) $kernel;
		$property = chr(0) . '*' . chr(0) . 'middleware';
		if (isset($properties[$property])) {
			$middleware = $properties[$property];

			if (!isset($middleware[0]) || $middleware[0] !== __CLASS__)
				throw new MiddlewareOrderException($message);
		}
	}
}
