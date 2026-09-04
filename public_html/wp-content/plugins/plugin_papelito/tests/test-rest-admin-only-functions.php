<?php
/**
 * Standalone regression test: codigo servido por REST nao chama funcao exclusiva do wp-admin.
 *
 * `wp_delete_user()` so e declarada em `wp-admin/includes/user.php`, que nao e carregado numa
 * requisicao REST. Chamar direto derruba a requisicao com erro critico ANTES de apagar nada — e o
 * rollback de uma conta criada pela metade vira o oposto: a conta parcial fica no banco, sem perfil
 * de CPF e sem a meta de verificacao, que `papelito_auth_requires_email_verification()` le como
 * "conta legada verificada". Foi exatamente o que acontecia no cadastro por convite com CPF
 * duplicado: HTTP 500 e conta ativa sem ninguem ter confirmado o e-mail.
 *
 * Estrutural de proposito: vale para as chamadas que vierem depois, nao so para as de hoje.
 *
 * Usage: php tests/test-rest-admin-only-functions.php
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
 * Chamadas a funcoes so-wp-admin sem a guarda que carrega o arquivo que as declara.
 *
 * A guarda tem de estar no MESMO trecho da chamada — carregar o include noutro ponto do arquivo
 * (por exemplo dentro de um helper) nao protege quem chama a funcao crua em outro lugar. Vale
 * tanto o require literal quanto o require montado em laco, que catalog-pdf.php usa.
 *
 * @param array<int,string>    $files     Arquivos a inspecionar.
 * @param array<string,string> $functions Funcao => arquivo do wp-admin que a declara.
 * @param int                  $window    Linhas anteriores em que a guarda ainda conta.
 * @return array<int,string> Ocorrencias em `arquivo:linha`.
 */
function papelito_unguarded_admin_calls( array $files, array $functions, int $window = 30 ): array {
	$offenders = array();

	foreach ( $files as $path ) {
		$lines = file( $path, FILE_IGNORE_NEW_LINES );

		foreach ( $lines as $index => $line ) {
			foreach ( $functions as $function => $include ) {
				$trimmed = ltrim( $line );
				if ( str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '/*' ) || str_starts_with( $trimmed, '//' ) ) {
					continue;
				}
				if ( ! str_contains( $line, $function . '(' ) || str_contains( $line, 'function ' . $function ) ) {
					continue;
				}

				$preceding = implode( "\n", array_slice( $lines, max( 0, $index - $window ), min( $window, $index ) ) );
				$guarded   = str_contains( $preceding, 'wp-admin/includes/' )
					&& str_contains( $preceding, basename( $include ) );

				if ( $guarded ) {
					continue;
				}

				$offenders[] = basename( $path ) . ':' . ( $index + 1 );
			}
		}
	}

	return $offenders;
}

$root  = dirname( __DIR__ );
$files = papelito_plugin_php_files( $root );

papelito_assert( 'encontrou os arquivos do plugin', true, count( $files ) > 10 );

$admin_only = array(
	'wp_delete_user' => 'wp-admin/includes/user.php',
	'wp_tempnam'     => 'wp-admin/includes/file.php',
	'wp_handle_upload' => 'wp-admin/includes/file.php',
	'media_handle_sideload' => 'wp-admin/includes/media.php',
);

$offenders = papelito_unguarded_admin_calls( $files, $admin_only );
papelito_assert(
	'toda chamada a funcao so-wp-admin carrega o include antes (' . implode( ', ', $offenders ) . ')',
	array(),
	$offenders
);

/* --- o cadastro por convite recusa CPF de outra conta ANTES de criar o usuario --- */
$auth = (string) file_get_contents( $root . '/includes/auth_endpoints.php' );

$check  = strpos( $auth, 'papelito_customer_profile_find_user_by_cpf( (string) $data[\'cpf\'] )' );
$insert = strpos( $auth, "'user_pass' => (string) \$data['password']," );
papelito_assert( 'CPF e conferido antes do wp_insert_user do convite', true, false !== $check && false !== $insert && $check < $insert );

/* --- e a conta nasce marcada como pendente antes de qualquer passo que possa falhar --- */
$pending = strpos( $auth, 'papelito_auth_mark_email_pending( $user_id );', $insert === false ? 0 : $insert );
$profile = strpos( $auth, "'identity_method'     => 'invitation_registration'," );
papelito_assert(
	'e-mail vira pendente antes do perfil de CPF no cadastro por convite',
	true,
	false !== $pending && false !== $profile && $pending < $profile
);

echo 0 === $failures ? "\nALL PASS\n" : "\n{$failures} FAIL\n";
exit( 0 === $failures ? 0 : 1 );
