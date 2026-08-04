<?php
/**
 * Sincronizacao entre o e-mail principal da conta e o e-mail de faturamento da empresa.
 *
 * O e-mail principal (usermeta `papelito_email_verification_status`) e o de faturamento (coluna
 * `billing_email_verified_at`) sao estados separados porque o segundo pode apontar para um endereco
 * fiscal que nao pertence a nenhuma conta. Quando os dois enderecos sao o mesmo, exigir duas
 * confirmacoes e ruido: a prova de posse ja foi dada uma vez.
 *
 * Este modulo concentra a decisao. As funcoes `papelito_billing_email_decide_*` sao puras de
 * proposito — sao elas que os testes standalone exercitam sem banco.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

/**
 * Informa se o e-mail principal da conta vale como verificado.
 *
 * Reusa a invariante existente de `papelito_auth_requires_email_verification()`: meta ausente e
 * conta legada, tratada como verificada. Era justamente essa divergencia que marcava empresa de
 * usuario legado como `billing_email_unverified`.
 *
 * @param int $user_id Usuario.
 * @return bool
 */
function papelito_billing_email_account_is_verified( int $user_id ): bool {
	if ( ! function_exists( 'papelito_auth_requires_email_verification' ) ) {
		return false;
	}

	return ! papelito_auth_requires_email_verification( $user_id );
}

/**
 * Timestamp em que o e-mail principal passou a valer como verificado.
 *
 * Conta legada nao tem `papelito_email_verified_at`; nesse caso usamos agora, porque o que importa
 * para o faturamento e o momento em que a equivalencia foi reconhecida.
 *
 * @param int $user_id Usuario.
 * @return string Datetime MySQL em UTC.
 */
function papelito_billing_email_account_verified_at( int $user_id ): string {
	$verified_at = (string) get_user_meta( $user_id, 'papelito_email_verified_at', true );

	return '' !== $verified_at ? $verified_at : current_time( 'mysql', true );
}

/**
 * Decide o que fazer com o e-mail de faturamento de uma empresa diante da conta do usuario.
 *
 * @param array<string,mixed> $company          Linha da empresa (billing_email, billing_email_verified_at).
 * @param string              $account_email    E-mail principal da conta.
 * @param bool                $account_verified Se o e-mail principal vale como verificado.
 * @return string `confirm`, `skip_already_verified`, `skip_account_unverified` ou `skip_email_differs`.
 */
function papelito_billing_email_decide_sync( array $company, string $account_email, bool $account_verified ): string {
	if ( ! empty( $company['billing_email_verified_at'] ) ) {
		return 'skip_already_verified';
	}

	if ( ! papelito_emails_match( (string) ( $company['billing_email'] ?? '' ), $account_email ) ) {
		return 'skip_email_differs';
	}

	if ( ! $account_verified ) {
		return 'skip_account_unverified';
	}

	return 'confirm';
}

/**
 * Decide o efeito de um `billingEmail` recebido no PATCH da empresa.
 *
 * @param array<string,mixed> $company          Linha da empresa.
 * @param string              $requested        E-mail solicitado (ja normalizado).
 * @param string              $account_email    E-mail principal do ator.
 * @param bool                $account_verified Se o e-mail principal do ator vale como verificado.
 * @return string `noop_same_verified`, `noop_same_pending`, `confirm_matches_account` ou `send_confirmation`.
 */
function papelito_billing_email_decide_update( array $company, string $requested, string $account_email, bool $account_verified ): string {
	$is_current = papelito_emails_match( (string) ( $company['billing_email'] ?? '' ), $requested );

	if ( $is_current && ! empty( $company['billing_email_verified_at'] ) ) {
		return 'noop_same_verified';
	}

	if ( papelito_emails_match( (string) ( $company['pending_billing_email'] ?? '' ), $requested ) ) {
		return 'noop_same_pending';
	}

	if ( $account_verified && papelito_emails_match( $requested, $account_email ) ) {
		return 'confirm_matches_account';
	}

	return 'send_confirmation';
}

