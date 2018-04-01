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
				$this->unencryptedHeaders = [];
			}

			return $this;
		});

		$class::macro('getOverriddenHeaders', function () {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			return $this->overriddenHeaders;
		});

		$class::macro('getUnencryptedHeaders', function () {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			return $this->unencryptedHeaders;
		});

		$class::macro('withPlainHeader', function ($name) {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			$name = strtolower($name);

			if (in_array($name, $this->overriddenHeaders))
				throw new \InvalidArgumentException($name . ' can not be sent as a plain header.');

			if (!in_array($name, $this->unencryptedHeaders))
				$this->unencryptedHeaders[] = $name;

			return $this;
		});

		$class::macro('withoutPlainHeader', function ($name) {
			if (!isset($this->overriddenHeaders))
				$this->initEncryptedApiResponse();

			$name = strtolower($name);

			if (($key = array_search($name, $this->unencryptedHeaders)) !== false) {
				unset($this->unencryptedHeaders[$key]);
				$this->unencryptedHeaders = array_values($this->unencryptedHeaders);
			}

			return $this;
		});
	}
}
