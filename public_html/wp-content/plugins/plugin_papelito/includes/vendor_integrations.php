<?php
// phpcs:ignoreFile WordPress.Files.FileName.NotHyphenatedLowercase -- Plugin modules use underscore filenames.
/**
 * Integrações de transportadora isoladas por vendor.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_VENDOR_INTEGRATIONS_TABLE       = 'papelito_vendor_integrations';
const PAPELITO_VENDOR_INTEGRATION_AUDIT_TABLE  = 'papelito_vendor_integration_audit';
const PAPELITO_VENDOR_INTEGRATION_PROVIDER     = 'braspress';
const PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED = 'unconfigured';
const PAPELITO_VENDOR_INTEGRATION_READY        = 'ready';
const PAPELITO_VENDOR_INTEGRATION_ACTIVE       = 'active';
const PAPELITO_VENDOR_INTEGRATION_INVALID      = 'invalid_credentials';
const PAPELITO_VENDOR_INTEGRATION_BLOCKED      = 'provider_blocked';

/**
 * Vocabulário fechado do desfecho auditado de uma tentativa de configuração.
 */
const PAPELITO_VENDOR_INTEGRATION_AUDIT_SUCCESS  = 'success';
const PAPELITO_VENDOR_INTEGRATION_AUDIT_DENIED   = 'denied';
const PAPELITO_VENDOR_INTEGRATION_AUDIT_REJECTED = 'rejected';
const PAPELITO_VENDOR_INTEGRATION_AUDIT_FAILED   = 'failed';

/**
 * Desfecho de uma tentativa de gravar a saúde da integração.
 *
 * `stale` e `failed` são deliberadamente distintos: o primeiro diz que a conta
 * que produziu a cotação já não vale e a resposta em trânsito deve ser
 * descartada; o segundo diz que só o registro de saúde falhou, e a cotação
 * continua boa para o comprador.
 */
const PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED = 'applied';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE   = 'stale';
const PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED  = 'failed';

/**
 * Parâmetros contratuais fixos do marketplace, deliberadamente não editáveis.
 *
 * Rodoviário é o único modal com preço viável para papelaria, e CIF é o único
 * tipo compatível com o modelo em que o vendor recebe produtos e frete e paga
 * a transportadora. FOB transferiria a cobrança para o destinatário e
 * consignado exigiria um terceiro pagante, quebrando o repasse integral.
 */
/**
 * Por quanto tempo a trilha da integração é guardada, e por quanto uma recusa
 * repetida é considerada o mesmo fato.
 *
 * A tabela cresce por tentativa, e a tentativa mais barata de repetir é
 * justamente a que já foi recusada: um laço sobre o endpoint bate no limite de
 * escrita e, sem a janela abaixo, escreveria uma linha por requisição — o
 * oposto do que o limite existe para conseguir.
 */
const PAPELITO_VENDOR_INTEGRATION_AUDIT_RETENTION_DAYS = 180;
const PAPELITO_VENDOR_INTEGRATION_AUDIT_REPEAT_WINDOW  = 300;
const PAPELITO_VENDOR_INTEGRATION_AUDIT_PRUNE_HOOK     = 'papelito_vendor_integration_audit_prune';

const PAPELITO_BRASPRESS_CONTRACT_MODAL        = 'R';
const PAPELITO_BRASPRESS_CONTRACT_FREIGHT_TYPE = '1';

/**
 * Prova de reautenticação que sobrevive ao fechamento do modal de senha.
 *
 * O vendor confirma a senha da conta uma vez, recebe um tíquete opaco e o
 * apresenta na mutação seguinte. Sem ele o formulário teria de guardar a senha
 * do sistema no navegador até o envio — exatamente o que a confirmação existe
 * para evitar. A janela é curta porque o tíquete vale o que a senha valeria.
 */
const PAPELITO_VENDOR_INTEGRATION_REAUTH_TTL    = 300;
const PAPELITO_VENDOR_INTEGRATION_REAUTH_PREFIX = 'papelito_vi_reauth_';
const PAPELITO_VENDOR_INTEGRATION_REAUTH_ACTION = 'reauth';

/**
 * Retorna o nome da tabela principal de integrações por vendor.
 *
 * @return string Nome com prefixo WordPress.
 */
function papelito_vendor_integrations_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_VENDOR_INTEGRATIONS_TABLE;
}

/**
 * Retorna o nome da tabela de auditoria sem segredos.
 *
 * @return string Nome com prefixo WordPress.
 */
function papelito_vendor_integration_audit_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . PAPELITO_VENDOR_INTEGRATION_AUDIT_TABLE;
}

/**
 * Cria ou atualiza as tabelas da integração.
 *
 * @return bool Se a rotina foi acionada.
 */
function papelito_vendor_integrations_install_tables(): bool {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();
	$integrations    = papelito_vendor_integrations_table_name();
	$audit           = papelito_vendor_integration_audit_table_name();

	$sql_integrations = "CREATE TABLE {$integrations} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
vendor_id BIGINT UNSIGNED NOT NULL,
provider VARCHAR(32) NOT NULL,
config_json LONGTEXT NOT NULL,
secret_envelope LONGTEXT NULL DEFAULT NULL,
configuration_version INT UNSIGNED NOT NULL DEFAULT 1,
enabled TINYINT(1) NOT NULL DEFAULT 0,
status VARCHAR(32) NOT NULL DEFAULT 'unconfigured',
last_successful_quote_at DATETIME NULL DEFAULT NULL,
last_health_checked_at DATETIME NULL DEFAULT NULL,
last_error_category VARCHAR(64) NULL DEFAULT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
created_by BIGINT UNSIGNED NULL DEFAULT NULL,
updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uq_vendor_provider (vendor_id, provider),
KEY idx_provider_eligibility (provider, enabled, status),
KEY idx_vendor_provider (vendor_id, provider)
) {$charset_collate};";

	$sql_audit = "CREATE TABLE {$audit} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
vendor_id BIGINT UNSIGNED NOT NULL,
provider VARCHAR(32) NOT NULL,
actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
action VARCHAR(40) NOT NULL,
status VARCHAR(16) NOT NULL DEFAULT 'success',
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY idx_vendor_created (vendor_id, created_at),
KEY idx_provider_created (provider, created_at),
KEY idx_status_created (status, created_at)
) {$charset_collate};";

	dbDelta( $sql_integrations );
	dbDelta( $sql_audit );

	return true;
}

/**
 * Fornece a estrutura pública de configuração ainda vazia.
 *
 * @return array<string,string> Configuração sem segredos.
 */
function papelito_vendor_integration_empty_config(): array {
	return array(
		'sender_cnpj'           => '',
		'origin_cep'            => '',
		'modal'                 => '',
		'freight_type'          => '',
		'tracking_tomador_cnpj' => '',
	);
}

/**
 * Descarta tudo que não for dígito de um valor de formulário ou de meta.
 *
 * Documento e CEP chegam formatados de origens diferentes e a API só aceita
 * dígitos. Quem chama continua usando o normalizador do seu domínio, para a
 * intenção não se perder na chamada.
 *
 * @param mixed $value Valor a normalizar.
 * @return string Valor somente com dígitos.
 */
function papelito_vendor_integration_digits( $value ): string {
	$digits = preg_replace( PAPELITO_DIGITS_REGEX, '', (string) $value );

	return is_string( $digits ) ? $digits : '';
}

/**
 * Mantém somente os dígitos de CPF/CNPJ usados pela API.
 *
 * @param mixed $value Documento a normalizar.
 * @return string Documento numérico.
 */
