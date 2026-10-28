<?php
/**
 * Fronteira WordPress do teste de cotas de cadastro; não implementa regras de negócio.
 *
 * @package Papelito
 */

/** Requisição mínima com corpo e cabeçalhos independentes do endereço do proxy. */
class WP_REST_Request {
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var array
	 */
	private array $body;
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var array
	 */
	private array $headers;
	/** Inicializa os valores recebidos pela fronteira WordPress. */
	public function __construct( array $body, array $headers ) {
		$this->body    = $body;
		$this->headers = $headers;
	}
	/** Devolve o corpo JSON recebido pelo callback. */
	public function get_json_params(): array {
		return $this->body;
	}
	/** Devolve o cabeçalho de candidatura enviado no cenário. */
	public function get_header( string $name ): string {
		return $this->headers[ $name ] ?? '';
	}
}
