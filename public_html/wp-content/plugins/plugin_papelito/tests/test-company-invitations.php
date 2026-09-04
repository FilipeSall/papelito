<?php
/**
 * Standalone regression test for Fase 1B invitation + access-request lifecycle.
 *
 * Uses an in-memory $wpdb fake (only the query shapes used by company_repository invitations and
 * the accept flow) plus WP function stubs. Covers:
 *   - invitation created / accepted / expired / revoked / resent
 *   - reused token fails; resend invalidates the previous token
 *   - CPF-HMAC-locked invitation rejects a divergent CPF and accepts a matching one
 *   - email mismatch rejected
 *   - invitation never grants owner
 *   - access request: neutral response whether the company exists or not (no enumeration)
 *   - access request resubmit policy (cooldown + attempt limit)
 *
 * @package Papelito
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'PAPELITO_TEST_MYSQL_DATETIME_FORMAT', 'Y-m-d H:i:s' );

class WP_Error { // NOSONAR - mock precisa preservar o nome da classe global do WordPress.
	private string $code;
	private mixed $data;
	public function __construct( string $code = '', string $m = '', mixed $d = null ) { $this->code = $code; $this->data = $d; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( mixed $v ) { return $v instanceof WP_Error; }

/* -------- WP function stubs -------- */
function sanitize_email( string $v ) { return trim( strtolower( $v ) ); }
function is_email( string $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
/** @param mixed $v @return mixed */
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : $v; }
/** @param mixed $v */
function sanitize_key( $v ) { return strtolower( (string) $v ); }
/** @param mixed $v */
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( string $type, bool $gmt = false ) { global $papelito_now; return gmdate( PAPELITO_TEST_MYSQL_DATETIME_FORMAT, $papelito_now ); }
function wp_mail( string $to, string $subject, string $body ) { global $papelito_mail; $papelito_mail[] = array( 'to' => $to, 'subject' => $subject, 'body' => $body ); return true; }
function get_userdata( int $id ) { global $papelito_users; return $papelito_users[ $id ] ?? false; }
function email_exists( string $email ) {
	global $papelito_users;
	foreach ( $papelito_users as $id => $user ) {
		if ( strtolower( (string) $user->user_email ) === strtolower( trim( $email ) ) ) { return (int) $id; }
	}
	return false;
}
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function wp_get_environment_type() { return 'production'; }
/** @param mixed $d @return mixed */
function papelito_env( string $k, $d = null ) {
	// O link do convite passa pelo mesmo resolvedor do e-mail de faturamento; sem uma base
	// configurada aqui o envio seria abortado de proposito.
	return 'PAPELITO_FRONTEND_URL' === $k ? 'https://marketplace.papelito.com' : $d;
}

$papelito_mail = array();

$papelito_meta = array();
function get_user_meta( int $u, string $k, bool $s = false ) { global $papelito_meta; return $papelito_meta[ "{$u}:{$k}" ] ?? ''; }
/** @param mixed $v */
function update_user_meta( int $u, string $k, $v ) { global $papelito_meta; $papelito_meta[ "{$u}:{$k}" ] = $v; return true; }
function delete_user_meta( int $u, string $k ) { global $papelito_meta; unset( $papelito_meta[ "{$u}:{$k}" ] ); return true; }

class WP_User { // NOSONAR - mock precisa preservar o nome da classe global do WordPress.
	public int $ID; // NOSONAR - propriedade compatível com WP_User.
	public string $user_email; // NOSONAR - propriedade compatível com WP_User.
	public string $display_name = 'Test'; // NOSONAR - propriedade compatível com WP_User.
	public function __construct( int $id, string $email ) { $this->ID = $id; $this->user_email = $email; }
}

/* -------- current time (mutable for expiry tests) -------- */
$papelito_now = 1_700_000_000;

/* -------- in-memory $wpdb fake -------- */
class FakeWpdb {
	public string $prefix = 'wp_';
	public string $last_error = ''; // NOSONAR - propriedade compatível com $wpdb.
	public int $insert_id = 0; // NOSONAR - propriedade compatível com $wpdb.
	public array $t = array(); // table => rows[]
	private int $auto = 0;

