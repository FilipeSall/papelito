<?php
/**
 * Schema das tabelas do modelo B2B (Fase 0).
 *
 * Cria via dbDelta as tabelas de domínio da migração B2B CNPJ. Tudo é ADITIVO e permanece
 * DESCONECTADO dos fluxos existentes na Fase 0 (nenhuma tabela é lida/escrita por cadastro,
 * login, carrinho, checkout ou Pagar.me).
 *
 * O CPF fica em wp_papelito_customer_profiles (ver customer_identity.php), aqui incluído.
 *
 * Padrão de instalação segue vendor_stock.php: constante → *_table_names() com $wpdb->prefix
 * → instalador com dbDelta, chamado pelo bootstrap quando PAPELITO_DB_VERSION avança.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PAPELITO_COMPANIES_TABLE' ) ) {
	define( 'PAPELITO_COMPANIES_TABLE', 'papelito_companies' );
}

if ( ! defined( 'PAPELITO_COMPANY_MEMBERS_TABLE' ) ) {
	define( 'PAPELITO_COMPANY_MEMBERS_TABLE', 'papelito_company_members' );
}

if ( ! defined( 'PAPELITO_COMPANY_INVITATIONS_TABLE' ) ) {
	define( 'PAPELITO_COMPANY_INVITATIONS_TABLE', 'papelito_company_invitations' );
}

if ( ! defined( 'PAPELITO_COMPANY_AUDIT_LOG_TABLE' ) ) {
	define( 'PAPELITO_COMPANY_AUDIT_LOG_TABLE', 'papelito_company_audit_log' );
}

if ( ! defined( 'PAPELITO_COMPANY_IDEMPOTENCY_TABLE' ) ) {
	define( 'PAPELITO_COMPANY_IDEMPOTENCY_TABLE', 'papelito_company_idempotency' );
}

if ( ! defined( 'PAPELITO_B2B_ONBOARDING_TABLE' ) ) {
	define( 'PAPELITO_B2B_ONBOARDING_TABLE', 'papelito_b2b_onboarding' );
}

if ( ! defined( 'PAPELITO_B2B_LEGACY_EMAIL_LOG_TABLE' ) ) {
	define( 'PAPELITO_B2B_LEGACY_EMAIL_LOG_TABLE', 'papelito_b2b_legacy_email_log' );
}

if ( ! defined( 'PAPELITO_COMPANY_OWNER_APPLICATIONS_TABLE' ) ) {
	define( 'PAPELITO_COMPANY_OWNER_APPLICATIONS_TABLE', 'papelito_company_owner_applications' );
}

if ( ! defined( 'PAPELITO_COMPANY_PRE_ACCOUNT_APPLICATIONS_TABLE' ) ) {
	define( 'PAPELITO_COMPANY_PRE_ACCOUNT_APPLICATIONS_TABLE', 'papelito_company_pre_account_applications' );
}

if ( ! defined( 'PAPELITO_COMPANY_CNPJ_COLUMN_TYPE' ) ) {
	define( 'PAPELITO_COMPANY_CNPJ_COLUMN_TYPE', 'CHAR(14)' );
}

/**
 * Resolve os nomes completos (com prefixo) das tabelas do modelo B2B.
 *
 * @return array{profiles:string,companies:string,members:string,invitations:string,audit:string}
 */
function papelito_company_table_names(): array {
	global $wpdb;

	return array(
		'profiles'    => $wpdb->prefix . PAPELITO_CUSTOMER_PROFILES_TABLE,
		'companies'   => $wpdb->prefix . PAPELITO_COMPANIES_TABLE,
		'members'     => $wpdb->prefix . PAPELITO_COMPANY_MEMBERS_TABLE,
		'invitations' => $wpdb->prefix . PAPELITO_COMPANY_INVITATIONS_TABLE,
		'audit'       => $wpdb->prefix . PAPELITO_COMPANY_AUDIT_LOG_TABLE,
		'idempotency' => $wpdb->prefix . PAPELITO_COMPANY_IDEMPOTENCY_TABLE,
		'onboarding' => $wpdb->prefix . PAPELITO_B2B_ONBOARDING_TABLE,
		'legacy_email_log' => $wpdb->prefix . PAPELITO_B2B_LEGACY_EMAIL_LOG_TABLE,
		'owner_applications' => $wpdb->prefix . PAPELITO_COMPANY_OWNER_APPLICATIONS_TABLE,
		'pre_account_applications' => $wpdb->prefix . PAPELITO_COMPANY_PRE_ACCOUNT_APPLICATIONS_TABLE,
	);
}

