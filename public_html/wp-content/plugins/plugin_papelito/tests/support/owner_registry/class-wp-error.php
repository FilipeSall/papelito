<?php
/**
 * WordPress error boundary for standalone registry tests.
 *
 * @package Papelito
 */

/** Minimal WordPress error value object. */
class WP_Error {
	/**
	 * Error identifier.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Error description.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * REST metadata.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/** Constructs an error with REST metadata. */
	public function __construct( string $code, string $message, array $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/** Returns the error identifier. */
	public function get_error_code(): string {
		return $this->code;
	}

	/** Returns the REST metadata. */
	public function get_error_data(): array {
		return $this->data;
	}
}
