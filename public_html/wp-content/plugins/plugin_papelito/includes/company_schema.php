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
	);
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
  cnpj CHAR(14) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  legal_name VARCHAR(255) NOT NULL,
  trade_name VARCHAR(255) NULL DEFAULT NULL,
  billing_email VARCHAR(191) NOT NULL,
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
  target_cnpj CHAR(14) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
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

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $profiles_sql );
	dbDelta( $companies_sql );
	dbDelta( $members_sql );
	dbDelta( $invitations_sql );
	dbDelta( $audit_sql );
	dbDelta( $idempotency_sql );
	dbDelta( $onboarding_sql );
}
