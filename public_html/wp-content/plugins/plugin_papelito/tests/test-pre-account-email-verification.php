<?php
/**
 * Standalone regression test: conta nova nunca nasce com e-mail tido como verificado.
 *
 * `papelito_auth_requires_email_verification()` trata usermeta ausente como conta legada
 * verificada — tolerancia intencional, porque a base antiga nao tem a meta. O efeito colateral e
 * que qualquer caminho de criacao de conta que esqueca de gravar a meta produz uma conta nova
 * indistinguivel de uma legada: o e-mail entra como confirmado sem ninguem ter aberto nada, e o
 * e-mail de faturamento da empresa herda essa confirmacao inexistente.
 *
 * Era o caso da aprovacao de pre-conta (que nem envia e-mail: o `resume_token` volta na resposta) e
 * do provisionamento de vendor. Este teste e estrutural de proposito: o que precisa ser garantido e
 * que NENHUM `wp_insert_user()` do plugin fique sem gravar a meta, inclusive os que vierem depois.
 *
 * Usage: php tests/test-pre-account-email-verification.php
 *
 * @package Papelito
 */

$failures = 0;
function papelito_assert( string $label, $expected, $actual ): void {
	global $failures;

	if ( $expected === $actual ) {
		echo "  PASS: {$label}\n";

		return;
	}

	++$failures;
	echo '  FAIL: ' . $label . ' — esperado ' . var_export( $expected, true ) . ', obtido ' . var_export( $actual, true ) . "\n";
}

/**
 * Lista os arquivos PHP do plugin, fora de tests/ e vendor/.
 *
 * @param string $root Raiz do plugin.
 * @return array<int,string>
 */
function papelito_plugin_php_files( string $root ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		$path = $file->getPathname();

		if ( 'php' !== $file->getExtension() || str_contains( $path, '/tests/' ) || str_contains( $path, '/vendor/' ) ) {
			continue;
		}

		$files[] = $path;
	}

	sort( $files );

	return $files;
}

/**
 * Localiza criacoes de usuario que nao gravam o estado de verificacao de e-mail.
 *
 * @param array<int,string> $files Arquivos a inspecionar.
 * @param int               $window Linhas apos a criacao em que a marcacao ainda conta.
 * @return array<int,string> Ocorrencias em `arquivo:linha`.
 */
function papelito_unmarked_user_creations( array $files, int $window = 40 ): array {
	$offenders = array();

	foreach ( $files as $path ) {
		$lines = file( $path, FILE_IGNORE_NEW_LINES );

		foreach ( $lines as $index => $line ) {
			if ( ! str_contains( $line, 'wp_insert_user(' ) || str_contains( $line, 'function ' ) ) {
				continue;
			}

			$following = implode( "\n", array_slice( $lines, $index, $window ) );

			if ( str_contains( $following, 'papelito_auth_mark_email_pending' ) || str_contains( $following, 'papelito_auth_mark_email_verified' ) ) {
				continue;
			}

			$offenders[] = basename( $path ) . ':' . ( $index + 1 );
		}
	}

	return $offenders;
}

$root  = dirname( __DIR__ );
$files = papelito_plugin_php_files( $root );

papelito_assert( 'encontrou os arquivos do plugin', true, count( $files ) > 10 );

$creations = 0;
foreach ( $files as $path ) {
	$creations += substr_count( (string) file_get_contents( $path ), 'wp_insert_user(' );
}
papelito_assert( 'ha criacoes de usuario para inspecionar', true, $creations > 0 );

$offenders = papelito_unmarked_user_creations( $files );
papelito_assert(
	'todo wp_insert_user grava o estado de verificacao de e-mail (' . implode( ', ', $offenders ) . ')',
	array(),
	$offenders
);

/* --- a empresa criada na aprovacao de pre-conta nasce sem verificacao herdada --- */
$pre_account = (string) file_get_contents( $root . '/includes/company_pre_account_applications.php' );

papelito_assert(
	'aprovacao de pre-conta marca o e-mail como pendente',
	true,
	str_contains( $pre_account, 'papelito_auth_mark_email_pending( $user_id );' )
);
papelito_assert(
	'empresa da pre-conta nasce sem billing_email_verified_at',
	true,
	str_contains( $pre_account, "'billing_email_verified_at' => null," )
);
papelito_assert(
	'nao herda mais a verificacao da conta recem-criada',
	false,
	str_contains( $pre_account, "'billing_email_verified_at' => papelito_billing_email_account_is_verified" )
);
papelito_assert(
	'aprovacao dispara o e-mail de confirmacao',
	true,
	str_contains( $pre_account, 'papelito_pre_account_application_dispatch_verification( $user_id );' )
);

/* --- e o envio acontece depois do COMMIT, nunca antes --- */
$commit   = strpos( $pre_account, "\$wpdb->query( 'COMMIT' )" );
$dispatch = strpos( $pre_account, 'papelito_pre_account_application_dispatch_verification( $user_id );' );
papelito_assert( 'envio fica depois do COMMIT', true, false !== $commit && false !== $dispatch && $dispatch > $commit );

/* --- a cascata que confirma a empresa segue ligada ao hook de verificacao --- */
$sync = (string) file_get_contents( $root . '/includes/billing_email_sync.php' );
papelito_assert(
	'sync de faturamento continua inscrito em papelito_email_verified',
	true,
	str_contains( $sync, "add_action( 'papelito_email_verified', 'papelito_billing_email_sync_for_user' );" )
);

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAIL\n";
exit( 0 === $failures ? 0 : 1 );