/**
 * CREATE TABLE das tabelas de identidade, empresa e vinculo.
 *
 * @param array<string,string> $tables          Nomes de tabela ja prefixados.
 * @param string               $charset_collate Sufixo de charset do $wpdb.
 * @return array<int,string>
 */
function papelito_company_identity_schema_sql( array $tables, string $charset_collate ): array {
	$profiles_sql = "CREATE TABLE {$tables['profiles']} (
  user_id BIGINT UNSIGNED NOT NULL,
  cpf_hmac CHAR(64) NOT NULL,
  cpf_ciphertext LONGTEXT NOT NULL,
  cpf_last4 CHAR(4) NOT NULL,
  birth_date_ciphertext LONGTEXT NULL DEFAULT NULL,
  identity_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  identity_method VARCHAR(32) NULL DEFAULT NULL,
  identity_checked_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (user_id),
  UNIQUE KEY uniq_cpf_hmac (cpf_hmac),
  KEY idx_identity_status (identity_status)
) {$charset_collate};";

	$companies_sql = "CREATE TABLE {$tables['companies']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cnpj CHAR(14) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  legal_name VARCHAR(255) NOT NULL,
  trade_name VARCHAR(255) NULL DEFAULT NULL,
  billing_email VARCHAR(191) NOT NULL,
  pending_billing_email VARCHAR(191) NULL DEFAULT NULL,
  pending_billing_email_token_hash CHAR(64) NULL DEFAULT NULL,
  pending_billing_email_expires_at DATETIME NULL DEFAULT NULL,
  billing_email_verified_at DATETIME NULL DEFAULT NULL,
  billing_email_verification_sent_at DATETIME NULL DEFAULT NULL,
  phone VARCHAR(24) NULL DEFAULT NULL,
  registry_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  ownership_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  company_status VARCHAR(24) NOT NULL DEFAULT 'onboarding',
  verification_method VARCHAR(32) NULL DEFAULT NULL,
  provider_source VARCHAR(32) NULL DEFAULT NULL,
  provider_checked_at DATETIME NULL DEFAULT NULL,
  provider_data_hash CHAR(64) NULL DEFAULT NULL,
  fiscal_cep VARCHAR(8) NULL DEFAULT NULL,
  fiscal_state CHAR(2) NULL DEFAULT NULL,
  fiscal_city VARCHAR(120) NULL DEFAULT NULL,
  fiscal_neighborhood VARCHAR(120) NULL DEFAULT NULL,
  fiscal_street VARCHAR(255) NULL DEFAULT NULL,
  fiscal_number VARCHAR(40) NULL DEFAULT NULL,
  fiscal_complement VARCHAR(120) NULL DEFAULT NULL,
  pagarme_customer_id VARCHAR(64) NULL DEFAULT NULL,
  pagarme_customer_code VARCHAR(52) NULL DEFAULT NULL,
  owner_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  verified_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  verified_at DATETIME NULL DEFAULT NULL,
  ownership_rejection_reason VARCHAR(255) NULL DEFAULT NULL,
  ownership_rejected_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ownership_rejected_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_cnpj (cnpj),
  KEY idx_registry_status (registry_status),
  KEY idx_ownership_status (ownership_status),
  KEY idx_company_status (company_status),
  KEY idx_owner_user (owner_user_id)
) {$charset_collate};";

	$members_sql = "CREATE TABLE {$tables['members']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_role VARCHAR(24) NOT NULL DEFAULT 'buyer',
  member_status VARCHAR(32) NOT NULL DEFAULT 'pending_company_approval',
  membership_origin VARCHAR(24) NOT NULL DEFAULT 'owner_candidate',
  identity_requirement VARCHAR(24) NOT NULL DEFAULT 'required',
  requested_at DATETIME NULL DEFAULT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_request_at DATETIME NULL DEFAULT NULL,
  invited_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  approved_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  approved_at DATETIME NULL DEFAULT NULL,
  rejected_at DATETIME NULL DEFAULT NULL,
  rejected_reason VARCHAR(255) NULL DEFAULT NULL,
  rejected_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  suspended_at DATETIME NULL DEFAULT NULL,
  suspended_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  revoked_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  role_changed_at DATETIME NULL DEFAULT NULL,
  role_changed_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_company_user (company_id, user_id),
  KEY idx_user_status (user_id, member_status),
  KEY idx_company_status (company_id, member_status),
  KEY idx_company_role_status (company_id, member_role, member_status)
) {$charset_collate};";

	$invitations_sql = "CREATE TABLE {$tables['invitations']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  invited_email VARCHAR(191) NOT NULL,
  invited_cpf_hmac CHAR(64) NULL DEFAULT NULL,
  invited_role VARCHAR(24) NOT NULL DEFAULT 'buyer',
  token_hash CHAR(64) NOT NULL,
  invitation_status VARCHAR(24) NOT NULL DEFAULT 'pending',
  invited_by_user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL DEFAULT NULL,
  accepted_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  declined_at DATETIME NULL DEFAULT NULL,
  declined_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  revoked_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  revoked_reason VARCHAR(255) NULL DEFAULT NULL,
  resent_at DATETIME NULL DEFAULT NULL,
  resend_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_invitation_token (token_hash),
  KEY idx_company_status (company_id, invitation_status),
  KEY idx_email_status (invited_email, invitation_status)
) {$charset_collate};";

	return array( $profiles_sql, $companies_sql, $members_sql, $invitations_sql );
}

