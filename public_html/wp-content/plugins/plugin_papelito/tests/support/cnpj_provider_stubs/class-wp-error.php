<?php
/**
 * Erro mínimo da fronteira HTTP dos testes CNPJ, sem carregar o core ou acessar a rede.
 *
 * @package Papelito
 */

/** Representa uma falha de transporte recebida pelos adapters. */
class WP_Error {
	/**
	 * Código da falha.
	 *
	 * @var string
	 */
	public string $code;

	/**
	 * Mensagem da falha.
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * Contexto da falha.
	 *
	 * @var mixed
	 */
	public mixed $data;

	/** Guarda os campos do erro recebido pela fronteira HTTP. */
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
}