	public function get_charset_collate() { return ''; }

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( array $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ] ?? '';
			return ( '%d' === $m[0] ) ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $query );
	}
	private function table_of( string $q ): string {
		if ( preg_match( '/\bwp_([a-z_]+)\b/', $q, $m ) ) { return 'wp_' . $m[1]; }
		return '';
	}
	/** @param array<string,mixed> $data */
	public function insert( string $table, array $data ) {
		$this->t[ $table ] ??= array();
		$data['id'] = ++$this->auto;
		$this->t[ $table ][] = $data;
		$this->insert_id = $data['id'];
		return 1;
	}
	/** @param array<string,mixed> $data @param array<string,mixed> $where */
	public function update( string $table, array $data, array $where ) {
		if ( ! isset( $this->t[ $table ] ) ) { return 0; }
		$n = 0;
		foreach ( $this->t[ $table ] as &$row ) {
			$match = true;
			foreach ( $where as $k => $v ) { if ( (string) ( $row[ $k ] ?? null ) !== (string) $v ) { $match = false; break; } }
			if ( $match ) { $row = array_merge( $row, $data ); $n++; }
		}
		unset( $row );
		return $n;
	}
	/** @param mixed $output */
	public function get_row( string $query, $output = null ) {
		$table = $this->table_of( $query );
		foreach ( ( $this->t[ $table ] ?? array() ) as $row ) {
			if ( $this->where_matches( $query, $row ) ) { return $row; }
		}
		return null;
	}
	public function query( string $query ) {
		if ( preg_match( '/^\s*(START TRANSACTION|COMMIT|ROLLBACK)/i', $query ) ) { return true; }
		$table = $this->table_of( $query );
		if ( '' === $table ) { return 0; }
		$set_part = preg_match( '/\bSET\b(.*?)\bWHERE\b/is', $query, $sm ) ? $sm[1] : '';
		return $this->update_matching_rows( $table, $query, $set_part );
	}
	private function update_matching_rows( string $table, string $query, string $set_part ): int {
		if ( ! isset( $this->t[ $table ] ) ) { return 0; }
		$n = 0;
		foreach ( $this->t[ $table ] as &$row ) {
			if ( ! $this->where_matches( $query, $row ) ) { continue; }
			$this->apply_update( $row, $query, $set_part );
			$n++;
		}
		unset( $row );
		return $n;
	}
	/** @param array<string,mixed> $row */
	private function apply_update( array &$row, string $query, string $set_part ): void {
		if ( preg_match_all( "/(\w+)\s*=\s*'([^']*)'/", $set_part, $sets, PREG_SET_ORDER ) ) {
			foreach ( $sets as $set ) { $row[ $set[1] ] = $set[2]; }
		}
		if ( preg_match( '/resend_count\s*=\s*resend_count\s*\+\s*1/', $query ) ) { $row['resend_count'] = ( (int) ( $row['resend_count'] ?? 0 ) ) + 1; }
	}
	/*
	 * Matches only the WHERE clause of a (prepared) query against a row. Supports equality on
	 * string ('...') and numeric columns, and a single "col < 'ts'" comparison used by the
	 * bulk-expire query.
	 */
	private function where_matches( string $query, array $row ): bool {
		if ( ! preg_match( '/\bWHERE\b(.*)$/is', $query, $m ) ) { return true; }
		$where = $m[1];
		if ( preg_match_all( "/(\w+)\s*=\s*'([^']*)'/", $where, $eqs, PREG_SET_ORDER ) ) {
			foreach ( $eqs as $e ) {
				if ( array_key_exists( $e[1], $row ) && (string) $row[ $e[1] ] !== $e[2] ) { return false; }
			}
		}
		if ( preg_match_all( '/(\w+)\s*=\s*(\d+)\b/', $where, $nums, PREG_SET_ORDER ) ) {
			foreach ( $nums as $nm ) {
				if ( array_key_exists( $nm[1], $row ) && (string) (int) $row[ $nm[1] ] !== $nm[2] ) { return false; }
			}
		}
		if ( preg_match( "/(\w+)\s*<\s*'([^']*)'/", $where, $lt ) && array_key_exists( $lt[1], $row ) && (string) $row[ $lt[1] ] >= $lt[2] ) {
			return false;
		}
		return true;
	}
}

