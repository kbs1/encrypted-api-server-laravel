<?php

namespace Kbs1\EncryptedApiServerLaravel\Http;

use Kbs1\EncryptedApiBase\Cryptography\Encryptor;

class OutgoingResponse
{
	protected $response, $secret1, $secret2, $id;

	public function __construct($response, $secret1, $secret2, $id)
	{
		$this->response = $response;
		$this->secret1 = $secret1;
		$this->secret2 = $secret2;
		$this->id = $id;
	}

	public function encrypted()
	{
		$content = $this->response->content();
		$this->response->headers->set('Content-Length', strlen($content));

		$encryptor = new Encryptor($this->response->headers->allPreserveCase(), $content, $this->secret1, $this->secret2, $this->id);

		$this->response->setContent($transmit = $encryptor->getTransmit());

		foreach ($this->response->getOverriddenHeaders() as $header)
			$this->response->headers->remove($header);

		$unencryptedHeaders = $this->response->getUnencryptedHeaders();
		foreach ($this->response->headers->all() as $header => $values)
			if (!in_array($header, $unencryptedHeaders))
				$this->response->headers->remove($header);

		$this->response->headers->set('Content-Type', 'application/json');
		$this->response->headers->set('Content-Length', strlen($transmit));

		return $this->response;
	}
}