/**
 * Marca o e-mail de faturamento como confirmado, sem passar por token.
 *
 * Limpa a pendencia apenas quando ela aponta para o mesmo endereco: uma troca em andamento para
 * outro endereco continua valendo e nao pode ser silenciosamente descartada aqui.
 *
 * @param int    $company_id ID da empresa.
 * @param string $email      Endereco confirmado (normalizado).
 * @param string $verified_at Datetime MySQL em UTC.
 * @param string $source     Origem registrada na auditoria.
 * @return bool
 */
function papelito_billing_email_confirm_company( int $company_id, string $email, string $verified_at, string $source ): bool {
	global $wpdb;

	$tables  = papelito_company_table_names();
	$company = $wpdb->get_row( $wpdb->prepare( "SELECT pending_billing_email FROM {$tables['companies']} WHERE id = %d", $company_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	$fields = array(
		'billing_email'             => $email,
		'billing_email_verified_at' => $verified_at,
	);

	if ( is_array( $company ) && papelito_emails_match( (string) ( $company['pending_billing_email'] ?? '' ), $email ) ) {
		$fields['pending_billing_email']            = null;
		$fields['pending_billing_email_token_hash'] = null;
		$fields['pending_billing_email_expires_at'] = null;
	}

	$updated = papelito_company_update( $company_id, $fields );

	if ( is_wp_error( $updated ) ) {
		return false;
	}

	papelito_company_audit( $company_id, null, 'billing_email_verified', array( 'source' => $source ) );

	return true;
}

/**
 * Empresas de um usuario cujo e-mail de faturamento pode herdar a verificacao da conta.
 *
 * Restrito a quem administra a empresa (owner registrado ou membro ativo owner/admin): a
 * verificacao do e-mail de um membro comum nao deve confirmar o endereco fiscal da empresa.
 *
 * @param int $user_id Usuario.
 * @return array<int,array<string,mixed>> Linhas elegiveis com a decisao anexada.
 */
function papelito_billing_email_sync_candidates( int $user_id ): array {
	global $wpdb;

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return array();
	}

	$account_email = papelito_normalize_email( (string) $user->user_email );

	if ( '' === $account_email ) {
		return array();
	}

	$tables = papelito_company_table_names();
	$sql    = "SELECT c.id, c.billing_email, c.billing_email_verified_at, c.pending_billing_email, m.member_role, m.member_status, m.expires_at FROM {$tables['companies']} c LEFT JOIN {$tables['members']} m ON m.company_id = c.id AND m.user_id = %d WHERE c.billing_email_verified_at IS NULL AND ( c.owner_user_id = %d OR m.id IS NOT NULL )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	$verified   = papelito_billing_email_account_is_verified( $user_id );
	$candidates = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		if ( 'confirm' !== papelito_billing_email_decide_sync( $row, $account_email, $verified ) ) {
			continue;
		}

		$is_owner   = null === $row['member_role'] || in_array( (string) $row['member_role'], array( 'owner', 'admin' ), true );
		$is_manager = $is_owner && ( null === $row['member_status'] || papelito_company_member_is_operationally_active( $row ) );

		if ( ! $is_manager ) {
			continue;
		}

		$candidates[] = array(
			'company_id' => (int) $row['id'],
			'email'      => $account_email,
		);
	}

	return $candidates;
}

/**
 * Confirma o e-mail de faturamento das empresas do usuario que sao iguais ao e-mail da conta.
 *
 * Idempotente: quem ja esta confirmado nao entra na consulta.
 *
 * @param int $user_id Usuario.
 * @return int Quantidade de empresas confirmadas.
 */
function papelito_billing_email_sync_for_user( int $user_id ): int {
	$verified_at = papelito_billing_email_account_verified_at( $user_id );
	$confirmed   = 0;

	foreach ( papelito_billing_email_sync_candidates( $user_id ) as $candidate ) {
		if ( papelito_billing_email_confirm_company( (int) $candidate['company_id'], (string) $candidate['email'], $verified_at, 'account_email' ) ) {
			++$confirmed;
		}
	}

	return $confirmed;
}
add_action( 'papelito_email_verified', 'papelito_billing_email_sync_for_user' );

/**
 * Diagnostico e backfill das empresas que ficaram `unverified` com o proprio e-mail da conta.
 *
 * @param bool $execute Se false, apenas conta.
 * @param int  $limit   Maximo de empresas inspecionadas.
 * @return array<string,int|array<int,int>>
 */