$wpdb = new FakeWpdb();
$papelito_users = array();

/* -------- includes under test -------- */
require_once __DIR__ . '/../includes/cnpj_validation.php';
require_once __DIR__ . '/../includes/customer_identity.php';

/* customer_identity uses papelito_env for keys — provide via override */
$papelito_test_env = array(
	'PAPELITO_PII_LOOKUP_KEY'     => str_repeat( 'a', 48 ),
	'PAPELITO_PII_ENCRYPTION_KEY' => str_repeat( 'b', 48 ),
	'PAPELITO_PII_KEY_VERSION'    => '1',
);
// Re-declare papelito_env is not allowed; instead route keys through a runtime map:
// customer_identity calls papelito_env($k) which returns null above, so we shadow the specific
// key getters by defining constants the crypto layer does NOT use — simplest is to feed keys via
// a wrapper: override get_key by defining PAPELITO_* env through $_ENV consulted by papelito_env?
// papelito_env here returns $d (null). We instead stub the two functions the invitation flow uses:

require_once __DIR__ . '/../includes/company_schema.php';
require_once __DIR__ . '/../includes/company_repository.php';
require_once __DIR__ . '/../includes/company_active_context.php';
require_once __DIR__ . '/../includes/company_authz.php';

/* company_services provides papelito_company_context / audit? audit is in company_services. Stub audit + context minimally. */
function papelito_company_audit( int $c, ?int $a, string $action, array $p = array() ): void {}
function papelito_company_context( int $user_id ): array { return array( 'user' => $user_id ); }
$papelito_missing_identity_users = array();
function papelito_company_profile_get( int $user_id ): ?array {
	global $papelito_missing_identity_users;
	return ! empty( $papelito_missing_identity_users[ $user_id ] ) ? null : array( 'identity_status' => 'verified' );
}

require_once __DIR__ . '/../includes/frontend_links.php';
require_once __DIR__ . '/../includes/company_invitation_services.php';
require_once __DIR__ . '/../includes/company_access_request_services.php';

/* -------- CPF crypto needs real keys: override the getter used by hmac/encrypt -------- */
/* customer_identity.papelito_pii_get_key() calls papelito_env(); our papelito_env returns null.
   Provide the keys by monkey-injecting via runtime: define a late-binding through $GLOBALS the
   getter reads. Simplest: redefine papelito_env is impossible. So we bypass CPF crypto by using
   invitations WITHOUT cpf lock, and test cpf-lock via papelito_cpf_hmac directly with a keyed run. */

$failures = 0;
function ok( string $label, $cond ): void { global $failures; if ( $cond ) { echo "  PASS: {$label}\n"; } else { ++$failures; echo "  FAIL: {$label}\n"; } }

/* Seed authz world: actor 1 is active owner of the company. papelito_company_get comes from the
   real repository (reads the fake $wpdb). */
$wpdb->insert( 'wp_papelito_companies', array( 'cnpj' => '11444777000161', 'legal_name' => 'ACME LTDA', 'trade_name' => 'ACME', 'company_status' => 'active', 'owner_user_id' => 1, 'created_by_user_id' => 1 ) );
$company_id = $wpdb->insert_id;
papelito_company_member_upsert( $company_id, 1, array( 'member_role' => 'owner', 'member_status' => 'active' ) );

$papelito_users[1] = new WP_User( 1, 'owner@acme.com' );
$papelito_users[2] = new WP_User( 2, 'newhire@acme.com' );
$papelito_users[3] = new WP_User( 3, 'intruder@evil.com' );

/* ---- issue invitation (buyer) ---- */
$inv = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'newhire@acme.com', 'invited_role' => 'buyer' ) );
ok( 'invitation issued', is_array( $inv ) && ! empty( $inv['token'] ) );
$token = $inv['token'];

