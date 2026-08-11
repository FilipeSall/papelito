<?php
/**
 * Uploads diretos autorizados por tíquete temporário.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_DIRECT_UPLOAD_MAX_BYTES = 10 * 1024 * 1024;
const PAPELITO_DIRECT_UPLOAD_TICKET_TTL = 300;
const PAPELITO_DIRECT_UPLOAD_PURGE_HOOK = 'papelito_direct_upload_purge_claims_event';

function papelito_direct_upload_error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, array( 'status' => $status ) );
}

function papelito_direct_upload_ticket_key( string $token ): string {
	return 'papelito_upload_' . hash( 'sha256', $token );
}

function papelito_direct_upload_ticket_token(): string {
	return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
}

function papelito_direct_upload_ticket_create( string $purpose, array $context = array() ): array {
	$token = papelito_direct_upload_ticket_token();
	$expires_at = time() + PAPELITO_DIRECT_UPLOAD_TICKET_TTL;
	set_transient(
		papelito_direct_upload_ticket_key( $token ),
		array(
			'purpose'    => $purpose,
			'context'    => $context,
			'expires_at' => $expires_at,
		),
		PAPELITO_DIRECT_UPLOAD_TICKET_TTL
	);

	return array(
		'ticket'    => $token,
		'uploadUrl' => rest_url( 'papelito/v1/uploads/direct' ),
		'expiresAt' => gmdate( 'c', $expires_at ),
	);
}

/**
 * Marca o tiquete como consumido, de forma atomica.
 *
 * `get_transient()` seguido de `delete_transient()` nao e atomico: duas requisicoes concorrentes com
 * o mesmo tiquete liam o valor antes de qualquer delete e as duas passavam. `add_option()` apoia no
 * indice unico de `option_name`, entao so a primeira vence — e o `autoload` fica desligado para o
 * marcador nao entrar no cache de opcoes de toda requisicao.
 *
 * @param string $hash Hash do tiquete.
 * @return bool `true` quando esta chamada foi a que consumiu.
 */
function papelito_direct_upload_ticket_claim( string $hash ): bool {
	return add_option( 'papelito_upload_used_' . $hash, time() + PAPELITO_DIRECT_UPLOAD_TICKET_TTL, '', false );
}

function papelito_direct_upload_ticket_consume( string $token ) {
	if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
		return papelito_direct_upload_error( 'papelito_upload_ticket_invalid', 'Autorização de upload inválida.', 401 );
	}

	if ( ! papelito_direct_upload_ticket_claim( hash( 'sha256', $token ) ) ) {
		return papelito_direct_upload_error( 'papelito_upload_ticket_expired', 'A autorização de upload expirou. Tente novamente.', 401 );
	}

	$key = papelito_direct_upload_ticket_key( $token );
	$ticket = get_transient( $key );
	delete_transient( $key );

	if ( ! is_array( $ticket ) || empty( $ticket['purpose'] ) || (int) ( $ticket['expires_at'] ?? 0 ) < time() ) {
		return papelito_direct_upload_error( 'papelito_upload_ticket_expired', 'A autorização de upload expirou. Tente novamente.', 401 );
	}

	return $ticket;
}

/**
 * Remove os marcadores de tiquete consumido ja vencidos.
 *
 * O marcador tem de sobreviver ao transient (senao o replay volta a passar), mas nao pode acumular
 * em `wp_options` para sempre.
 *
 * @return void
 */
function papelito_direct_upload_purge_claims(): void {
	global $wpdb;

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
			$wpdb->esc_like( 'papelito_upload_used_' ) . '%',
			time()
		)
	);
}
add_action( PAPELITO_DIRECT_UPLOAD_PURGE_HOOK, 'papelito_direct_upload_purge_claims' );

/**
 * Agenda a limpeza dos marcadores de tiquete consumido.
 *
 * @return void
 */
function papelito_direct_upload_schedule_purge(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	if ( ! wp_next_scheduled( PAPELITO_DIRECT_UPLOAD_PURGE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', PAPELITO_DIRECT_UPLOAD_PURGE_HOOK );
	}
}
add_action( 'init', 'papelito_direct_upload_schedule_purge' );

function papelito_direct_upload_file( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	$file = $files['file'] ?? null;

	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
		return papelito_direct_upload_error( 'papelito_upload_missing_file', 'Selecione um arquivo para enviar.', 422 );
	}
	if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
		return papelito_direct_upload_error( 'papelito_upload_receive_error', 'Não foi possível receber o arquivo enviado.', 422 );
	}
	if ( (int) ( $file['size'] ?? 0 ) <= 0 ) {
		return papelito_direct_upload_error( 'papelito_upload_empty_file', 'O arquivo enviado está vazio.', 422 );
	}
	if ( (int) $file['size'] > PAPELITO_DIRECT_UPLOAD_MAX_BYTES ) {
		return papelito_direct_upload_error( 'papelito_upload_file_too_large', 'O arquivo excede o limite de 10 MB.', 413 );
	}

	return $file;
}