/**
 * CREATE TABLE das tabelas de operacao: auditoria, idempotencia, onboarding e log legado.
 *
 * @param array<string,string> $tables          Nomes de tabela ja prefixados.
 * @param string               $charset_collate Sufixo de charset do $wpdb.
 * @return array<int,string>
 */
function papelito_company_operations_schema_sql( array $tables, string $charset_collate ): array {
	$audit_sql = "CREATE TABLE {$tables['audit']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  action VARCHAR(64) NOT NULL,
  payload_json LONGTEXT NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_company_created (company_id, created_at)
) {$charset_collate};";

	$idempotency_sql = "CREATE TABLE {$tables['idempotency']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  operation VARCHAR(64) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  resource_id BIGINT UNSIGNED NULL DEFAULT NULL,
  response_code SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_actor_operation_key (actor_user_id, operation, key_hash),
  KEY idx_expires_at (expires_at)
) {$charset_collate};";

	$onboarding_sql = "CREATE TABLE {$tables['onboarding']} (
  user_id BIGINT UNSIGNED NOT NULL,
  onboarding_type VARCHAR(32) NOT NULL,
  target_cnpj CHAR(14) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending_email',
  company_id BIGINT UNSIGNED NULL DEFAULT NULL,
  membership_id BIGINT UNSIGNED NULL DEFAULT NULL,
  expires_at DATETIME NULL DEFAULT NULL,
  email_confirmed_at DATETIME NULL DEFAULT NULL,
  completed_at DATETIME NULL DEFAULT NULL,
  last_error_code VARCHAR(64) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (user_id),
  KEY idx_onboarding_status (status),
  KEY idx_onboarding_type_status (onboarding_type, status),
  KEY idx_onboarding_expires (expires_at)
) {$charset_collate};";

	$legacy_email_log_sql = "CREATE TABLE {$tables['legacy_email_log']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  campaign VARCHAR(48) NOT NULL,
  campaign_version VARCHAR(24) NOT NULL DEFAULT '1',
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code VARCHAR(64) NULL DEFAULT NULL,
  next_retry_at DATETIME NULL DEFAULT NULL,
  sent_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_user_campaign_version (user_id, campaign, campaign_version),
  KEY idx_status_retry (status, next_retry_at),
  KEY idx_campaign_status (campaign, status)
) {$charset_collate};";

	return array( $audit_sql, $idempotency_sql, $onboarding_sql, $legacy_email_log_sql );
}