function papelito_vendor_integration_normalize_document( $value ): string {
	return papelito_vendor_integration_digits( $value );
}

/**
 * Mantém somente os dígitos de um CEP.
 *
 * @param mixed $value CEP a normalizar.
 * @return string CEP numérico.
 */
function papelito_vendor_integration_normalize_cep( $value ): string {
	return papelito_vendor_integration_digits( $value );
}

/**
 * Normaliza o CEP de origem declarado pelo vendor para o contrato Braspress.
 *
 * O contrato é de cada vendor e fica amarrado a uma origem específica, que
 * pode ser um centro de distribuição diferente do endereço cadastrado. Por
 * isso a origem é declarável, e não derivada à força.
 *
 * @param mixed $value CEP informado no formulário.
 * @return string|null CEP com oito dígitos, vazio quando não informado ou nulo quando inválido.
 */
function papelito_vendor_integration_normalize_origin_cep( $value ): ?string {
	$cep = papelito_vendor_integration_normalize_cep( $value );
	if ( '' === $cep ) {
		return '';
	}

	return 8 === strlen( $cep ) && '00000000' !== $cep ? $cep : null;
}

/**
 * Monta os parâmetros contratuais do vendor sem pedir o que já está cadastrado.
 *
 * Só a origem é declarável. O CNPJ remetente vem do cadastro porque precisa ser
 * o mesmo que emite a nota, e a API aceita qualquer CNPJ enviado — digitá-lo de
 * novo permitiria cotar no contrato de outra empresa. Modal e tipo de frete são
 * fixos por decisão de marketplace, e em CIF o tomador do frete é o remetente.
 *
 * @param int   $vendor_id ID do vendor.
 * @param mixed $stored_json Configuração persistida da integração.
 * @return array<string,string> Configuração efetiva, com campos vazios quando o cadastro não permite cotar.
 */
function papelito_vendor_integration_braspress_config( int $vendor_id, $stored_json = '' ): array {
	$config = papelito_vendor_integration_empty_config();
	if ( $vendor_id <= 0 ) {
		return $config;
	}

	$cnpj = papelito_vendor_integration_normalize_document( get_user_meta( $vendor_id, 'cnpj', true ) );
	if ( 14 === strlen( $cnpj ) && ( ! function_exists( 'papelito_validate_cnpj' ) || papelito_validate_cnpj( $cnpj ) ) ) {
		$config['sender_cnpj']           = $cnpj;
		$config['tracking_tomador_cnpj'] = $cnpj;
	}

	$stored    = is_string( $stored_json ) ? json_decode( $stored_json, true ) : null;
	$declared  = is_array( $stored ) ? papelito_vendor_integration_normalize_origin_cep( $stored['origin_cep'] ?? '' ) : '';
	$fallback  = papelito_vendor_integration_normalize_origin_cep( get_user_meta( $vendor_id, 'cep', true ) );
	$origin    = ! empty( $declared ) ? $declared : $fallback;

	if ( ! empty( $origin ) ) {
		$config['origin_cep'] = $origin;
	}

	$config['modal']        = PAPELITO_BRASPRESS_CONTRACT_MODAL;
	$config['freight_type'] = PAPELITO_BRASPRESS_CONTRACT_FREIGHT_TYPE;

	return $config;
}

/**
 * Verifica se todos os parâmetros necessários à cotação estão presentes.
 *
 * @param array<string,mixed> $config Configuração derivada.
 * @return bool Se a configuração é suficiente.
 */