/**
 * Traduz a recusa do `wp/v2/media` numa mensagem acionavel.
 *
 * Espelha `wordpressFailure()` do frontend. Quando o upload passava pelo proxy Next era la que a
 * traducao acontecia; no caminho direto o browser fala com o WordPress, entao a mensagem crua
 * ("Sorry, you are not allowed...", "O tipo de arquivo nao e permitido...") chegaria ao admin sem
 * dizer o que fazer. O caso mais comum e o GD do PHP sem WebP/AVIF.
 *
 * @param int         $status Status devolvido pelo endpoint de midia.
 * @param string|null $code   Codigo de erro do WordPress.
 * @return WP_Error
 */
function papelito_direct_upload_media_failure( int $status, ?string $code ): WP_Error {
	$failures = array(
		'rest_cannot_create'                   => array( 'Sua conta não tem permissão para enviar imagens.', 403 ),
		'rest_upload_file_too_big'             => array( 'A imagem excede o tamanho máximo aceito pelo servidor de mídia.', 413 ),
		'rest_upload_image_type_not_supported' => array( 'O servidor de mídia não consegue processar este formato de imagem. Envie a imagem em PNG ou JPEG.', 415 ),
		'rest_upload_invalid_disposition'      => array( 'O arquivo chegou incompleto ao servidor de mídia. Tente enviar novamente.', 422 ),
		'rest_upload_no_content_disposition'   => array( 'O arquivo chegou incompleto ao servidor de mídia. Tente enviar novamente.', 422 ),
		'rest_upload_no_data'                  => array( 'O arquivo chegou vazio ao servidor de mídia. Tente enviar novamente.', 422 ),
		'rest_upload_sideload_error'           => array( 'O servidor de mídia não conseguiu processar a imagem. Tente novamente.', 502 ),
		'rest_upload_unknown_error'            => array( 'O servidor de mídia não conseguiu processar a imagem. Tente novamente.', 502 ),
	);

	if ( null !== $code && isset( $failures[ $code ] ) ) {
		return papelito_direct_upload_error( 'papelito_upload_media_failed', $failures[ $code ][0], $failures[ $code ][1] );
	}

	if ( 401 === $status || 403 === $status ) {
		return papelito_direct_upload_error( 'papelito_upload_media_failed', 'O servidor de mídia recusou a autenticação. Entre novamente e tente de novo.', 403 );
	}

	if ( 413 === $status ) {
		return papelito_direct_upload_error( 'papelito_upload_media_failed', 'A imagem excede o tamanho máximo aceito pelo servidor de mídia.', 413 );
	}

	return papelito_direct_upload_error( 'papelito_upload_media_failed', 'Não foi possível armazenar a imagem no servidor de mídia. Tente novamente.', 502 );
}

function papelito_direct_upload_media( array $ticket, array $file ) {
	$user_id = (int) ( $ticket['context']['user_id'] ?? 0 );
	if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
		return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para enviar esta mídia.', 403 );
	}

	wp_set_current_user( $user_id );

	if ( papelito_media_is_svg_filename( (string) ( $file['name'] ?? '' ) ) ) {
		$svg_error = papelito_media_svg_upload_error( $file );

		if ( '' !== $svg_error ) {
			return papelito_direct_upload_error( 'papelito_upload_svg_invalid', $svg_error, 422 );
		}

		$file['type'] = PAPELITO_MEDIA_SVG_MIME_TYPE;
	} else {
		// O arquivo deixou de passar pelo proxy Next, onde `validateImageUpload()` conferia assinatura,
		// divergencia entre conteudo e extensao e truncamento. A conferencia precisa existir aqui, ou o
		// caminho direto aceita qualquer coisa que o WordPress engula.
		$validated = papelito_direct_upload_validate_image( $file );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$file['name'] = $validated['file_name'];
		$file['type'] = $validated['mime'];
	}

	$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
	$request->set_file_params( array( 'file' => $file ) );
	$response = rest_do_request( $request );
	$data = $response->get_data();

	if ( $response->get_status() >= 400 ) {
		$code = null;
		if ( $data instanceof WP_Error ) {
			$code = $data->get_error_code();
		} elseif ( is_array( $data ) && isset( $data['code'] ) ) {
			$code = sanitize_key( (string) $data['code'] );
		}

		return papelito_direct_upload_media_failure( $response->get_status(), $code );
	}

	return array(
		'media' => array(
			'alt' => is_array( $data ) ? (string) ( $data['alt_text'] ?? '' ) : '',
			'id'  => is_array( $data ) ? (int) ( $data['id'] ?? 0 ) : 0,
			'src' => is_array( $data ) ? (string) ( $data['source_url'] ?? '' ) : '',
		),
	);
}

