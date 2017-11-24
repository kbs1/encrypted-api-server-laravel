<?php

namespace Kbs1\EncryptedApiServerLaravel\Http;

use Kbs1\EncryptedApiBase\Cryptography\Encryptor;

class OutgoingResponse
{
	protected $encryptor, $response;

	public function __construct($response, $secret1, $secret2, $id)
	{
		$this->encryptor = new Encryptor($response->headers->all(), $response->content(), $secret1, $secret2, $id);
		$this->response = $response;
	}

	public function encrypt()
	{
		$this->response->setContent($this->encryptor->encrypt());
		$this->response->headers->replace(['Content-Type' => 'application/json']);
	}
}
