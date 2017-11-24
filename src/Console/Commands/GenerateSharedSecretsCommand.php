<?php

namespace Kbs1\EncryptedApiServerLaravel\Console\Commands;

use Illuminate\Console\Command;

use Kbs1\EncryptedApiBase\Exceptions\Cryptography\WeakRandomBytesException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\GenerateSharedSecrets\UnableToLoadDefaultConfigurationException;
use Kbs1\EncryptedApiServerLaravel\Exceptions\GenerateSharedSecrets\UnableToSaveConfigurationException;

use Kbs1\EncryptedApiBase\Cryptography\SharedSecretsGenerator;

class GenerateSharedSecretsCommand extends Command
{
	protected $signature = 'encrypted-api:secrets:generate {--save : Overwrite current config/encrypted_api.php with generated secrets. IPv4 whitelist will be preserved.}';
	protected $description = 'Generate suitable shared secrets for Encrypted API.';

	public function handle()
	{
		$this->callSilent('vendor:publish', ['--tag' => 'encrypted-api']);

		$generator = new SharedSecretsGenerator();
		$secrets = $generator->generateSharedSecrets();

		$secret1 = $this->byteArrayToPhpCode($secrets['secret1']);
		$secret2 = $this->byteArrayToPhpCode($secrets['secret2']);

		if (!$this->option('save')) {
			$this->info('Generation complete! Place the following shared secrets in config/encrypted_api.php config file:');
			$this->line("\t'secret1' => $secret1,\n\t'secret2' => $secret2,\n");
			return;
		}

		try {
			$config = $this->replaceSharedSecrets($this->loadDefaultConfiguration(), $secret1, $secret2);
			$this->writeConfiguration($config);
		} catch (UnableToLoadDefaultConfigurationException $ex) {
			$this->error('Unable to load default package configuration.');
			return 1;
		} catch (UnableToSaveConfigurationException $ex) {
			$this->error('Unable to write config/encrypted_api.php config file.');
			return 1;
		}

		$this->info('Generation complete! Shared secrets were stored in config/encrypted_api.php config file.');
	}

	protected function byteArrayToPhpCode(array $array)
	{
		return '[' . implode(', ', array_map('intval', $array)) . ']';
	}

	protected function loadDefaultConfiguration()
	{
		$config = @file_get_contents(__DIR__ . '/../../../config/encrypted_api.php');
		if ($config === false)
			throw new UnableToLoadDefaultConfigurationException();

		return $config;
	}

	protected function replaceSharedSecrets($config, $secret1, $secret2)
	{
		return str_replace([
			"'secret1' => ''",
			"'secret2' => ''",
			"'ipv4_whitelist' => null",
		], [
			"'secret1' => $secret1",
			"'secret2' => $secret2",
			"'ipv4_whitelist' => " . var_export(config('encrypted_api.ipv4_whitelist'), true),
		], $config);
	}

	protected function writeConfiguration($config)
	{
		if (@file_put_contents(config_path('encrypted_api.php'), $config) === false)
			throw new UnableToSaveConfigurationException();
	}
}