function papelito_vendor_integration_config_complete( array $config ): bool {
	foreach ( papelito_vendor_integration_empty_config() as $field => $unused ) {
		if ( empty( $config[ $field ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Monta a representação pública, omitindo envelope e credenciais.
 *
 * @param array<string,mixed>|null $row Linha persistida.
 * @param int                      $vendor_id Vendor consultado, quando ainda não há linha.
 * @return array<string,mixed> Estado seguro para REST.
 */
function papelito_vendor_integration_public_record( ?array $row = null, int $vendor_id = 0 ): array {
	$row    = is_array( $row ) ? $row : array();
	$config = papelito_vendor_integration_braspress_config( (int) ( $row['vendor_id'] ?? $vendor_id ), $row['config_json'] ?? '' );

	return array(
		'provider'                 => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'enabled'                  => ! empty( $row['enabled'] ),
		'status'                   => sanitize_key( (string) ( $row['status'] ?? PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED ) ),
		'configuration_version'    => max( 0, (int) ( $row['configuration_version'] ?? 0 ) ),
		'configured'               => papelito_vendor_integration_config_complete( $config ),
		'credentials_configured'   => ! empty( $row['secret_envelope'] ),
		'config'                   => $config,
		'last_successful_quote_at' => $row['last_successful_quote_at'] ?? null,
		'last_error_category'      => $row['last_error_category'] ?? null,
	);
}

/**
 * Busca a única integração de um provider para um vendor.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $provider Identificador do provider.
 * @return array<string,mixed>|null Linha encontrada.
 */
function papelito_vendor_integration_find_row( int $vendor_id, string $provider = PAPELITO_VENDOR_INTEGRATION_PROVIDER ): ?array {
	global $wpdb;

	$table = papelito_vendor_integrations_table_name();
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives exclusively from $wpdb->prefix.
			"SELECT * FROM {$table} WHERE vendor_id = %d AND provider = %s LIMIT 1",
			$vendor_id,
			$provider
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * Registra uma ação sensível sem incluir configuração ou segredo.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $provider Identificador do provider.
 * @param int    $actor_user_id ID do autor autenticado.
 * @param string $action Ação auditável.
 * @param string $status Desfecho da tentativa, do vocabulário fechado.
 * @return void
 */
function papelito_vendor_integration_audit( int $vendor_id, string $provider, int $actor_user_id, string $action, string $status = PAPELITO_VENDOR_INTEGRATION_AUDIT_SUCCESS ): void {
	global $wpdb;

	$written = $wpdb->insert(
		papelito_vendor_integration_audit_table_name(),
		array(
			'vendor_id'     => $vendor_id,
			'provider'      => $provider,
			'actor_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
			'action'        => sanitize_key( $action ),
			'status'        => sanitize_key( $status ),
			'created_at'    => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%d', '%s', '%s', '%s' )
	);

	if ( false === $written ) {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf( 'papelito_vendor_integration_audit_failed vendor=%d action=%s', $vendor_id, sanitize_key( $action ) )
		);
	}
}

/**
 * Nome do transient que marca uma recusa já registrada nesta janela.
 *
 * @param int    $actor_user_id ID de quem tentou.
 * @param string $reason Motivo da recusa, do vocabulário fechado.
 * @return string Nome do transient.
 */
function papelito_vendor_integration_audit_repeat_key( int $actor_user_id, string $reason ): string {
	return 'papelito_vi_denied_' . sanitize_key( $reason ) . '_' . $actor_user_id;
}

/**
 * Diz se esta recusa já foi registrada na janela, e marca a primeira.
 *
 * Só recusa **repetível de graça** passa por aqui: limite de escrita estourado e
 * porteiro barrando. As duas são o mesmo fato repetido, e uma linha por
 * requisição transformaria a trilha de segurança num contador de retentativas —
 * sem trazer informação nova e sem teto, porque nenhuma das duas chega a
 * executar trabalho que o limite pudesse conter. Recusa que depende de acertar
 * dado — senha atual errada, CEP inválido — continua linha a linha, porque cada
 * uma é uma tentativa diferente.
 *
 * @param int    $actor_user_id ID de quem tentou.
 * @param string $reason Motivo da recusa.
 * @return bool Se esta recusa já tem linha nesta janela.
 */
function papelito_vendor_integration_audit_is_repeat( int $actor_user_id, string $reason ): bool {
	$key = papelito_vendor_integration_audit_repeat_key( $actor_user_id, $reason );
	if ( false !== get_transient( $key ) ) {
		return true;
	}

	set_transient( $key, 1, PAPELITO_VENDOR_INTEGRATION_AUDIT_REPEAT_WINDOW );

	return false;
}

/**
 * Apaga a trilha que passou da janela de retenção.
 *
 * A tabela só cresce, e nenhuma pergunta de segurança é respondida por linha de
 * meio ano atrás: o que se investiga é alteração recente de credencial. Sem esta
 * varredura, os três índices degradam para sempre por causa de tentativa que já
 * não interessa a ninguém.
 *
 * @return void
 */
function papelito_vendor_integration_audit_prune(): void {
	global $wpdb;

	$table  = papelito_vendor_integration_audit_table_name();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - PAPELITO_VENDOR_INTEGRATION_AUDIT_RETENTION_DAYS * DAY_IN_SECONDS );

	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derives exclusively from $wpdb->prefix.
			"DELETE FROM {$table} WHERE created_at < %s",
			$cutoff
		)
	);
}

/**
 * Agenda a varredura diária da trilha.
 *
 * @return void
 */
function papelito_vendor_integration_schedule_audit_prune(): void {
	if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
		return;
	}

	if ( ! wp_next_scheduled( PAPELITO_VENDOR_INTEGRATION_AUDIT_PRUNE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', PAPELITO_VENDOR_INTEGRATION_AUDIT_PRUNE_HOOK );
	}
}

/**
 * Traduz o erro de uma tentativa recusada no status auditável.
 *
 * A separação importa na leitura do histórico: `denied` é alguém que não podia
 * fazer aquilo — autorização, reautenticação, limite de escrita —, `rejected` é
 * dado que o próprio vendor errou no formulário, e `failed` é defeito do
 * Papelito. Fundir os três num "erro" só transformaria tentativa de invasão em
 * ruído de validação.
 *
 * @param WP_Error $error Erro devolvido pela operação.
 * @return string Status do vocabulário fechado.
 */
function papelito_vendor_integration_audit_status( WP_Error $error ): string {
	$data   = $error->get_error_data();
	$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;

	if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
		return PAPELITO_VENDOR_INTEGRATION_AUDIT_DENIED;
	}

	return $status >= 400 && $status < 500
		? PAPELITO_VENDOR_INTEGRATION_AUDIT_REJECTED
		: PAPELITO_VENDOR_INTEGRATION_AUDIT_FAILED;
}

/**
 * Diz se o corpo declarou aquele campo como texto não vazio.
 *
 * O corpo chega cru de `get_json_params()`, sem schema de rota, então o campo
 * pode ser array ou objeto. Converter isso para texto emitiria um aviso de PHP a
 * cada tentativa, num caminho que o atacante controla.
 *
 * @param mixed $value Valor bruto do corpo.
 * @return bool Se há texto declarado.
 */
function papelito_vendor_integration_declares_text( mixed $value ): bool {
	return is_string( $value ) && '' !== $value;
}

/**
 * Deduz do corpo da requisição qual ação o ator pretendia executar.
 *
 * Uma tentativa recusada nunca chega ao ponto que nomeia a ação, e auditar
 * "erro" sem dizer o que se tentou mudar não responde à pergunta de segurança:
 * quem tentou trocar a credencial e foi barrado. O corpo é lido só pela forma —
 * se os campos existem —, nunca pelo valor.
 *
 * @param array<string,mixed> $payload Corpo da requisição.
 * @return string Ação pretendida.
 */
function papelito_vendor_integration_intended_action( array $payload ): string {
	if ( ! empty( $payload['removeCredentials'] ) || ! empty( $payload['remove_credentials'] ) ) {
		return 'credentials_removed';
	}

	$declares_credentials = papelito_vendor_integration_declares_text( $payload['username'] ?? null )
		|| papelito_vendor_integration_declares_text( $payload['password'] ?? null );

	return $declares_credentials ? 'credentials_saved' : 'configuration_saved';
}

/**
 * Audita a tentativa recusada e devolve o resultado intocado.
 *
 * Fica fora do corpo da operação de propósito: auditar em cada `return` de erro
 * espalharia a regra por oito ramos e o próximo ramo novo nasceria sem rastro.
 *
 * @param int    $vendor_id ID do vendor alvo.
 * @param int    $actor_user_id ID de quem tentou.
 * @param string $action Ação pretendida.
 * @param mixed  $result Resultado da operação.
 * @return mixed O mesmo resultado recebido.
 */
function papelito_vendor_integration_audit_attempt( int $vendor_id, int $actor_user_id, string $action, mixed $result ): mixed {
	if ( ! is_wp_error( $result ) ) {
		return $result;
	}

	$rate_limited = 'papelito_vendor_integration_rate_limited' === $result->get_error_code();
	if ( $rate_limited && papelito_vendor_integration_audit_is_repeat( $actor_user_id, 'rate_limited' ) ) {
		return $result;
	}

	papelito_vendor_integration_audit(
		$vendor_id,
		PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		$actor_user_id,
		$action,
		papelito_vendor_integration_audit_status( $result )
	);

	return $result;
}

/**
 * Notifica uma alteração sensível sem incluir configuração ou segredo.
 *
 * O hook permite que a operação central envie alerta adicional sem acoplar a
 * integração a um destino específico. O e-mail para o titular reduz o tempo
 * de detecção de uma alteração indevida da credencial write-only.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $action Ação sensível ocorrida.
 * @return void
 */
function papelito_vendor_integration_security_event( int $vendor_id, string $action ): void {
	$action = sanitize_key( $action );
	do_action( 'papelito_vendor_integration_security_event', $vendor_id, PAPELITO_VENDOR_INTEGRATION_PROVIDER, $action );

	if ( ! in_array( $action, array( 'credentials_saved', 'credentials_removed', 'removed' ), true ) ) {
		return;
	}

	$user = get_userdata( $vendor_id );
	if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
		return;
	}

	wp_mail(
		$user->user_email,
		'Papelito: alteração na integração Braspress',
		'A integração Braspress da sua loja teve uma alteração de credencial ou foi removida. Nenhum segredo é enviado por este e-mail. Se você não reconhece a alteração, entre em contato com o suporte.'
	);
}

/**
 * Decide se a transição pedida ainda vale para a configuração que está no banco.
 *
 * Uma cotação leva até 15 segundos, e nesse intervalo o vendor pode desabilitar
 * a integração, trocar a credencial ou mudar a origem. Sem esta comparação, a
 * resposta que chega depois marcaria `active` uma conta que já não existe.
 *
 * Credencial recusada prevalece sobre cotação boa da **mesma** versão: as duas
 * saíram com a mesma conta, e reativar por causa da ordem de chegada esconderia
 * do vendor que a senha precisa ser trocada. Só uma versão nova tira a
 * integração de `invalid_credentials`.
 *
 * @param mixed  $row Linha corrente da integração, ou nulo quando não existe.
 * @param string $status Estado que se quer aplicar.
 * @param int    $expected_version Versão que originou a tentativa; zero dispensa a comparação.
 * @return bool Se a transição ainda é válida.
 */
function papelito_vendor_integration_health_is_current( mixed $row, string $status, int $expected_version ): bool {
	if ( ! is_array( $row ) ) {
		return false;
	}

	if ( $expected_version > 0 && (int) ( $row['configuration_version'] ?? 0 ) !== $expected_version ) {
		return false;
	}

	$current = sanitize_key( (string) ( $row['status'] ?? '' ) );

	return ! ( PAPELITO_VENDOR_INTEGRATION_INVALID === $current && PAPELITO_VENDOR_INTEGRATION_ACTIVE === $status );
}

/**
 * Monta os campos de saúde gravados na integração.
 *
 * @param string $status Estado alcançado.
 * @param string $error_category Categoria pública e redigida do erro.
 * @return array<string,mixed> Campos a gravar.
 */
function papelito_vendor_integration_health_columns( string $status, string $error_category ): array {
	$now  = current_time( 'mysql', true );
	$data = array(
		'status'                 => $status,
		'last_health_checked_at' => $now,
		'last_error_category'    => '' !== $error_category ? sanitize_key( $error_category ) : null,
		'updated_at'             => $now,
	);

	if ( PAPELITO_VENDOR_INTEGRATION_ACTIVE === $status ) {
		$data['last_successful_quote_at'] = $now;
	}

	return $data;
}

/**
 * Aplica a saúde da integração somente sobre a versão que originou a tentativa.
 *
 * Distingue três desfechos porque quem chama reage a cada um de um jeito:
 * `applied` segue o fluxo, `stale` **descarta a cotação em trânsito** — a conta
 * que a produziu já não vale — e `failed` preserva a cotação, porque o comprador
 * não pode perder um frete válido só porque o contador de saúde não gravou.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $status Novo estado operacional permitido.
 * @param string $error_category Categoria pública e redigida do erro.
 * @param int    $expected_version Versão de configuração que originou a tentativa.
 * @return string Desfecho do vocabulário `PAPELITO_VENDOR_INTEGRATION_HEALTH_*`.
 */
function papelito_vendor_integration_apply_braspress_health( int $vendor_id, string $status, string $error_category = '', int $expected_version = 0 ): string {
	global $wpdb;

	if ( ! in_array( $status, array( PAPELITO_VENDOR_INTEGRATION_ACTIVE, PAPELITO_VENDOR_INTEGRATION_INVALID, PAPELITO_VENDOR_INTEGRATION_BLOCKED ), true ) ) {
		return PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE;
	}

	$row = papelito_vendor_integration_find_row( $vendor_id );
	if ( ! papelito_vendor_integration_health_is_current( $row, $status, $expected_version ) ) {
		return PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE;
	}

	$previous = sanitize_key( (string) ( $row['status'] ?? PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED ) );
	$data     = papelito_vendor_integration_health_columns( $status, $error_category );
	$where    = array(
		'vendor_id' => $vendor_id,
		'provider'  => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'status'    => $previous,
	);
	if ( $expected_version > 0 ) {
		$where['configuration_version'] = $expected_version;
	}

	$written = $wpdb->update(
		papelito_vendor_integrations_table_name(),
		$data,
		$where,
		array_fill( 0, count( $data ), '%s' ),
		array_fill( 0, count( $where ), '%s' )
	);

	if ( false === $written ) {
		return PAPELITO_VENDOR_INTEGRATION_HEALTH_FAILED;
	}

	if ( 0 === (int) $written ) {
		return papelito_vendor_integration_health_recheck( $vendor_id, $status, $expected_version );
	}

	papelito_vendor_integration_announce_health_change( $vendor_id, $previous, $status, $error_category );

	return PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED;
}

/**
 * Desempata o `UPDATE` que não afetou linha nenhuma.
 *
 * O MySQL devolve zero em dois casos opostos: a cláusula não casou — alguém
 * gravou entre o `SELECT` e o `UPDATE` — ou casou e os valores já eram os
 * mesmos, que é o que acontece quando duas cotações boas terminam no mesmo
 * segundo. Tratar os dois como obsolescência descartaria cotação válida; tratar
 * os dois como sucesso reabriria a corrida. Só relendo dá para saber qual foi.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $status Estado que se queria aplicar.
 * @param int    $expected_version Versão que originou a tentativa.
 * @return string `applied` quando a linha já está no estado desejado, `stale` caso contrário.
 */
function papelito_vendor_integration_health_recheck( int $vendor_id, string $status, int $expected_version ): string {
	$row = papelito_vendor_integration_find_row( $vendor_id );
	if ( ! papelito_vendor_integration_health_is_current( $row, $status, $expected_version ) ) {
		return PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE;
	}

	return sanitize_key( (string) ( $row['status'] ?? '' ) ) === $status
		? PAPELITO_VENDOR_INTEGRATION_HEALTH_APPLIED
		: PAPELITO_VENDOR_INTEGRATION_HEALTH_STALE;
}

/**
 * Estados operacionais em que a integração existe mas não consegue cotar.
 *
 * @param string $status Estado operacional.
 * @return bool Se o estado impede a Braspress de participar.
 */
function papelito_vendor_integration_is_degraded( string $status ): bool {
	return in_array( $status, array( PAPELITO_VENDOR_INTEGRATION_INVALID, PAPELITO_VENDOR_INTEGRATION_BLOCKED ), true );
}

/**
 * Alerta somente quando a saúde da integração muda, nunca a cada cotação.
 *
 * Sem o recorte por transição, uma credencial vencida dispararia um alerta por
 * checkout e o canal viraria ruído em uma tarde. A recuperação também é
 * publicada, porque um alerta que só abre e nunca fecha obriga o operador a
 * conferir o painel para saber se o problema acabou.
 *
 * @param int    $vendor_id ID interno do vendor.
 * @param string $previous Estado anterior à escrita.
 * @param string $status Estado alcançado.
 * @param string $error_category Categoria pública e redigida do erro.
 * @return void
 */
function papelito_vendor_integration_announce_health_change( int $vendor_id, string $previous, string $status, string $error_category ): void {
	$was_degraded = papelito_vendor_integration_is_degraded( $previous );
	$is_degraded  = papelito_vendor_integration_is_degraded( $status );

	if ( $was_degraded === $is_degraded || ! function_exists( 'papelito_shipping_provider_alert' ) ) {
		return;
	}

	papelito_shipping_provider_alert(
		PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		$status,
		array(
			'vendor_id'      => $vendor_id,
			'previous_state' => $previous,
			'error_category' => $error_category,
		)
	);
}

/**
 * Exige a senha atual antes de mutações de credencial.
 *
 * @param array<string,mixed> $payload Corpo da requisição.
 * @param int                 $vendor_id ID do vendor.
 * @return true|WP_Error Resultado da reautenticação.
 */
function papelito_vendor_integration_verify_current_password( array $payload, int $vendor_id ) {
	$password = (string) ( $payload['currentPassword'] ?? $payload['current_password'] ?? '' );
	$user     = get_user_by( 'id', $vendor_id );

	if ( ! $user instanceof WP_User || '' === $password || ! wp_check_password( $password, $user->user_pass, $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_current_password_invalid', 'Não foi possível confirmar a senha atual.', array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Nome do transient que guarda o tíquete vigente daquele vendor.
 *
 * @param int $vendor_id ID do vendor.
 * @return string Nome do transient.
 */
function papelito_vendor_integration_reauth_key( int $vendor_id ): string {
	return PAPELITO_VENDOR_INTEGRATION_REAUTH_PREFIX . $vendor_id;
}

/**
 * Emite um tíquete novo e invalida o anterior daquele vendor.
 *
 * O transient guarda só o HMAC: quem ler a tabela de opções não sai de lá com um
 * tíquete utilizável. O valor em claro existe uma única vez, na resposta.
 *
 * @param int $vendor_id ID do vendor já reautenticado.
 * @return string Tíquete em claro.
 */
function papelito_vendor_integration_reauth_issue( int $vendor_id ): string {
	$ticket = wp_generate_password( 48, false, false );

	set_transient(
		papelito_vendor_integration_reauth_key( $vendor_id ),
		hash_hmac( 'sha256', $ticket, wp_salt( 'auth' ) ),
		PAPELITO_VENDOR_INTEGRATION_REAUTH_TTL
	);

	return $ticket;
}

/**
 * Lê o tíquete declarado no corpo, sem convertê-lo a texto.
 *
 * @param array<string,mixed> $payload Corpo da requisição.
 * @return mixed Valor bruto do campo.
 */
function papelito_vendor_integration_reauth_declared( array $payload ): mixed {
	return $payload['reauthTicket'] ?? $payload['reauth_ticket'] ?? null;
}

/**
 * Diz se o corpo apresenta o tíquete vigente daquele vendor.
 *
 * Não apaga nada: quem queima o tíquete é a mutação que gravou, em
 * `papelito_vendor_integration_reauth_forget()`. Assim um CEP inválido recusado
 * no meio do caminho não obriga o vendor a confirmar a senha de novo.
 *
 * @param array<string,mixed> $payload Corpo da requisição.
 * @param int                 $vendor_id ID do vendor.
 * @return bool Se o tíquete confere.
 */
function papelito_vendor_integration_reauth_matches( array $payload, int $vendor_id ): bool {
	$ticket = papelito_vendor_integration_reauth_declared( $payload );
	if ( ! papelito_vendor_integration_declares_text( $ticket ) ) {
		return false;
	}

	$stored = get_transient( papelito_vendor_integration_reauth_key( $vendor_id ) );

	return is_string( $stored ) && hash_equals( $stored, hash_hmac( 'sha256', (string) $ticket, wp_salt( 'auth' ) ) );
}

/**
 * Descarta o tíquete vigente daquele vendor.
 *
 * @param int $vendor_id ID do vendor.
 * @return void
 */
function papelito_vendor_integration_reauth_forget( int $vendor_id ): void {
	delete_transient( papelito_vendor_integration_reauth_key( $vendor_id ) );
}

/**
 * Porteiro único do step-up das mutações sensíveis.
 *
 * Aceita as duas provas de identidade: o tíquete emitido pelo modal de senha e a
 * senha crua no corpo, que continua valendo para quem chama a rota direto. Corpo
 * que não declara tíquete nenhum cai na senha, para o erro devolvido continuar
 * sendo o que o vendor sabe corrigir.
 *
 * @param array<string,mixed> $payload Corpo da requisição.
 * @param int                 $vendor_id ID do vendor.
 * @return true|WP_Error Resultado da reautenticação.
 */
function papelito_vendor_integration_require_reauth( array $payload, int $vendor_id ) {
	if ( papelito_vendor_integration_reauth_matches( $payload, $vendor_id ) ) {
		return true;
	}

	if ( papelito_vendor_integration_declares_text( papelito_vendor_integration_reauth_declared( $payload ) ) ) {
		return new WP_Error( 'papelito_vendor_integration_reauth_ticket_invalid', 'A confirmação de senha não vale mais.', array( 'status' => 403 ) );
	}

	return papelito_vendor_integration_verify_current_password( $payload, $vendor_id );
}

/**
 * Salva parâmetros e, opcionalmente, substitui a credencial write-only.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return array<string,mixed>|WP_Error Estado público ou erro.
 */
function papelito_vendor_integration_save_braspress( int $vendor_id, array $payload, int $actor_user_id ) {
	return papelito_vendor_integration_audit_attempt(
		$vendor_id,
		$actor_user_id,
		papelito_vendor_integration_intended_action( $payload ),
		papelito_vendor_integration_apply_braspress_save( $vendor_id, $payload, $actor_user_id )
	);
}

/**
 * Nomeia a ação efetivamente gravada, para a auditoria e para o aviso ao titular.
 *
 * @param bool $credentials_changed Se o segredo foi substituído ou removido.
 * @param bool $secret_removed Se a integração ficou sem envelope.
 * @return string Ação do vocabulário auditável.
 */
function papelito_vendor_integration_saved_action( bool $credentials_changed, bool $secret_removed ): string {
	if ( ! $credentials_changed ) {
		return 'configuration_saved';
	}

	return $secret_removed ? 'credentials_removed' : 'credentials_saved';
}

/**
 * Marca na trilha quando quem gravou não foi o próprio vendor.
 *
 * O `actor_user_id` sozinho já diferencia os dois caminhos, mas exige cruzar a
 * tabela de usuários para ler a trilha. O prefixo responde à pergunta "quem
 * mexeu nesta integração" direto na linha, que é como a auditoria é consultada
 * durante um incidente.
 *
 * @param string $action Ação do vocabulário do vendor.
 * @param int    $vendor_id ID do vendor dono da integração.
 * @param int    $actor_user_id ID de quem assinou a gravação.
 * @return string Ação auditável, prefixada quando a gravação é administrativa.
 */
function papelito_vendor_integration_audited_action( string $action, int $vendor_id, int $actor_user_id ): string {
	return $vendor_id === $actor_user_id ? $action : 'admin_' . $action;
}

/**
 * Executa a gravação da configuração, sem se ocupar da auditoria da tentativa.
 *
 * Nenhuma variação do `PUT` pede step-up de identidade: cadastrar, substituir ou
 * apagar a credencial write-only, mudar o CEP de origem e ligar o interruptor
 * são todos corrigíveis pela própria tela e ficam na trilha de auditoria. Só a
 * remoção da integração, que é destrutiva, continua exigindo a senha da conta.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return array<string,mixed>|WP_Error Estado público ou erro.
 */
function papelito_vendor_integration_apply_braspress_save( int $vendor_id, array $payload, int $actor_user_id ) {
	$guard = papelito_vendor_integration_guard_braspress_save( $vendor_id, $payload, $actor_user_id );
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	return papelito_vendor_integration_write_braspress( $vendor_id, $payload, $actor_user_id );
}

/**
 * Grava a configuração já autorizada, sem decidir quem pode gravá-la.
 *
 * Esta é a única escrita da integração, e os dois painéis chegam nela por
 * portas diferentes: o do vendor passa antes por
 * `papelito_vendor_integration_guard_braspress_save()`, que cobra titularidade e
 * limite de tentativas; o administrativo passa pela capability em
 * `papelito_admin_vendor_integration_save_braspress()`. Quem chama daqui já
 * respondeu à pergunta da autorização — a função não a repete, e por isso não
 * deve ser exposta diretamente a nenhuma rota.
 *
 * @param int                 $vendor_id ID do vendor dono da integração.
 * @param array<string,mixed> $payload Corpo já autorizado da requisição.
 * @param int                 $actor_user_id ID de quem assina a gravação na trilha.
 * @return array<string,mixed>|WP_Error Estado público ou erro.
 */
function papelito_vendor_integration_write_braspress( int $vendor_id, array $payload, int $actor_user_id ) {
	$origin_cep = papelito_vendor_integration_normalize_origin_cep( $payload['origin_cep'] ?? $payload['originCep'] ?? '' );
	if ( null === $origin_cep ) {
		return new WP_Error( 'papelito_vendor_integration_invalid_origin_cep', 'Informe um CEP de origem com 8 dígitos.', array( 'status' => 422 ) );
	}

	$config   = papelito_vendor_integration_braspress_config( $vendor_id, wp_json_encode( array( 'origin_cep' => $origin_cep ) ) );
	$complete = papelito_vendor_integration_config_complete( $config );
	$enabled  = isset( $payload['enabled'] ) && true === filter_var( $payload['enabled'], FILTER_VALIDATE_BOOLEAN );

	if ( $enabled && ! $complete ) {
		return new WP_Error(
			'papelito_vendor_integration_profile_incomplete',
			'Informe o CEP de origem e confirme o CNPJ do cadastro da sua loja antes de habilitar a Braspress.',
			array( 'status' => 422 )
		);
	}

	$row      = papelito_vendor_integration_find_row( $vendor_id );
	$existing = is_array( $row ) ? $row : array();

	$credentials = papelito_vendor_integration_resolve_secret_envelope( $payload, $existing );
	if ( is_wp_error( $credentials ) ) {
		return $credentials;
	}

	$secret_envelope     = $credentials['envelope'];
	$credentials_changed = $credentials['changed'];

	$stored_origin  = papelito_vendor_integration_braspress_config( $vendor_id, $existing['config_json'] ?? '' );
	$origin_changed = ( $stored_origin['origin_cep'] ?? '' ) !== ( $config['origin_cep'] ?? '' );
	$previous       = sanitize_key( (string) ( $existing['status'] ?? PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED ) );
	$status         = papelito_vendor_integration_next_status( $previous, $complete, $secret_envelope, $credentials_changed, $origin_changed );

	$now  = current_time( 'mysql', true );
	$data = array(
		'config_json'           => wp_json_encode( array( 'origin_cep' => $origin_cep ) ),
		'secret_envelope'       => $secret_envelope,
		'configuration_version' => max( 0, (int) ( $existing['configuration_version'] ?? 0 ) ) + 1,
		'enabled'               => $enabled ? 1 : 0,
		'status'                => $status,
		'updated_at'            => $now,
		'updated_by'            => $actor_user_id,
	);

	if ( ! papelito_vendor_integration_write_row( $vendor_id, $data, $existing, $actor_user_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_save_failed', 'Não foi possível salvar a integração.', array( 'status' => 500 ) );
	}

	if ( $credentials_changed ) {
		papelito_vendor_integration_reauth_forget( $vendor_id );
	}

	$action = papelito_vendor_integration_saved_action( $credentials_changed, empty( $secret_envelope ) );
	papelito_vendor_integration_announce_health_change( $vendor_id, $previous, $status, '' );
	papelito_vendor_integration_audit(
		$vendor_id,
		PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		$actor_user_id,
		papelito_vendor_integration_audited_action( $action, $vendor_id, $actor_user_id ),
		PAPELITO_VENDOR_INTEGRATION_AUDIT_SUCCESS
	);
	papelito_vendor_integration_security_event( $vendor_id, $action );
	papelito_vendor_integration_probe_braspress_credentials( $vendor_id, $enabled, $status );

	return papelito_vendor_integration_public_record( papelito_vendor_integration_find_row( $vendor_id ) );
}

/**
 * Confere na Braspress a credencial que acabou de ser gravada.
 *
 * Sem isto o vendor habilita a integração com usuário e senha errados e só
 * descobre quando um comprador tenta cotar, porque nada é enviado à
 * transportadora ao salvar. A sondagem só faz sentido sobre uma integração
 * habilitada e completa que ainda não provou a credencial: `active` já provou,
 * e `invalid_credentials` continua marcada até o par ser substituído.
 *
 * @param int    $vendor_id ID do vendor dono da integração.
 * @param bool   $enabled Se a integração ficou habilitada nesta gravação.
 * @param string $status Estado gravado nesta escrita.
 * @return void
 */
function papelito_vendor_integration_probe_braspress_credentials( int $vendor_id, bool $enabled, string $status ): void {
	if ( ! $enabled || PAPELITO_VENDOR_INTEGRATION_READY !== $status ) {
		return;
	}

	if ( ! function_exists( 'papelito_braspress_probe_credentials' ) ) {
		return;
	}

	papelito_braspress_probe_credentials( $vendor_id );
}

/**
 * Barra a gravação que não pode sequer ser tentada.
 *
 * Reúne titularidade e limite de tentativas, para a gravação em si só tratar de
 * dados válidos. Gravar credencial **não** exige step-up de identidade: o par é
 * write-only e uma troca indevida se corrige cadastrando o par certo. Só a
 * remoção, que apaga o envelope cifrado sem volta, continua pedindo a senha.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return WP_Error|null Erro que interrompe a gravação ou nulo quando liberada.
 */
function papelito_vendor_integration_guard_braspress_save( int $vendor_id, array $payload, int $actor_user_id ) {
	if ( $vendor_id <= 0 || $vendor_id !== $actor_user_id ) {
		return new WP_Error( 'papelito_vendor_integration_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}

	if ( ! papelito_auth_rate_limit( 'vendor_braspress_write', 5, 300, 'user:' . $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	return null;
}

/**
 * Decide qual envelope de credencial vai para o banco nesta gravação.
 *
 * Manter o envelope atual, substituí-lo pelo par recém-informado ou apagá-lo
 * são os três desfechos possíveis, e só o próprio corpo da requisição diz qual
 * deles vale. Usuário e senha andam juntos: um sem o outro é erro do cliente.
 *
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param array<string,mixed> $existing Linha já gravada, quando existe.
 * @return array{changed: bool, envelope: string|null}|WP_Error Envelope a gravar ou erro.
 */
function papelito_vendor_integration_resolve_secret_envelope( array $payload, array $existing ) {
	$username = sanitize_text_field( (string) ( $payload['username'] ?? '' ) );
	$secret   = (string) ( $payload['password'] ?? '' );

	if ( ( '' === $username ) !== ( '' === $secret ) ) {
		return new WP_Error( 'papelito_vendor_integration_credentials_incomplete', 'Informe usuário e senha juntos.', array( 'status' => 422 ) );
	}

	if ( ! empty( $payload['removeCredentials'] ) || ! empty( $payload['remove_credentials'] ) ) {
		return array(
			'changed'  => true,
			'envelope' => null,
		);
	}

	if ( '' === $username ) {
		return array(
			'changed'  => false,
			'envelope' => $existing['secret_envelope'] ?? null,
		);
	}

	$envelope = papelito_vendor_secret_encrypt(
		wp_json_encode(
			array(
				'username' => $username,
				'password' => $secret,
			)
		)
	);

	if ( is_wp_error( $envelope ) ) {
		return new WP_Error( 'papelito_vendor_integration_encrypt_failed', 'Não foi possível proteger a credencial.', array( 'status' => 500 ) );
	}

	return array(
		'changed'  => true,
		'envelope' => $envelope,
	);
}

/**
 * Calcula o status da integração depois da gravação.
 *
 * Perfil incompleto ou sem credencial derruba para `unconfigured`. Credencial
 * nova, ou origem trocada sem erro pendente da transportadora, devolve o vendor
 * para `ready`; fora isso o status anterior continua valendo.
 *
 * @param string      $previous Status gravado antes desta requisição.
 * @param bool        $complete Se o perfil tem CEP de origem e CNPJ.
 * @param string|null $secret_envelope Envelope que será gravado.
 * @param bool        $credentials_changed Se a credencial foi trocada ou apagada.
 * @param bool        $origin_changed Se o CEP de origem mudou.
 * @return string Status a gravar.
 */
function papelito_vendor_integration_next_status( string $previous, bool $complete, $secret_envelope, bool $credentials_changed, bool $origin_changed ): string {
	if ( ! $complete || empty( $secret_envelope ) ) {
		return PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED;
	}

	if ( $credentials_changed || ( $origin_changed && PAPELITO_VENDOR_INTEGRATION_INVALID !== $previous ) ) {
		return PAPELITO_VENDOR_INTEGRATION_READY;
	}

	return $previous;
}

/**
 * Grava a linha da integração, criando-a quando o vendor ainda não tem uma.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $data Colunas já montadas pela gravação.
 * @param array<string,mixed> $existing Linha já gravada, quando existe.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return bool Se o banco aceitou a escrita.
 */
function papelito_vendor_integration_write_row( int $vendor_id, array $data, array $existing, int $actor_user_id ): bool {
	global $wpdb;

	if ( empty( $existing ) ) {
		$data['vendor_id']  = $vendor_id;
		$data['provider']   = PAPELITO_VENDOR_INTEGRATION_PROVIDER;
		$data['created_at'] = $data['updated_at'];
		$data['created_by'] = $actor_user_id;

		return false !== $wpdb->insert( papelito_vendor_integrations_table_name(), $data );
	}

	return false !== $wpdb->update(
		papelito_vendor_integrations_table_name(),
		$data,
		array( 'id' => (int) $existing['id'] )
	);
}

/**
 * Remove a integração e o envelope de credenciais após reautenticação.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return array<string,mixed>|WP_Error Estado vazio ou erro.
 */
function papelito_vendor_integration_delete_braspress( int $vendor_id, array $payload, int $actor_user_id ) {
	return papelito_vendor_integration_audit_attempt(
		$vendor_id,
		$actor_user_id,
		'removed',
		papelito_vendor_integration_apply_braspress_delete( $vendor_id, $payload, $actor_user_id )
	);
}

/**
 * Executa a remoção da integração, sem se ocupar da auditoria da tentativa.
 *
 * @param int                 $vendor_id ID do vendor.
 * @param array<string,mixed> $payload Corpo autenticado da requisição.
 * @param int                 $actor_user_id ID do autor autenticado.
 * @return array<string,mixed>|WP_Error Estado vazio ou erro.
 */
function papelito_vendor_integration_apply_braspress_delete( int $vendor_id, array $payload, int $actor_user_id ) {
	if ( $vendor_id <= 0 || $vendor_id !== $actor_user_id ) {
		return new WP_Error( 'papelito_vendor_integration_forbidden', 'Ação não permitida.', array( 'status' => 403 ) );
	}

	if ( ! papelito_auth_rate_limit( 'vendor_braspress_write', 5, 300, 'user:' . $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$reauth = papelito_vendor_integration_require_reauth( $payload, $vendor_id );
	if ( is_wp_error( $reauth ) ) {
		return $reauth;
	}

	return papelito_vendor_integration_erase_braspress( $vendor_id, $actor_user_id );
}

/**
 * Apaga a linha e o envelope cifrado de uma integração já autorizada.
 *
 * Vale aqui a mesma divisão de `papelito_vendor_integration_write_braspress()`:
 * a remoção é destrutiva e irreversível, mas quem decide se ela pode acontecer
 * é o chamador — o painel do vendor pela reautenticação, o administrativo pela
 * capability. Não exponha esta função a nenhuma rota direta.
 *
 * @param int $vendor_id ID do vendor dono da integração.
 * @param int $actor_user_id ID de quem assina a remoção na trilha.
 * @return array<string,mixed>|WP_Error Estado vazio ou erro.
 */
function papelito_vendor_integration_erase_braspress( int $vendor_id, int $actor_user_id ) {
	global $wpdb;

	$row      = papelito_vendor_integration_find_row( $vendor_id );
	$previous = sanitize_key( (string) ( $row['status'] ?? PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED ) );

	$deleted = $wpdb->delete(
		papelito_vendor_integrations_table_name(),
		array(
			'vendor_id' => $vendor_id,
			'provider'  => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		),
		array( '%d', '%s' )
	);

	if ( false === $deleted ) {
		return new WP_Error( 'papelito_vendor_integration_delete_failed', 'Não foi possível remover a integração.', array( 'status' => 500 ) );
	}

	papelito_vendor_integration_reauth_forget( $vendor_id );
	papelito_vendor_integration_forget_braspress_health( $vendor_id, $previous );
	papelito_vendor_integration_audit(
		$vendor_id,
		PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		$actor_user_id,
		papelito_vendor_integration_audited_action( 'removed', $vendor_id, $actor_user_id ),
		PAPELITO_VENDOR_INTEGRATION_AUDIT_SUCCESS
	);
	papelito_vendor_integration_security_event( $vendor_id, 'removed' );

	return papelito_vendor_integration_public_record( null, $vendor_id );
}

/**
 * Apaga o rastro operacional que a integração removida deixaria para trás.
 *
 * São dois rastros, e os dois sobrevivem ao `DELETE` da linha. O alerta de
 * degradação fica aberto no canal referindo um vendor que já não tem conta —
 * exatamente o "abre e nunca fecha" que o recorte por transição existe para
 * evitar. E o disjuntor, que é gravado à parte em option própria, continua
 * aberto: quem removesse e reconfigurasse na sequência ficaria sem Braspress no
 * checkout por causa do estado da conta anterior.
 *
 * @param int    $vendor_id ID do vendor.
 * @param string $previous Estado de saúde imediatamente antes da remoção.
 * @return void
 */
function papelito_vendor_integration_forget_braspress_health( int $vendor_id, string $previous ): void {
	papelito_vendor_integration_announce_health_change( $vendor_id, $previous, PAPELITO_VENDOR_INTEGRATION_UNCONFIGURED, '' );

	if ( function_exists( 'papelito_shipping_breaker_close' ) ) {
		papelito_shipping_breaker_close( PAPELITO_VENDOR_INTEGRATION_PROVIDER, $vendor_id );
	}
}

/**
 * Resolve a integração com credenciais apenas para o adapter server-side.
 *
 * @param int $vendor_id ID do vendor.
 * @return array<string,mixed>|WP_Error|null Integração interna, erro ou inelegibilidade.
 */
function papelito_vendor_integration_resolve_braspress( int $vendor_id ) {
	$row = papelito_vendor_integration_find_row( $vendor_id );
	if ( ! is_array( $row ) || empty( $row['enabled'] ) || ! in_array( $row['status'], array( PAPELITO_VENDOR_INTEGRATION_READY, PAPELITO_VENDOR_INTEGRATION_ACTIVE ), true ) ) {
		return null;
	}

	$config = papelito_vendor_integration_braspress_config( $vendor_id, $row['config_json'] ?? '' );
	if ( ! papelito_vendor_integration_config_complete( $config ) || empty( $row['secret_envelope'] ) ) {
		return null;
	}

	$secret = papelito_vendor_secret_decrypt( (string) $row['secret_envelope'] );
	if ( is_wp_error( $secret ) ) {
		return new WP_Error( 'papelito_vendor_integration_secret_unavailable', 'A integração Braspress não está disponível.', array( 'status' => 503 ) );
	}

	$credentials = json_decode( $secret, true );
	if ( ! is_array( $credentials ) || empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
		return new WP_Error( 'papelito_vendor_integration_secret_invalid', 'A integração Braspress não está disponível.', array( 'status' => 503 ) );
	}

	return array(
		'id'                    => (int) $row['id'],
		'vendor_id'             => $vendor_id,
		'provider'              => PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		'config'                => $config,
		'configuration_version' => (int) $row['configuration_version'],
		'credentials'           => array(
			'username' => (string) $credentials['username'],
			'password' => (string) $credentials['password'],
		),
	);
}

/**
 * Entrega ao vendor somente o estado não secreto da sua integração.
 *
 * @return WP_REST_Response Resposta REST segura.
 */
function papelito_vendor_integration_handle_get_braspress() {
	$vendor_id = get_current_user_id();

	return new WP_REST_Response( papelito_vendor_integration_public_record( papelito_vendor_integration_find_row( $vendor_id ), $vendor_id ), 200 );
}

/**
 * Salva a integração do vendor autenticado.
 *
 * @param WP_REST_Request $request Requisição REST autenticada.
 * @return WP_REST_Response|WP_Error Resultado da escrita.
 */
function papelito_vendor_integration_handle_save_braspress( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$result  = papelito_vendor_integration_save_braspress( get_current_user_id(), is_array( $payload ) ? $payload : array(), get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Remove a integração do vendor autenticado.
 *
 * @param WP_REST_Request $request Requisição REST autenticada.
 * @return WP_REST_Response|WP_Error Resultado da remoção.
 */
function papelito_vendor_integration_handle_delete_braspress( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$result  = papelito_vendor_integration_delete_braspress( get_current_user_id(), is_array( $payload ) ? $payload : array(), get_current_user_id() );

	return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
}

/**
 * Confirma a senha da conta e devolve o tíquete que libera a mutação seguinte.
 *
 * Existe para a tela de credencial não precisar guardar a senha do sistema no
 * navegador entre a confirmação e o envio. O limite tem balde próprio de
 * propósito: confirmar a senha não pode consumir as escritas da integração, e
 * gastar escrita seria a forma barata de impedir o vendor de se reautenticar.
 * Toda tentativa entra na trilha — sem isso a rota seria um oráculo de senha
 * sem rastro.
 *
 * @param WP_REST_Request $request Requisição REST autenticada.
 * @return WP_REST_Response|WP_Error Tíquete ou erro.
 */
function papelito_vendor_integration_handle_reauth_braspress( WP_REST_Request $request ) {
	$vendor_id = get_current_user_id();

	if ( ! papelito_auth_rate_limit( 'vendor_braspress_reauth', 5, 300, 'user:' . $vendor_id ) ) {
		return new WP_Error( 'papelito_vendor_integration_rate_limited', PAPELITO_AUTH_RATE_LIMIT_MESSAGE, array( 'status' => 429 ) );
	}

	$payload = $request->get_json_params();
	$checked = papelito_vendor_integration_verify_current_password( is_array( $payload ) ? $payload : array(), $vendor_id );

	if ( is_wp_error( $checked ) ) {
		papelito_vendor_integration_audit(
			$vendor_id,
			PAPELITO_VENDOR_INTEGRATION_PROVIDER,
			$vendor_id,
			PAPELITO_VENDOR_INTEGRATION_REAUTH_ACTION,
			PAPELITO_VENDOR_INTEGRATION_AUDIT_DENIED
		);

		return $checked;
	}

	papelito_vendor_integration_audit( $vendor_id, PAPELITO_VENDOR_INTEGRATION_PROVIDER, $vendor_id, PAPELITO_VENDOR_INTEGRATION_REAUTH_ACTION );

	return new WP_REST_Response(
		array(
			'ticket'     => papelito_vendor_integration_reauth_issue( $vendor_id ),
			'expires_in' => PAPELITO_VENDOR_INTEGRATION_REAUTH_TTL,
		),
		200
	);
}

/**
 * Audita a recusa do porteiro e devolve o resultado intocado.
 *
 * O porteiro decide **antes** do callback, então toda tentativa barrada por
 * papel, sessão ou suspensão comercial sumia da trilha — justamente a tentativa
 * de acesso não autorizado que a separação entre `denied` e `rejected` existe
 * para responder. Só ator identificado é registrado: sem sessão não há a quem
 * atribuir, e escrever por requisição anônima abriria um caminho de gravação sem
 * limite nenhum antes do rate limit.
 *
 * @param mixed  $check Resultado do porteiro.
 * @param string $action Ação que o ator tentaria executar.
 * @return mixed O mesmo resultado recebido.
 */
function papelito_vendor_integration_audit_permission( mixed $check, string $action ): mixed {
	$actor_user_id = get_current_user_id();
	if ( ! is_wp_error( $check ) || $actor_user_id <= 0 ) {
		return $check;
	}

	if ( papelito_vendor_integration_audit_is_repeat( $actor_user_id, 'permission' ) ) {
		return $check;
	}

	papelito_vendor_integration_audit(
		$actor_user_id,
		PAPELITO_VENDOR_INTEGRATION_PROVIDER,
		$actor_user_id,
		$action,
		PAPELITO_VENDOR_INTEGRATION_AUDIT_DENIED
	);

	return $check;
}

/**
 * Porteiro de leitura da integração, com rastro na recusa.
 *
 * @return true|WP_Error Resultado do porteiro.
 */
function papelito_vendor_integration_permission_read(): mixed {
	return papelito_vendor_integration_audit_permission( papelito_vendor_dashboard_permission_seller(), 'configuration_read' );
}

/**
 * Porteiro de escrita e remoção da integração, com rastro na recusa.
 *
 * @return true|WP_Error Resultado do porteiro.
 */
function papelito_vendor_integration_permission_write(): mixed {
	return papelito_vendor_integration_audit_permission( papelito_vendor_dashboard_permission_seller_commercial(), 'configuration_saved' );
}

/**
 * Porteiro da confirmação de senha, com rastro na recusa.
 *
 * É o mesmo da escrita: quem não pode trocar a credencial não tem por que provar
 * identidade para trocá-la. Só a ação auditada muda, para a recusa aparecer na
 * trilha como tentativa de reautenticação e não como tentativa de gravação.
 *
 * @return true|WP_Error Resultado do porteiro.
 */
function papelito_vendor_integration_permission_reauth(): mixed {
	return papelito_vendor_integration_audit_permission( papelito_vendor_dashboard_permission_seller_commercial(), PAPELITO_VENDOR_INTEGRATION_REAUTH_ACTION );
}

/**
 * Registra as rotas REST autenticadas da integração Braspress.
 *
 * @return void
 */
function papelito_vendor_integration_register_routes(): void {
	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/integrations/braspress',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => 'papelito_vendor_integration_permission_read',
				'callback'            => 'papelito_vendor_integration_handle_get_braspress',
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => 'papelito_vendor_integration_permission_write',
				'callback'            => 'papelito_vendor_integration_handle_save_braspress',
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => 'papelito_vendor_integration_permission_write',
				'callback'            => 'papelito_vendor_integration_handle_delete_braspress',
			),
		)
	);

	register_rest_route(
		PAPELITO_REST_NAMESPACE,
		'/vendor/me/integrations/braspress/reauth',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => 'papelito_vendor_integration_permission_reauth',
			'callback'            => 'papelito_vendor_integration_handle_reauth_braspress',
		)
	);
}

add_action( 'rest_api_init', 'papelito_vendor_integration_register_routes' );
add_action( 'init', 'papelito_vendor_integration_schedule_audit_prune' );
add_action( PAPELITO_VENDOR_INTEGRATION_AUDIT_PRUNE_HOOK, 'papelito_vendor_integration_audit_prune' );
