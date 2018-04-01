<?php

namespace Kbs1\EncryptedApiServerLaravel\Client;

class ClientConfigurationProvider implements ClientConfigurationProviderInterface
{
	public function getSharedSecrets()
	{
		return ['secret1' => config('encrypted_api.secret1'), 'secret2' => config('encrypted_api.secret2')];
	}

	public function getIpv4Whitelist()
	{
		return config('encrypted_api.ipv4_whitelist');
	}
}