/**
 * CREATE TABLE das tabelas de candidatura: dono de empresa e pre-conta.
 *
 * @param array<string,string> $tables          Nomes de tabela ja prefixados.
 * @param string               $charset_collate Sufixo de charset do $wpdb.
 * @return array<int,string>
 */
function papelito_company_applications_schema_sql( array $tables, string $charset_collate ): array {
	$owner_applications_sql = "CREATE TABLE {$tables['owner_applications']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  attempt_number INT UNSIGNED NOT NULL,
  application_status VARCHAR(32) NOT NULL,
  is_open TINYINT UNSIGNED NULL DEFAULT NULL,
  evidence_json LONGTEXT NULL DEFAULT NULL,
  provider_source VARCHAR(32) NULL DEFAULT NULL,
  provider_checked_at DATETIME NULL DEFAULT NULL,
  provider_data_hash CHAR(64) NULL DEFAULT NULL,
  document_storage_key VARCHAR(80) NULL DEFAULT NULL,
  document_original_name VARCHAR(191) NULL DEFAULT NULL,
  document_mime VARCHAR(64) NULL DEFAULT NULL,
  document_size BIGINT UNSIGNED NULL DEFAULT NULL,
  document_sha256 CHAR(64) NULL DEFAULT NULL,
  document_uploaded_at DATETIME NULL DEFAULT NULL,
  document_purge_status VARCHAR(24) NOT NULL DEFAULT 'not_applicable',
  document_purge_error_code VARCHAR(64) NULL DEFAULT NULL,
  document_deleted_at DATETIME NULL DEFAULT NULL,
  decided_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  decided_at DATETIME NULL DEFAULT NULL,
  rejection_reason VARCHAR(500) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_company_attempt (company_id, attempt_number),
  UNIQUE KEY uniq_company_open (company_id, is_open),
  KEY idx_user_created (user_id, created_at),
  KEY idx_status_created (application_status, created_at)
) {$charset_collate};";

	$pre_account_applications_sql = "CREATE TABLE {$tables['pre_account_applications']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_email_hmac CHAR(64) NOT NULL,
  contact_email_ciphertext LONGTEXT NOT NULL,
  full_name_ciphertext LONGTEXT NOT NULL,
  phone_ciphertext LONGTEXT NOT NULL,
  cpf_hmac CHAR(64) NOT NULL,
  cpf_ciphertext LONGTEXT NOT NULL,
  birth_date_ciphertext LONGTEXT NOT NULL,
  address_ciphertext LONGTEXT NOT NULL,
  password_hash VARCHAR(255) NULL DEFAULT NULL,
  canonical_cnpj CHAR(14) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
  legal_name_ciphertext LONGTEXT NULL DEFAULT NULL,
  review_path VARCHAR(24) NULL DEFAULT NULL,
  application_status VARCHAR(32) NOT NULL DEFAULT 'draft',
  is_open TINYINT UNSIGNED NULL DEFAULT NULL,
  resume_token_hash CHAR(64) NOT NULL,
  resume_token_expires_at DATETIME NOT NULL,
  evidence_json LONGTEXT NULL DEFAULT NULL,
  provider_source VARCHAR(32) NULL DEFAULT NULL,
  provider_checked_at DATETIME NULL DEFAULT NULL,
  provider_data_hash CHAR(64) NULL DEFAULT NULL,
  document_storage_key VARCHAR(80) NULL DEFAULT NULL,
  document_original_name VARCHAR(191) NULL DEFAULT NULL,
  document_mime VARCHAR(64) NULL DEFAULT NULL,
  document_size BIGINT UNSIGNED NULL DEFAULT NULL,
  document_sha256 CHAR(64) NULL DEFAULT NULL,
  document_uploaded_at DATETIME NULL DEFAULT NULL,
  document_deleted_at DATETIME NULL DEFAULT NULL,
  decided_by_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  decided_at DATETIME NULL DEFAULT NULL,
  rejection_reason VARCHAR(500) NULL DEFAULT NULL,
  created_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  created_company_id BIGINT UNSIGNED NULL DEFAULT NULL,
  created_membership_id BIGINT UNSIGNED NULL DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_open_cnpj (canonical_cnpj, is_open),
  KEY idx_contact_status (contact_email_hmac, application_status),
  KEY idx_resume_token (resume_token_hash),
  KEY idx_status_expires (application_status, expires_at)
) {$charset_collate};";

	return array( $owner_applications_sql, $pre_account_applications_sql );
}

