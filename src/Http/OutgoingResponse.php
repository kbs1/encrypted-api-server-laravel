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

		// see which headers should be sent unmanaged
		$headers = $this->response->headers->allPreserveCase();
		foreach ($headers as $header => $values)
			if (in_array(strtolower($header), $this->response->getUnmanagedHeaders()))
				unset($headers[$header]);

		$encryptor = new Encryptor($headers, $content, $this->secret1, $this->secret2, $this->id);

		$this->response->setContent($transmit = $encryptor->getTransmit());

		foreach ($this->response->getOverriddenHeaders() as $header)
			$this->response->headers->remove($header);

		$visibleHeaders = array_merge($this->response->getVisibleHeaders(), $this->response->getUnmanagedHeaders());
		foreach ($this->response->headers->all() as $header => $values)
			if (!in_array($header, $visibleHeaders))
				$this->response->headers->remove($header);

		$this->response->headers->set('Content-Type', 'application/json');
		$this->response->headers->set('Content-Length', strlen($transmit));

		return $this->response;
	}
}
