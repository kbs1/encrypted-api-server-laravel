<?php

namespace Kbs1\EncryptedApiServerLaravel\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Request;
use Illuminate\Routing\Router;

use Kbs1\EncryptedApiServerLaravel\Http\Middleware\EncryptedApi;
use Kbs1\EncryptedApiServerLaravel\Console\Commands\GenerateSharedSecretsCommand;

class EncryptedApiServiceProvider extends ServiceProvider
{
	public function boot(Router $router)
	{
		$this->publishes([__DIR__ . '/../../config/encrypted_api.php' => config_path('encrypted_api.php')], 'encrypted-api');

		$router->aliasMiddleware('kbs1.encryptedApi-v1', EncryptedApi::class);

		if ($this->app->runningInConsole()) {
			$this->commands([
				GenerateSharedSecretsCommand::class,
			]);
		}
	}

	public function register()
	{
		$this->mergeConfigFrom(__DIR__ . '/../../config/encrypted_api.php', 'encrypted_api');
	}
}