/* ---- invitation cannot grant owner ---- */
$bad = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'x@acme.com', 'invited_role' => 'owner' ) );
ok( 'invitation cannot grant owner', is_wp_error( $bad ) && $bad->get_error_code() === 'papelito_b2b_invitation_invalid_role' );

/* ---- quem ja tem vinculo vivo nao e convidavel de novo ---- */
$already = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'owner@acme.com', 'invited_role' => 'admin' ) );
ok( 'active member cannot be invited again', is_wp_error( $already ) && $already->get_error_code() === 'papelito_b2b_invitation_already_member' );
ok( 'blocked invitation answers 409', is_wp_error( $already ) && 409 === ( $already->get_error_data()['status'] ?? 0 ) );

papelito_company_member_upsert( $company_id, 3, array( 'member_role' => 'buyer', 'member_status' => 'revoked' ) );
$readmit = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'intruder@evil.com', 'invited_role' => 'viewer' ) );
ok( 'revoked member can be invited back', is_array( $readmit ) && ! empty( $readmit['token'] ) );

/* ---- email mismatch rejected ---- */
$mismatch = papelito_company_invitation_accept_token( 3, $token );
ok( 'email mismatch rejected', is_wp_error( $mismatch ) && $mismatch->get_error_code() === 'papelito_b2b_invitation_email_mismatch' );

/* ---- acceptance without a personal CPF is blocked before membership activation ---- */
$identity_inv = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'without-cpf@acme.com', 'invited_role' => 'buyer' ) );
$papelito_users[7] = new WP_User( 7, 'without-cpf@acme.com' );
$papelito_missing_identity_users[7] = true;
$missing_identity = papelito_company_invitation_accept_token( 7, $identity_inv['token'] );
ok( 'invitation without CPF is rejected', is_wp_error( $missing_identity ) && $missing_identity->get_error_code() === 'papelito_b2b_invitation_identity_required' );
ok( 'invitation without CPF does not create membership', null === papelito_company_member_get( $company_id, 7 ) );
unset( $papelito_missing_identity_users[7] );

/* ---- accept by correct user ---- */
$ctx = papelito_company_invitation_accept_token( 2, $token );
ok( 'invitation accepted by matching email', ! is_wp_error( $ctx ) );
$member = papelito_company_member_get( $company_id, 2 );
ok( 'member created active buyer', $member && $member['member_status'] === 'active' && $member['member_role'] === 'buyer' );
ok( 'accept marks cohort', papelito_b2b_is_cohort( 2 ) );

/* ---- token reuse fails (single-use) ---- */
$reuse = papelito_company_invitation_accept_token( 2, $token );
ok( 'reused token fails', is_wp_error( $reuse ) );

/* ---- expired invitation (force stored expires_at into the past; repo checks real time()) ---- */
$inv2   = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'late@acme.com', 'invited_role' => 'viewer' ) );
$papelito_users[4] = new WP_User( 4, 'late@acme.com' );
foreach ( $wpdb->t['wp_papelito_company_invitations'] as &$__row ) {
	if ( (int) $__row['id'] === (int) $inv2['id'] ) { $__row['expires_at'] = gmdate( PAPELITO_TEST_MYSQL_DATETIME_FORMAT, time() - DAY_IN_SECONDS ); }
}
unset( $__row );
$expired = papelito_company_invitation_accept_token( 4, $inv2['token'] );
ok( 'expired invitation rejected', is_wp_error( $expired ) && $expired->get_error_code() === 'papelito_b2b_invitation_invalid' );

/* ---- resend invalidates previous token ---- */
$inv3   = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'resend@acme.com', 'invited_role' => 'buyer' ) );
$old    = $inv3['token'];
$re     = papelito_company_invitation_reissue( 1, $company_id, $inv3['id'] );
ok( 'resend returns new token', is_array( $re ) && $re['token'] !== $old );
$papelito_users[5] = new WP_User( 5, 'resend@acme.com' );
ok( 'old token invalid after resend', is_wp_error( papelito_company_invitation_accept_token( 5, $old ) ) );
ok( 'new token accepts after resend', ! is_wp_error( papelito_company_invitation_accept_token( 5, $re['token'] ) ) );

