<?php
/**
 * Fronteira WordPress do teste de cotas de cadastro; não implementa regras de negócio.
 *
 * @package Papelito
 */

/** Erro WordPress com o contrato consumido pelos serviços reais. */
class WP_Error {
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var string
	 */
	private string $code;
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var string
	 */
	private string $message;
	/**
	 * Valor consumido pelo contrato da fronteira WordPress.
	 *
	 * @var array
	 */
	private array $data;
	/** Inicializa os valores recebidos pela fronteira WordPress. */
	public function __construct( string $code, string $message = '', array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	/** Devolve o código que identifica o erro REST. */
	public function get_error_code(): string {
		return $this->code;
	}
	/** Devolve a mensagem de validação produzida pelo serviço real. */
	public function get_error_message(): string {
		return $this->message;
	}
	/** Devolve o status HTTP e os dados que o dispatcher serializaria. */
	public function get_error_data(): array {
		return $this->data;
	}
}
