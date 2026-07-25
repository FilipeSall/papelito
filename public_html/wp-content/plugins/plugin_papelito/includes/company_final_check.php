<?php

defined( 'ABSPATH' ) || exit;

function papelito_b2b_final_check_user( WP_User $user ): array {
	$context = papelito_company_context( $user->ID );
	return array(
		'user_id' => $user->ID,
		'user_context_type' => (string) ( $context['userContextType'] ?? 'customer' ),
		'purchase_mode' => (string) ( $context['purchaseMode'] ?? 'blocked' ),
		'purchase_block_reason' => $context['purchaseBlockReason'] ?? null,
		'has_customer_context' => ! empty( $context['hasCustomerContext'] ),
		'company_id' => isset( $context['companyId'] ) ? (int) $context['companyId'] : null,
		'membership_status' => $context['membershipStatus'] ?? null,
		'identity_status' => $context['identityStatus'] ?? null,
		'legacy_document_present' => '' !== (string) get_user_meta( $user->ID, 'cpf', true ) || '' !== (string) get_user_meta( $user->ID, 'cnpj', true ),
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	class Papelito_B2B_Final_Check_CLI {
		public function __invoke( array $args, array $assoc_args ): void {
			$apply = ! empty( $assoc_args['apply'] );
			if ( ! $apply && empty( $assoc_args['dry-run'] ) ) {
				WP_CLI::error( 'Use --dry-run ou --apply.' );
			}
			$counts = array();
			$changed = 0;
			foreach ( get_users( array( 'number' => -1 ) ) as $user ) {
				if ( ! $user instanceof WP_User ) {
					continue;
				}
				$row = papelito_b2b_final_check_user( $user );
				$type = $row['user_context_type'];
				$counts[ 'context_' . $type ] = ( $counts[ 'context_' . $type ] ?? 0 ) + 1;
				if ( null !== $row['purchase_block_reason'] ) {
					$counts[ 'reason_' . $row['purchase_block_reason'] ] = ( $counts[ 'reason_' . $row['purchase_block_reason'] ] ?? 0 ) + 1;
				}
				if ( ! empty( $row['legacy_document_present'] ) ) {
					$counts['legacy_document_present'] = ( $counts['legacy_document_present'] ?? 0 ) + 1;
				}
				if ( $apply && in_array( $type, array( 'customer', 'hybrid' ), true ) && ! papelito_b2b_is_cohort( $user->ID ) ) {
					papelito_b2b_mark_cohort( $user->ID );
					++$changed;
				}
			}
			$counts['changed'] = $changed;
			$counts['dry_run'] = ! $apply;
			WP_CLI::success( wp_json_encode( $counts ) );
		}
	}

	WP_CLI::add_command( 'papelito b2b final-check', 'Papelito_B2B_Final_Check_CLI' );
}
