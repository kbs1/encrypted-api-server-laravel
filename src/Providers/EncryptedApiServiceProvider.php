<?php

namespace Kbs1\EncryptedApiServerLaravel\Providers;

use Illuminate\Support\ServiceProvider;

use Kbs1\EncryptedApiServerLaravel\Http\Middleware\EncryptedApi;
use Kbs1\EncryptedApiServerLaravel\Console\Commands\GenerateSharedSecretsCommand;

class EncryptedApiServiceProvider extends ServiceProvider
{
	public function boot()
	{
		$this->publishes([__DIR__ . '/../../config/encrypted_api.php' => config_path('encrypted_api.php')], 'encrypted-api');

		$this->app['router']->aliasMiddleware('kbs1.encryptedApi-v1', EncryptedApi::class);

		if ($this->app->runningInConsole()) {
			$this->commands([
				GenerateSharedSecretsCommand::class,
			]);
		}

		$this->app->booted(function ($app) {
			// make sure encrypted API middleware runs first
			if (($key = array_search('kbs1.encryptedApi-v1', $app['router']->middlewarePriority)) !== false)
				unset($app['router']->middlewarePriority[$key]);

			array_unshift($app['router']->middlewarePriority, 'kbs1.encryptedApi-v1');
			$app['router']->middlewarePriority = array_values($app['router']->middlewarePriority);
		});
	}

	public function register()
	{
		$this->mergeConfigFrom(__DIR__ . '/../../config/encrypted_api.php', 'encrypted_api');
	}
}
