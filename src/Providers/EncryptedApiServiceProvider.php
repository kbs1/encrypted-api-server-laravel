<?php

namespace Kbs1\EncryptedApiServerLaravel\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\{Request, Response, RedirectResponse, JsonResponse};

use Kbs1\EncryptedApiServerLaravel\Client\{ClientConfigurationProviderInterface, ClientConfigurationProvider};

use Kbs1\EncryptedApiServerLaravel\Http\Middleware\EncryptedApiMiddleware;
use Kbs1\EncryptedApiServerLaravel\Console\Commands\GenerateSharedSecretsCommand;

class EncryptedApiServiceProvider extends ServiceProvider
{
	public function boot()
	{
		$this->publishes([__DIR__ . '/../../config/encrypted_api.php' => config_path('encrypted_api.php')], 'encrypted-api');

		if ($this->app->runningInConsole())
			$this->commands([GenerateSharedSecretsCommand::class]);

		$this->app->booted(function ($app) {
			// try to get EncryptedApiMiddleare to run first, unfortunately other service providers might
			// sill prepend another middleware, there is currently no way to enforce Kernel's middleware
			// execution order
			$app->make(Kernel::class)->prependMiddleware(EncryptedApiMiddleware::class);

			$this->createResponseMacros(Response::class);
			$this->createResponseMacros(RedirectResponse::class);
			$this->createResponseMacros(JsonResponse::class);
		});
	}

	public function register()
	{
		$this->mergeConfigFrom(__DIR__ . '/../../config/encrypted_api.php', 'encrypted_api');

		$this->app->bind(ClientConfigurationProviderInterface::class, ClientConfigurationProvider::class);
	}

	protected function createResponseMacros($class)
	{
		$class::macro('initEncryptedApiResponse', function () {
			if (!isset($this->overriddenHeaders)) {
				$this->overriddenHeaders = ['server', 'content-type', 'content-length', 'connection', 'cache-control', 'date'];
				$this->visibleHeaders = $this->unmanagedHeaders = [];
			}

			return $this;
		});

		$class::macro('getOverriddenHeaders', function () {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			return $this->overriddenHeaders;
		});

		$class::macro('getVisibleHeaders', function () {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			return $this->visibleHeaders;
		});

		$class::macro('getUnmanagedHeaders', function () {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			return $this->unmanagedHeaders;
		});

		$class::macro('withVisibleHeader', function ($name) {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			$name = strtolower($name);

			if (in_array($name, $this->overriddenHeaders))
				throw new \InvalidArgumentException($name . ' can not be sent as visible header.');

			if (!in_array($name, $this->visibleHeaders))
				$this->visibleHeaders[] = $name;

			return $this;
		});

		$class::macro('withoutVisibleHeader', function ($name) {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			$name = strtolower($name);

			if (($key = array_search($name, $this->visibleHeaders)) !== false) {
				unset($this->visibleHeaders[$key]);
				$this->visibleHeaders = array_values($this->visibleHeaders);
			}

			return $this;
		});

		$class::macro('withManagedHeader', function ($name) {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			$name = strtolower($name);

			if (($key = array_search($name, $this->unmanagedHeaders)) !== false) {
				unset($this->unmanagedHeaders[$key]);
				$this->unmanagedHeaders = array_values($this->unmanagedHeaders);
			}

			return $this;
		});

		$class::macro('withoutManagedHeader', function ($name) {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			$name = strtolower($name);

			if (in_array($name, $this->overriddenHeaders))
				throw new \InvalidArgumentException($name . ' can not be sent as unmanaged header.');

			if (!in_array($name, $this->unmanagedHeaders))
				$this->unmanagedHeaders[] = $name;

			return $this;
		});
	}
}