function papelito_billing_email_backfill_run( bool $execute, int $limit ): array {
	global $wpdb;

	$tables  = papelito_company_table_names();
	$summary = array(
		'scanned'         => 0,
		'matched'         => 0,
		'confirmed'       => 0,
		'email_differs'   => 0,
		'account_pending' => 0,
		'no_owner'        => 0,
		'sample'          => array(),
	);

	$sql  = "SELECT id, billing_email, billing_email_verified_at, pending_billing_email, owner_user_id, created_by_user_id FROM {$tables['companies']} WHERE billing_email_verified_at IS NULL ORDER BY id ASC LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		papelito_billing_email_backfill_process_row( $row, $execute, $summary );
	}

	return $summary;
}

/**
 * Processa uma empresa no backfill e atualiza o resumo por referência.
 *
 * @param array<string,mixed>                $row     Linha da empresa.
 * @param bool                               $execute Executa a confirmação.
 * @param array<string,int|array<int,int>> &$summary Resumo acumulado.
 * @return void
 */
function papelito_billing_email_backfill_process_row( array $row, bool $execute, array &$summary ): void {
	++$summary['scanned'];
	$owner_id = (int) $row['owner_user_id'];
	$owner_id = $owner_id > 0 ? $owner_id : (int) $row['created_by_user_id'];
	$owner = $owner_id > 0 ? get_userdata( $owner_id ) : false;

	if ( ! $owner instanceof WP_User ) {
		++$summary['no_owner'];
		return;
	}

	$account_email = papelito_normalize_email( (string) $owner->user_email );
	$decision = papelito_billing_email_decide_sync(
		$row,
		$account_email,
		papelito_billing_email_account_is_verified( $owner_id )
	);

	if ( 'confirm' !== $decision ) {
		papelito_billing_email_backfill_count_skip( $decision, $summary );
		return;
	}

	++$summary['matched'];
	if ( count( $summary['sample'] ) < 10 ) {
		$summary['sample'][] = (int) $row['id'];
	}

	if ( $execute && papelito_billing_email_confirm_company( (int) $row['id'], $account_email, papelito_billing_email_account_verified_at( $owner_id ), 'backfill' ) ) {
		++$summary['confirmed'];
	}
}

/**
 * Incrementa apenas os resultados de descarte que são relevantes ao relatório.
 *
 * @param string                             $decision Decisão de sincronização.
 * @param array<string,int|array<int,int>> &$summary  Resumo acumulado.
 * @return void
 */
function papelito_billing_email_backfill_count_skip( string $decision, array &$summary ): void {
	if ( 'skip_email_differs' === $decision ) {
		++$summary['email_differs'];
	}

	if ( 'skip_account_unverified' === $decision ) {
		++$summary['account_pending'];
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * WP-CLI do e-mail de faturamento.
	 */
	class PapelitoBillingEmailCli {
		/**
		 * Confirma o e-mail de faturamento igual ao e-mail da conta ja verificada.
		 *
		 * Simula por padrao. Enderecos diferentes do e-mail da conta nunca sao alterados.
		 *
		 * @param array<int,string>    $args       Argumentos posicionais.
		 * @param array<string,string> $assoc_args --execute, --limit=N.
		 */
		public function backfill( array $args, array $assoc_args ): void {
			$execute = ! empty( $assoc_args['execute'] );
			$limit   = (int) ( $assoc_args['limit'] ?? 5000 );

			if ( ! $execute ) {
				WP_CLI::log( 'Simulacao. Nada foi gravado. Use --execute para aplicar.' );
			}

			$summary = papelito_billing_email_backfill_run( $execute, $limit );

			WP_CLI::success(
				sprintf(
					'scanned=%d matched=%d confirmed=%d email_differs=%d account_pending=%d no_owner=%d execute=%s sample=%s',
					(int) $summary['scanned'],
					(int) $summary['matched'],
					(int) $summary['confirmed'],
					(int) $summary['email_differs'],
					(int) $summary['account_pending'],
					(int) $summary['no_owner'],
					$execute ? 'true' : 'false',
					empty( $summary['sample'] ) ? '-' : implode( ',', array_map( 'absint', $summary['sample'] ) )
				)
			);
		}
	}

	WP_CLI::add_command( 'papelito billing-email', 'PapelitoBillingEmailCli' );
}
