<?php

namespace Kbs1\EncryptedApiServerLaravel\Client;

interface ClientConfigurationProviderInterface
{
	public function getSharedSecrets();
	public function getIpv4Whitelist();
}