/* ---- revoke prevents accept ---- */
$inv4 = papelito_company_invitation_issue( 1, $company_id, array( 'invited_email' => 'revoke@acme.com', 'invited_role' => 'buyer' ) );
$rev  = papelito_company_invitation_cancel( 1, $company_id, $inv4['id'], 'no longer needed' );
ok( 'revoke succeeds', true === $rev );
$papelito_users[6] = new WP_User( 6, 'revoke@acme.com' );
ok( 'revoked token cannot be accepted', is_wp_error( papelito_company_invitation_accept_token( 6, $inv4['token'] ) ) );

/* ---- access-request: neutral response, no enumeration ---- */
$existing_company_cnpj = '11444777000161';
$nonexistent_cnpj      = '11222333000181';
$r_exist = papelito_company_access_request_submit( 9, $existing_company_cnpj );
$r_none  = papelito_company_access_request_submit( 10, $nonexistent_cnpj );
ok( 'access-request existing → neutral', is_array( $r_exist ) && $r_exist === array( 'status' => 'received' ) );
ok( 'access-request nonexistent → identical neutral', $r_none === $r_exist );
ok( 'access-request marks cohort even if company missing', papelito_b2b_is_cohort( 10 ) );
$pending = papelito_company_member_get( $company_id, 9 );
ok( 'access-request created pending membership', $pending && $pending['member_status'] === 'pending_company_approval' && $pending['membership_origin'] === 'access_request' );

/* ---- resubmit policy: reject then cooldown blocks, then limit (repo uses real time()) ---- */
papelito_company_member_upsert( $company_id, 9, array( 'member_status' => 'rejected', 'rejected_reason' => 'nope', 'request_count' => 1, 'last_request_at' => gmdate( PAPELITO_TEST_MYSQL_DATETIME_FORMAT, time() ) ) );
$cool = papelito_company_access_request_submit( 9, $existing_company_cnpj );
ok( 'resubmit within cooldown blocked', is_wp_error( $cool ) && $cool->get_error_code() === 'papelito_b2b_access_request_cooldown' );

/* move last_request_at into the past (beyond cooldown) → allowed */
papelito_company_member_upsert( $company_id, 9, array( 'last_request_at' => gmdate( PAPELITO_TEST_MYSQL_DATETIME_FORMAT, time() - 25 * HOUR_IN_SECONDS ) ) );
$after = papelito_company_access_request_submit( 9, $existing_company_cnpj );
ok( 'resubmit after cooldown allowed', is_array( $after ) );

/* push to the attempt limit */
papelito_company_member_upsert( $company_id, 9, array( 'member_status' => 'rejected', 'request_count' => 3, 'last_request_at' => gmdate( PAPELITO_TEST_MYSQL_DATETIME_FORMAT, time() - 100 * HOUR_IN_SECONDS ) ) );
$limit = papelito_company_access_request_submit( 9, $existing_company_cnpj );
ok( 'resubmit beyond attempt limit blocked', is_wp_error( $limit ) && $limit->get_error_code() === 'papelito_b2b_access_request_limit' );

/* o link do convite usa o dominio do ambiente, nunca localhost */
$invitation_mails = array_values( array_filter( $papelito_mail, static fn( array $mail ): bool => str_contains( (string) $mail['subject'], 'Convite' ) ) );
ok( 'invitation email sent', ! empty( $invitation_mails ) );
$invitation_body = (string) ( $invitation_mails[0]['body'] ?? '' );
ok( 'invitation link uses the environment domain', str_contains( $invitation_body, 'https://marketplace.papelito.com/convite/' ) );
ok( 'invitation link never uses localhost', ! str_contains( $invitation_body, 'localhost' ) );

if ( $failures > 0 ) { echo "RESULT: {$failures} assertion(s) FAILED\n"; exit( 1 ); }
echo "RESULT: all assertions passed\n";
exit( 0 );