function papelito_direct_upload_owner_document( array $ticket, array $file ) {
	$user_id = (int) ( $ticket['context']['user_id'] ?? 0 );
	if ( $user_id <= 0 ) {
		return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para enviar este documento.', 403 );
	}

	$result = papelito_company_owner_application_upload( $user_id, $file, (string) ( $ticket['context']['idempotency_key'] ?? '' ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'application' => $result,
		'context'     => papelito_company_context( $user_id ),
	);
}

function papelito_direct_upload_pre_account_document( array $ticket, array $file ) {
	$application_id = (int) ( $ticket['context']['application_id'] ?? 0 );
	if ( $application_id <= 0 ) {
		return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para enviar este documento.', 403 );
	}

	return papelito_pre_account_application_upload_authorized(
		papelito_pre_account_application_authorize_by_id( $application_id ),
		$file
	);
}

function papelito_direct_upload_catalog( array $ticket, array $file ) {
	$user_id = (int) ( $ticket['context']['user_id'] ?? 0 );
	if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
		return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para enviar o catálogo.', 403 );
	}

	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( 'POST', '/papelito/v1/catalog-pdf' );
	$request->set_file_params( array( 'file' => $file ) );
	$response = papelito_catalog_pdf_upload( $request );

	return is_wp_error( $response ) ? $response : $response->get_data();
}

function papelito_direct_upload_ticket_issue( WP_REST_Request $request ) {
	$purpose = sanitize_key( (string) $request->get_param( 'purpose' ) );
	$user_id = get_current_user_id();

	// A emissao do tiquete sempre chega pelo proxy Next, entao o REMOTE_ADDR e o do servidor de
	// frontend e vale igual para a base inteira. Chavear por IP transformava a cota num teto global
	// do marketplace; a identidade real e o usuario (ou a candidatura, quando anonimo).
	$application_token = 'pre-account-document' === $purpose
		? sanitize_text_field( (string) $request->get_header( 'X-Papelito-Application-Token' ) )
		: '';
	$identity          = papelito_rate_limit_identity(
		'' !== $application_token ? 'app:' . hash( 'sha256', $application_token ) : ''
	);

	if ( ! papelito_rate_limit( 'direct_upload_ticket', $identity, 20, 60 ) ) {
		return papelito_direct_upload_error( 'papelito_rate_limited', 'Muitas tentativas. Tente novamente em alguns instantes.', 429 );
	}

	if ( in_array( $purpose, array( 'media', 'catalog' ), true ) ) {
		if ( $user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return papelito_direct_upload_error( 'papelito_upload_not_allowed', 'Você não tem permissão para enviar este arquivo.', 403 );
		}

		return new WP_REST_Response( papelito_direct_upload_ticket_create( $purpose, array( 'user_id' => $user_id ) ), 201 );
	}

	if ( 'owner-document' === $purpose ) {
		if ( $user_id <= 0 ) {
			return papelito_direct_upload_error( 'papelito_upload_not_authenticated', 'Autenticação necessária.', 401 );
		}

		return new WP_REST_Response(
			papelito_direct_upload_ticket_create(
				$purpose,
				array(
					'user_id'         => $user_id,
					'idempotency_key' => wp_generate_uuid4(),
				)
			),
			201
		);
	}

	if ( 'pre-account-document' === $purpose ) {
		$application = papelito_pre_account_application_authorize( $application_token );
		if ( is_wp_error( $application ) ) {
			return $application;
		}

		// Guarda o id, nao o `resume_token`: o transient vive em `wp_options` e o token e credencial.
		return new WP_REST_Response(
			papelito_direct_upload_ticket_create( $purpose, array( 'application_id' => (int) $application['id'] ) ),
			201
		);
	}

	return papelito_direct_upload_error( 'papelito_upload_invalid_purpose', 'Finalidade de upload inválida.', 422 );
}

function papelito_direct_upload_receive( WP_REST_Request $request ) {
	$ticket = papelito_direct_upload_ticket_consume( sanitize_text_field( (string) $request->get_header( 'X-Papelito-Upload-Ticket' ) ) );
	if ( is_wp_error( $ticket ) ) {
		return $ticket;
	}

	$file = papelito_direct_upload_file( $request );
	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$result = match ( (string) $ticket['purpose'] ) {
		'media'                => papelito_direct_upload_media( $ticket, $file ),
		'catalog'              => papelito_direct_upload_catalog( $ticket, $file ),
		'owner-document'       => papelito_direct_upload_owner_document( $ticket, $file ),
		'pre-account-document' => papelito_direct_upload_pre_account_document( $ticket, $file ),
		default                => papelito_direct_upload_error( 'papelito_upload_invalid_purpose', 'Finalidade de upload inválida.', 422 ),
	};

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'papelito/v1',
			'/uploads/tickets',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_direct_upload_ticket_issue',
			)
		);
		register_rest_route(
			'papelito/v1',
			'/uploads/direct',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => 'papelito_direct_upload_receive',
			)
		);
	}
);