/**
 * Realinha as colunas de CNPJ canonico que ficaram fora do charset da tabela.
 *
 * @param array<string,string> $tables Nomes de tabela ja prefixados.
 * @return void
 */
function papelito_company_align_cnpj_columns( array $tables ): void {
	papelito_db_align_binary_columns(
		$tables['companies'],
		array(
			'cnpj' => array(
				'type'       => PAPELITO_COMPANY_CNPJ_COLUMN_TYPE,
				'attributes' => 'NOT NULL',
			),
		)
	);
	papelito_db_align_binary_columns(
		$tables['onboarding'],
		array(
			'target_cnpj' => array(
				'type'       => PAPELITO_COMPANY_CNPJ_COLUMN_TYPE,
				'attributes' => 'NULL DEFAULT NULL',
			),
		)
	);
	papelito_db_align_binary_columns(
		$tables['pre_account_applications'],
		array(
			'canonical_cnpj' => array(
				'type'       => PAPELITO_COMPANY_CNPJ_COLUMN_TYPE,
				'attributes' => 'NULL DEFAULT NULL',
			),
		)
	);
}

/**
 * Revoga convites pendentes presos a CPF, aposentados pela politica atual.
 *
 * @param array<string,string> $tables Nomes de tabela ja prefixados.
 * @return void
 */
function papelito_company_revoke_cpf_invitations( array $tables ): void {
	global $wpdb;

	// Convites pendentes que foram presos a CPF não podem ser aceitos sob a nova política.
	// Mantemos o histórico e exigimos um novo convite, sem CPF, em vez de apagar dados.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tables['invitations']} SET invitation_status = %s, revoked_at = %s, revoked_reason = %s WHERE invitation_status = %s AND invited_cpf_hmac IS NOT NULL",
			'revoked',
			current_time( 'mysql', true ),
			'cpf_invitation_policy_retired',
			'pending'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Cria/atualiza as tabelas do modelo B2B via dbDelta.
 *
 * Chamado pelo bootstrap de migration em plugin_papelito.php quando papelito_db_version for
 * inferior à versão atual. Idempotente (dbDelta é declarativo).
 */
function papelito_company_install_tables(): void {
	global $wpdb;

	$tables          = papelito_company_table_names();
	$charset_collate = $wpdb->get_charset_collate();

	$statements = array_merge(
		papelito_company_identity_schema_sql( $tables, $charset_collate ),
		papelito_company_operations_schema_sql( $tables, $charset_collate ),
		papelito_company_applications_schema_sql( $tables, $charset_collate )
	);

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	foreach ( $statements as $statement ) {
		dbDelta( $statement );
	}

	papelito_company_align_cnpj_columns( $tables );
	papelito_company_revoke_cpf_invitations( $tables );
}
