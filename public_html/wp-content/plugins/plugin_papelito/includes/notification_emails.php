<?php
/**
 * Camada de apresentacao dos e-mails transacionais da Papelito.
 *
 * Reproduz a identidade da marca dentro das limitacoes de cliente de e-mail:
 * tabelas, estilo inline, sem box-shadow, sem flex e sem webfont. A sombra dura
 * e o quadro de 2px sao desenhados com celulas de tabela.
 *
 * @package Papelito
 */

defined( 'ABSPATH' ) || exit;

const PAPELITO_EMAIL_INK        = '#231f20';
const PAPELITO_EMAIL_YELLOW     = '#ffe500';
const PAPELITO_EMAIL_KRAFT      = '#faf8f2';
const PAPELITO_EMAIL_PAPER      = '#ffffff';
const PAPELITO_EMAIL_TEXT_SOFT  = '#4a5565';
const PAPELITO_EMAIL_TEXT_LABEL = '#596375';
const PAPELITO_EMAIL_FOOTER_INK = '#d1d5dc';
const PAPELITO_EMAIL_FONT_STACK = "'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
const PAPELITO_EMAIL_WIDTH      = 600;

/** Respiro interno da chapa branca de conteudo. */
const PAPELITO_EMAIL_PLATE_PADDING = '20px 22px';

/**
 * Divisor claro entre linhas de dado — a tinta a 10% chapada sobre o branco.
 *
 * `rgba()` nao e confiavel no Outlook, entao a transparencia ja vem resolvida.
 */
const PAPELITO_EMAIL_RULE = '#e8e8e8';

/** Respiro interno da chapa amarela de destaque, mais apertado por ser menor. */
const PAPELITO_EMAIL_ACCENT_PADDING = '16px 20px';

/** Dimensoes de exibicao do logo na tarja — as mesmas do rodape publico. */
const PAPELITO_EMAIL_LOGO_WIDTH  = 183;
const PAPELITO_EMAIL_LOGO_HEIGHT = 31;

/**
 * URL absoluta do logo branco usado na tarja de todo e-mail.
 *
 * O arquivo e o mesmo desenho de `papelito-web/public/images/logo3.svg`
 * rasterizado em 2x sobre a tinta da tarja: cliente de e-mail nao renderiza SVG
 * e o fundo chapado evita que o modo escuro inverta o wordmark. Fica em codigo,
 * versionado com o plugin — a identidade do e-mail nao e administravel.
 *
 * @return string
 */
function papelito_email_logo_url(): string {
	return plugins_url(
		'assets/email/papelito-logo-email@2x.png',
		dirname( __DIR__ ) . '/plugin_papelito.php'
	);
}

/**
 * Wordmark da tarja: imagem quando carrega, texto da marca quando bloqueada.
 *
 * O `alt` recebe o mesmo tratamento tipografico do wordmark antigo, entao o
 * cliente que bloqueia imagem continua mostrando PAPELITO em caixa alta branca
 * no lugar exato — nao um icone quebrado.
 *
 * @return string
 */
function papelito_email_logo(): string {
	return sprintf(
		'<img class="papelito-logo" src="%1$s" width="%2$d" height="%3$d" alt="Papelito" style="display:block;width:%2$dpx;height:%3$dpx;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;font-family:%4$s;font-size:19px;font-weight:900;letter-spacing:0.22em;text-transform:uppercase;color:#ffffff;" />',
		esc_url( papelito_email_logo_url() ),
		PAPELITO_EMAIL_LOGO_WIDTH,
		PAPELITO_EMAIL_LOGO_HEIGHT,
		PAPELITO_EMAIL_FONT_STACK
	);
}

/**
 * Dados publicos do remetente, exibidos no rodape de todo e-mail.
 *
 * @return array{name:string,tax_id:string,address:string,age_notice:string}
 */
function papelito_email_sender_identity(): array {
	return array(
		'name'       => 'Papelito Brasil',
		'tax_id'     => 'CNPJ 14.536.755/0001-10',
		'address'    => 'SIA Trecho 4, Brasília-DF',
		'age_notice' => 'Venda proibida para menores de 18 anos.',
	);
}

/**
 * Chapa da marca: fundo, quadro preto de 2px e sombra dura deslocada.
 *
 * A sombra e o proprio fundo da celula externa, revelado pelo padding — a unica
 * forma de `box-shadow: Npx Npx 0` que o Outlook desenha.
 *
 * @param string              $inner_html Conteudo ja escapado.
 * @param array<string,mixed> $options    background, padding, shadow, offset, width, align.
 * @return string
 */
function papelito_email_plate( string $inner_html, array $options = array() ): string {
	$background = (string) ( $options['background'] ?? PAPELITO_EMAIL_PAPER );
	$padding    = (string) ( $options['padding'] ?? '0' );
	$shadow     = (string) ( $options['shadow'] ?? PAPELITO_EMAIL_INK );
	$offset     = absint( $options['offset'] ?? 8 );
	$width      = isset( $options['width'] ) ? absint( $options['width'] ) : 0;
	$align      = (string) ( $options['align'] ?? 'left' );

	$outer_attrs = $width > 0
		? sprintf( ' width="%d" align="%s" style="width:%dpx;border-collapse:separate;"', $width, esc_attr( $align ), $width )
		: ' width="100%" style="width:100%;border-collapse:separate;"';

	return sprintf(
		'<table role="presentation" border="0" cellpadding="0" cellspacing="0"%1$s>
			<tr>
				<td style="background-color:%2$s;padding:0 %3$dpx %3$dpx 0;font-size:0;line-height:0;">
					<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;border-collapse:collapse;">
						<tr>
							<td style="background-color:%4$s;border:2px solid %5$s;padding:%6$s;">%7$s</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>',
		$outer_attrs,
		esc_attr( $shadow ),
		$offset,
		esc_attr( $background ),
		esc_attr( PAPELITO_EMAIL_INK ),
		esc_attr( $padding ),
		$inner_html
	);
}

/**
 * Rotulo curto em caixa alta — a voz de comando da marca.
 *
 * @param string $text  Texto do rotulo.
 * @param string $color Cor do texto.
 * @return string
 */
function papelito_email_label( string $text, string $color = PAPELITO_EMAIL_TEXT_LABEL ): string {
	if ( '' === trim( $text ) ) {
		return '';
	}

	return sprintf(
		'<span style="font-family:%s;font-size:11px;font-weight:900;line-height:16px;letter-spacing:0.18em;text-transform:uppercase;color:%s;">%s</span>',
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( $color ),
		esc_html( $text )
	);
}

/**
 * Botao principal: bloco preto com texto amarelo e sombra dura amarela.
 *
 * @param string $url   Destino.
 * @param string $label Texto do botao, ja no imperativo.
 * @return string
 */
function papelito_email_button( string $url, string $label ): string {
	$anchor = sprintf(
		'<a href="%1$s" style="display:block;padding:18px 24px;font-family:%2$s;font-size:13px;font-weight:900;line-height:18px;letter-spacing:0.18em;text-transform:uppercase;text-align:center;text-decoration:none;color:%3$s;background-color:%4$s;">%5$s</a>',
		esc_url( $url ),
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_YELLOW ),
		esc_attr( PAPELITO_EMAIL_INK ),
		esc_html( $label )
	);

	return papelito_email_plate(
		$anchor,
		array(
			'background' => PAPELITO_EMAIL_INK,
			'shadow'     => PAPELITO_EMAIL_YELLOW,
			'offset'     => 5,
		)
	);
}

/**
 * Casca compartilhada: tarja da marca, fita amarela, corpo kraft e rodape.
 *
 * @param array<string,mixed> $parts kicker, preheader, body_html, footer_lines.
 * @return string
 */
function papelito_email_shell( array $parts ): string {
	$sender    = papelito_email_sender_identity();
	$kicker    = (string) ( $parts['kicker'] ?? '' );
	$preheader = (string) ( $parts['preheader'] ?? '' );
	$body      = (string) ( $parts['body_html'] ?? '' );
	$footer    = array_values( array_filter( (array) ( $parts['footer_lines'] ?? array() ) ) );

	$footer_html = '';
	foreach ( $footer as $line ) {
		$footer_html .= sprintf(
			'<p style="margin:0 0 8px;font-family:%s;font-size:12px;font-weight:500;line-height:18px;color:%s;">%s</p>',
			PAPELITO_EMAIL_FONT_STACK,
			esc_attr( PAPELITO_EMAIL_FOOTER_INK ),
			$line
		);
	}

	return sprintf(
		'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="pt-BR">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<title>%1$s</title>
<style type="text/css">
	body { margin:0 !important; padding:0 !important; width:100%% !important; }
	img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; }
	table { border-collapse:collapse; }
	a { color:%2$s; }
	@media only screen and (max-width:620px) {
		.papelito-shell { width:100%% !important; }
		.papelito-pad { padding-left:20px !important; padding-right:20px !important; }
		.papelito-headline { font-size:26px !important; }
		.papelito-price { font-size:34px !important; }
		.papelito-stack { display:block !important; width:100%% !important; }
		.papelito-stack-media { padding:0 0 18px 0 !important; }
		.papelito-media { width:100%% !important; max-width:100%% !important; }
		.papelito-offset { width:100%% !important; }
		.papelito-offset-spacer { width:20px !important; }
		.papelito-logo { width:150px !important; height:26px !important; }
	}
</style>
</head>
<body style="margin:0;padding:0;background-color:%3$s;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">%4$s</div>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;background-color:%3$s;">
	<tr>
		<td align="center" style="padding:24px 12px 32px;">
			<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="%5$d" class="papelito-shell" style="width:%5$dpx;max-width:%5$dpx;border:2px solid %6$s;">
				<tr>
					<td class="papelito-pad" style="background-color:%6$s;padding:22px 28px;">
						<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;">
							<tr>
								<td align="left" valign="middle" style="line-height:0;font-size:0;">%15$s</td>
								<td align="right" valign="middle" style="font-family:%7$s;font-size:10px;font-weight:900;line-height:16px;letter-spacing:0.22em;text-transform:uppercase;color:%2$s;padding-left:16px;">%8$s</td>
							</tr>
						</table>
					</td>
				</tr>
				<tr>
					<td style="background-color:%2$s;font-size:0;line-height:0;height:8px;">&nbsp;</td>
				</tr>
				<tr>
					<td class="papelito-pad" style="background-color:%3$s;padding:32px 28px 36px;">%9$s</td>
				</tr>
				<tr>
					<td class="papelito-pad" style="background-color:%6$s;padding:24px 28px;">
						%10$s
						<p style="margin:16px 0 0;font-family:%7$s;font-size:11px;font-weight:900;line-height:16px;letter-spacing:0.14em;text-transform:uppercase;color:%2$s;">%11$s</p>
						<p style="margin:6px 0 0;font-family:%7$s;font-size:11px;font-weight:500;line-height:16px;color:#8a8f99;">%12$s &middot; %13$s</p>
						<p style="margin:6px 0 0;font-family:%7$s;font-size:11px;font-weight:500;line-height:16px;color:#8a8f99;">%14$s</p>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>',
		esc_html( $kicker ),
		esc_attr( PAPELITO_EMAIL_YELLOW ),
		esc_attr( PAPELITO_EMAIL_KRAFT ),
		esc_html( $preheader ),
		PAPELITO_EMAIL_WIDTH,
		esc_attr( PAPELITO_EMAIL_INK ),
		PAPELITO_EMAIL_FONT_STACK,
		esc_html( $kicker ),
		$body,
		$footer_html,
		esc_html( $sender['name'] ),
		esc_html( $sender['tax_id'] ),
		esc_html( $sender['address'] ),
		esc_html( $sender['age_notice'] ),
		papelito_email_logo()
	);
}

/**
 * Titulo e linha de abertura do corpo.
 *
 * @param string $headline Frase de manchete, ja em caixa alta.
 * @param string $lead     Paragrafo de abertura, ja escapado.
 * @return string
 */
function papelito_email_headline( string $headline, string $lead ): string {
	return sprintf(
		'<h1 class="papelito-headline" style="margin:0;font-family:%1$s;font-size:31px;font-weight:900;line-height:1.04;letter-spacing:-0.02em;text-transform:uppercase;color:%2$s;">%3$s</h1>
		<p style="margin:14px 0 0;font-family:%1$s;font-size:15px;font-weight:500;line-height:24px;color:%4$s;">%5$s</p>',
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_INK ),
		esc_html( $headline ),
		esc_attr( PAPELITO_EMAIL_TEXT_SOFT ),
		$lead
	);
}

/**
 * Corpo HTML do convite de empresa, usando a mesma casca dos e-mails de campanha.
 *
 * @param array<string,string> $view company_name, inviter_name, link, expires_at.
 * @return string
 */
function papelito_company_invitation_email_html( array $view ): string {
	$company = trim( (string) ( $view['company_name'] ?? '' ) );
	$inviter = trim( (string) ( $view['inviter_name'] ?? '' ) );
	$link    = (string) ( $view['link'] ?? '' );
	$expires = trim( (string) ( $view['expires_at'] ?? '' ) );
	$who     = '' !== $inviter ? sprintf( '<strong style="font-weight:900;">%s</strong>', esc_html( $inviter ) ) : 'uma pessoa da empresa';
	$lead    = sprintf( '%s convidou você para fazer parte de <strong style="font-weight:900;">%s</strong> no Papelito.', $who, esc_html( $company ) );
	$context = sprintf(
		'<p style="margin:0;font-family:%1$s;font-size:14px;font-weight:500;line-height:22px;color:%2$s;">Ao continuar, você cria sua conta e aceita receber acesso à empresa com o papel definido no convite.</p>%3$s',
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_TEXT_SOFT ),
		'' !== $expires ? sprintf( '<p style="margin:12px 0 0;font-family:%1$s;font-size:13px;font-weight:900;line-height:20px;color:%2$s;">Este convite é válido até %3$s.</p>', PAPELITO_EMAIL_FONT_STACK, esc_attr( PAPELITO_EMAIL_INK ), esc_html( $expires ) ) : ''
	);

	$body  = papelito_email_headline( 'Você foi convidado.', $lead );
	$body .= '<div style="height:24px;line-height:24px;font-size:0;">&nbsp;</div>';
	$body .= papelito_email_plate(
		$context,
		array(
			'background' => PAPELITO_EMAIL_PAPER,
			'padding'    => PAPELITO_EMAIL_PLATE_PADDING,
			'offset'     => 6,
		)
	);
	$body .= '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>';
	$body .= papelito_email_button( $link, 'Aceitar convite' );

	return papelito_email_shell(
		array(
			'kicker'       => 'Acesso à empresa',
			'preheader'    => sprintf( 'Convite para fazer parte de %s no Papelito.', $company ),
			'body_html'    => $body,
			'footer_lines' => array( 'Se você não esperava este convite, ignore este e-mail.' ),
		)
	);
}

/**
 * Aviso transacional simples: manchete, chamada, fatos, acao e observacoes.
 *
 * Quase todo e-mail do produto tem esta forma. Ter uma montagem so evita que
 * cada rotina invente a propria casca — foi assim que os avisos em texto puro
 * apareceram — e mantem tarja, logo, fita e rodape iguais em todos eles.
 *
 * @param array<string,mixed> $view kicker, preheader, headline, lead, facts,
 *                                  cta{label,url}, notes, footer_lines.
 * @return string
 */
function papelito_email_notice_html( array $view ): string {
	$facts = isset( $view['facts'] ) && is_array( $view['facts'] ) ? $view['facts'] : array();
	$cta   = isset( $view['cta'] ) && is_array( $view['cta'] ) ? $view['cta'] : array();
	$notes = array_values( array_filter( (array) ( $view['notes'] ?? array() ) ) );

	$body = papelito_email_headline(
		(string) ( $view['headline'] ?? '' ),
		esc_html( (string) ( $view['lead'] ?? '' ) )
	);

	if ( ! empty( $facts ) ) {
		$rows = '';

		foreach ( $facts as $label => $value ) {
			$rows .= sprintf(
				'<tr><td style="%1$spadding:14px 20px;">%2$s<p style="margin:5px 0 0;font-family:%3$s;font-size:15px;font-weight:900;line-height:21px;color:%4$s;word-break:break-word;">%5$s</p></td></tr>',
				'' !== $rows ? sprintf( 'border-top:2px solid %s;', esc_attr( PAPELITO_EMAIL_RULE ) ) : '',
				papelito_email_label( (string) $label ),
				PAPELITO_EMAIL_FONT_STACK,
				esc_attr( PAPELITO_EMAIL_INK ),
				esc_html( (string) $value )
			);
		}

		$body .= '<div style="height:24px;line-height:24px;font-size:0;">&nbsp;</div>';
		$body .= papelito_email_plate(
			sprintf(
				'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;border-collapse:collapse;">%s</table>',
				$rows
			),
			array(
				'background' => PAPELITO_EMAIL_PAPER,
				'padding'    => '0',
				'offset'     => 6,
			)
		);
	}

	if ( '' !== (string) ( $cta['url'] ?? '' ) && '' !== (string) ( $cta['label'] ?? '' ) ) {
		$body .= '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>';
		$body .= papelito_email_button( (string) $cta['url'], (string) $cta['label'] );
	}

	foreach ( $notes as $index => $note ) {
		$body .= sprintf(
			'<p style="margin:%1$s 0 0;font-family:%2$s;font-size:13px;font-weight:500;line-height:20px;color:%3$s;">%4$s</p>',
			0 === $index ? '20px' : '8px',
			PAPELITO_EMAIL_FONT_STACK,
			esc_attr( PAPELITO_EMAIL_TEXT_SOFT ),
			esc_html( (string) $note )
		);
	}

	return papelito_email_shell(
		array(
			'kicker'       => (string) ( $view['kicker'] ?? '' ),
			'preheader'    => (string) ( $view['preheader'] ?? ( $view['lead'] ?? '' ) ),
			'body_html'    => $body,
			'footer_lines' => (array) ( $view['footer_lines'] ?? array() ),
		)
	);
}

/**
 * Alternativa em texto do aviso transacional, com os mesmos dados do HTML.
 *
 * @param array<string,mixed> $view Mesma estrutura de papelito_email_notice_html().
 * @return string
 */
function papelito_email_notice_text( array $view ): string {
	$facts = isset( $view['facts'] ) && is_array( $view['facts'] ) ? $view['facts'] : array();
	$cta   = isset( $view['cta'] ) && is_array( $view['cta'] ) ? $view['cta'] : array();
	$notes = array_values( array_filter( (array) ( $view['notes'] ?? array() ) ) );

	$lines = array( (string) ( $view['headline'] ?? '' ), '', (string) ( $view['lead'] ?? '' ) );

	if ( ! empty( $facts ) ) {
		$lines[] = '';
		foreach ( $facts as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
	}

	if ( '' !== (string) ( $cta['url'] ?? '' ) ) {
		$lines[] = '';
		$lines[] = trim( (string) ( $cta['label'] ?? 'Acessar' ) ) . ': ' . (string) $cta['url'];
	}

	if ( ! empty( $notes ) ) {
		$lines[] = '';
		foreach ( $notes as $note ) {
			$lines[] = (string) $note;
		}
	}

	foreach ( (array) ( $view['footer_lines'] ?? array() ) as $footer_line ) {
		$footer_line = trim( wp_strip_all_tags( (string) $footer_line ) );
		if ( '' !== $footer_line ) {
			$lines[] = '';
			$lines[] = $footer_line;
		}
	}

	return implode( "\n", $lines );
}

/**
 * Quadro de campos pendentes: barra de resumo preta e linhas agrupadas.
 *
 * E a `ResultFrame` do painel traduzida para tabela — barra com a contagem,
 * rotulo de secao sobre kraft e uma linha por campo — porque o vendor precisa
 * ler isto como roteiro de preenchimento, nao como texto corrido.
 *
 * @param array<int, array{label:string, fields:array<int,string>}> $groups Grupos ja rotulados.
 * @param int                                                       $count  Total de campos pendentes.
 * @return string
 */
function papelito_vendor_pending_fields_plate( array $groups, int $count ): string {
	$rows = sprintf(
		'<tr>
			<td style="background-color:%1$s;padding:13px 20px;">
				<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;border-collapse:collapse;">
					<tr>
						<td align="left" style="font-family:%2$s;font-size:11px;font-weight:900;line-height:16px;letter-spacing:0.18em;text-transform:uppercase;color:%3$s;">Campos pendentes</td>
						<td align="right" style="font-family:%2$s;font-size:15px;font-weight:900;line-height:16px;color:%3$s;">%4$d</td>
					</tr>
				</table>
			</td>
		</tr>',
		esc_attr( PAPELITO_EMAIL_INK ),
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_YELLOW ),
		$count
	);

	foreach ( $groups as $group ) {
		$rows .= sprintf(
			'<tr>
				<td style="background-color:%1$s;padding:9px 20px;font-family:%2$s;font-size:10px;font-weight:900;line-height:15px;letter-spacing:0.18em;text-transform:uppercase;color:%3$s;">%4$s</td>
			</tr>',
			esc_attr( PAPELITO_EMAIL_KRAFT ),
			PAPELITO_EMAIL_FONT_STACK,
			esc_attr( PAPELITO_EMAIL_INK ),
			esc_html( (string) ( $group['label'] ?? '' ) )
		);

		$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? array_values( $group['fields'] ) : array();

		foreach ( $fields as $index => $field ) {
			$rows .= sprintf(
				'<tr>
					<td style="%1$spadding:11px 20px;font-family:%2$s;font-size:14px;font-weight:500;line-height:20px;color:%3$s;">%4$s</td>
				</tr>',
				$index > 0 ? sprintf( 'border-top:2px solid %s;', esc_attr( PAPELITO_EMAIL_RULE ) ) : '',
				PAPELITO_EMAIL_FONT_STACK,
				esc_attr( PAPELITO_EMAIL_INK ),
				esc_html( (string) $field )
			);
		}
	}

	return papelito_email_plate(
		sprintf(
			'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;border-collapse:collapse;">%s</table>',
			$rows
		),
		array(
			'background' => PAPELITO_EMAIL_PAPER,
			'padding'    => '0',
			'offset'     => 6,
		)
	);
}

/**
 * Corpo HTML do aviso de cadastro de vendor incompleto.
 *
 * @param array<string,mixed> $view greeting, link, groups, pending_count.
 * @return string
 */
function papelito_vendor_pending_registration_email_html( array $view ): string {
	$greeting = trim( (string) ( $view['greeting'] ?? '' ) );
	$link     = (string) ( $view['link'] ?? '' );
	$count    = max( 0, (int) ( $view['pending_count'] ?? 0 ) );
	$groups   = isset( $view['groups'] ) && is_array( $view['groups'] ) ? $view['groups'] : array();

	$headline = 1 === $count
		? 'Falta 1 campo para sua loja vender.'
		: sprintf( 'Faltam %d campos para sua loja vender.', $count );

	$context = 'cadastro de vendor foi criado pelo time Papelito. Enquanto estes dados não chegarem, sua loja fica fora do catálogo e não recebe pedidos.';
	$lead    = '' !== $greeting
		? sprintf( '<strong style="font-weight:900;">%s</strong>, seu %s', esc_html( $greeting ), $context )
		: sprintf( 'Seu %s', $context );

	$body  = papelito_email_headline( $headline, $lead );
	$body .= '<div style="height:24px;line-height:24px;font-size:0;">&nbsp;</div>';
	$body .= papelito_vendor_pending_fields_plate( $groups, $count );
	$body .= '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>';
	$body .= papelito_email_button( $link, 'Completar cadastro' );
	$body .= sprintf(
		'<p style="margin:20px 0 0;font-family:%1$s;font-size:13px;font-weight:500;line-height:20px;color:%2$s;">Assim que o último campo for salvo, a Papelito envia seus dados para a Pagar.me e sua loja volta ao catálogo automaticamente.</p>',
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_TEXT_SOFT )
	);

	return papelito_email_shell(
		array(
			'kicker'       => 'Cadastro de vendor',
			'preheader'    => 1 === $count
				? 'Falta 1 campo para liberar sua loja no marketplace.'
				: sprintf( 'Faltam %d campos para liberar sua loja no marketplace.', $count ),
			'body_html'    => $body,
			'footer_lines' => array( 'Você recebeu este aviso porque sua loja ainda não pode receber pedidos.' ),
		)
	);
}

/**
 * Corpo texto do aviso de cadastro de vendor incompleto.
 *
 * @param array<string,mixed> $view greeting, link, groups, pending_count.
 * @return string
 */
function papelito_vendor_pending_registration_email_text( array $view ): string {
	$greeting = trim( (string) ( $view['greeting'] ?? '' ) );
	$count    = max( 0, (int) ( $view['pending_count'] ?? 0 ) );
	$groups   = isset( $view['groups'] ) && is_array( $view['groups'] ) ? $view['groups'] : array();

	$lines = array(
		'' !== $greeting ? sprintf( 'Olá %s,', $greeting ) : 'Olá,',
		'',
		1 === $count
			? 'Falta 1 campo para sua loja vender.'
			: sprintf( 'Faltam %d campos para sua loja vender.', $count ),
		'Seu cadastro de vendor foi criado pelo time Papelito. Enquanto estes dados não chegarem, sua loja fica fora do catálogo e não recebe pedidos.',
		'',
		'CAMPOS PENDENTES',
	);

	foreach ( $groups as $group ) {
		$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();

		if ( empty( $fields ) ) {
			continue;
		}

		$lines[] = '';
		$lines[] = mb_strtoupper( (string) ( $group['label'] ?? '' ) );

		foreach ( $fields as $field ) {
			$lines[] = '- ' . $field;
		}
	}

	$lines[] = '';
	$lines[] = 'Completar cadastro: ' . (string) ( $view['link'] ?? '' );
	$lines[] = '';
	$lines[] = 'Assim que o último campo for salvo, a Papelito envia seus dados para a Pagar.me e sua loja volta ao catálogo automaticamente.';

	return implode( "\n", $lines );
}

/**
 * Corpo texto do convite de empresa.
 *
 * @param array<string,string> $view company_name, inviter_name, link, expires_at.
 * @return string
 */
function papelito_company_invitation_email_text( array $view ): string {
	$company = trim( (string) ( $view['company_name'] ?? '' ) );
	$inviter = trim( (string) ( $view['inviter_name'] ?? '' ) );
	$lines   = array(
		'Você foi convidado para fazer parte de ' . $company . ' no Papelito.',
		'' !== $inviter ? sprintf( 'Quem convidou: %s.', $inviter ) : '',
		'',
		'Ao continuar, você cria sua conta e aceita receber acesso à empresa com o papel definido no convite.',
	);
	if ( '' !== trim( (string) ( $view['expires_at'] ?? '' ) ) ) {
		$lines[] = 'Este convite é válido até ' . trim( (string) $view['expires_at'] ) . '.';
	}
	$lines[] = '';
	$lines[] = 'Aceitar convite: ' . (string) ( $view['link'] ?? '' );
	$lines[] = '';
	$lines[] = 'Se você não esperava este convite, ignore este e-mail.';

	return implode( "\n", $lines );
}

/**
 * Envia o e-mail em multipart: HTML para quem renderiza, texto para o resto.
 *
 * @param string               $recipient   Destinatario ja validado.
 * @param string               $subject     Assunto.
 * @param string               $html        Corpo HTML.
 * @param string               $text        Corpo alternativo em texto simples.
 * @param array<string,string> $attachments Anexos no formato aceito por wp_mail().
 * @return bool
 */
function papelito_email_send( string $recipient, string $subject, string $html, string $text, array $attachments = array() ): bool {
	$attach_alt_body = static function ( $phpmailer ) use ( $text ) {
		if ( is_object( $phpmailer ) ) {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	};

	add_action( 'phpmailer_init', $attach_alt_body );
	$sent = wp_mail( $recipient, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ), $attachments );
	remove_action( 'phpmailer_init', $attach_alt_body );

	return (bool) $sent;
}

/**
 * Bloco de contexto da oferta — so aparece quando acrescenta informacao.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_offer_note( array $view ): string {
	$note = papelito_favorite_promo_offer_sentence( $view );

	if ( '' === $note ) {
		return '';
	}

	return sprintf(
		'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;margin-top:20px;">
			<tr>
				<td width="10" style="width:10px;background-color:%1$s;font-size:0;line-height:0;">&nbsp;</td>
				<td style="background-color:%2$s;border:2px solid %3$s;border-left:0;padding:12px 16px;font-family:%4$s;font-size:13px;font-weight:500;line-height:20px;color:%3$s;">%5$s</td>
			</tr>
		</table>',
		esc_attr( PAPELITO_EMAIL_YELLOW ),
		esc_attr( PAPELITO_EMAIL_PAPER ),
		esc_attr( PAPELITO_EMAIL_INK ),
		PAPELITO_EMAIL_FONT_STACK,
		$note
	);
}

/**
 * Frase de contexto da oferta, em HTML, conforme a origem da promocao.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_offer_sentence( array $view ): string {
	$promo_type  = (string) ( $view['promo_type'] ?? '' );
	$promo_label = trim( (string) ( $view['promo_label'] ?? '' ) );

	if ( '' === $promo_label ) {
		return '';
	}

	if ( 'coupon' === $promo_type ) {
		return sprintf(
			'Use o cupom <strong style="font-weight:900;letter-spacing:0.06em;">%s</strong> no carrinho para garantir o desconto.',
			esc_html( $promo_label )
		);
	}

	if ( 'flash_sale' === $promo_type ) {
		return sprintf(
			'Oferta Relâmpago: <strong style="font-weight:900;">%s</strong>. Vale enquanto a campanha estiver no ar.',
			esc_html( $promo_label )
		);
	}

	return '';
}

/**
 * Mesma frase de contexto, em texto simples.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_offer_text( array $view ): string {
	$promo_type  = (string) ( $view['promo_type'] ?? '' );
	$promo_label = trim( (string) ( $view['promo_label'] ?? '' ) );

	if ( '' === $promo_label ) {
		return '';
	}

	if ( 'coupon' === $promo_type ) {
		return sprintf( 'Use o cupom %s no carrinho para garantir o desconto.', $promo_label );
	}

	if ( 'flash_sale' === $promo_type ) {
		return sprintf( 'Oferta Relâmpago: %s. Vale enquanto a campanha estiver no ar.', $promo_label );
	}

	return '';
}

/**
 * Chapa branca do produto: imagem, categoria, nome e preco anterior riscado.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_product_plate( array $view ): string {
	$name          = (string) ( $view['product_name'] ?? '' );
	$category      = (string) ( $view['category'] ?? '' );
	$image_url     = (string) ( $view['image_url'] ?? '' );
	$regular_price = (string) ( $view['regular_price_label'] ?? '' );

	$details  = '' !== $category ? papelito_email_label( $category ) . '<br />' : '';
	$details .= sprintf(
		'<span style="font-family:%s;font-size:22px;font-weight:900;line-height:27px;letter-spacing:-0.015em;color:%s;">%s</span>',
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_INK ),
		esc_html( $name )
	);

	if ( '' !== $regular_price ) {
		$details .= sprintf(
			'<br /><span style="font-family:%s;font-size:14px;font-weight:500;line-height:22px;color:%s;text-decoration:line-through;">De %s</span>',
			PAPELITO_EMAIL_FONT_STACK,
			esc_attr( PAPELITO_EMAIL_TEXT_LABEL ),
			esc_html( $regular_price )
		);
	}

	if ( '' === $image_url ) {
		return papelito_email_plate(
			$details,
			array(
				'background' => PAPELITO_EMAIL_PAPER,
				'padding'    => PAPELITO_EMAIL_PLATE_PADDING,
			)
		);
	}

	$columns = sprintf(
		'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;">
			<tr>
				<td class="papelito-stack papelito-stack-media" width="190" valign="middle" style="width:190px;padding:0 20px 0 0;">
					<img class="papelito-media" src="%1$s" width="190" alt="%2$s" style="display:block;width:190px;max-width:100%%;height:auto;border:2px solid %3$s;background-color:%4$s;" />
				</td>
				<td class="papelito-stack" valign="middle" style="padding:0;">%5$s</td>
			</tr>
		</table>',
		esc_url( $image_url ),
		esc_attr( $name ),
		esc_attr( PAPELITO_EMAIL_INK ),
		esc_attr( PAPELITO_EMAIL_KRAFT ),
		$details
	);

	return papelito_email_plate(
		$columns,
		array(
			'background' => PAPELITO_EMAIL_PAPER,
			'padding'    => PAPELITO_EMAIL_PLATE_PADDING,
		)
	);
}

/**
 * Chapa amarela de remarcacao, colada por cima e deslocada a direita.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_price_plate( array $view ): string {
	$sale_price = (string) ( $view['sale_price_label'] ?? '' );
	$discount   = absint( $view['discount_percent'] ?? 0 );

	if ( '' === $sale_price ) {
		return '';
	}

	$badge = $discount > 0
		? sprintf(
			'<td align="right" valign="bottom" style="padding:0 0 6px 12px;"><span style="display:inline-block;padding:7px 11px;background-color:%1$s;font-family:%2$s;font-size:12px;font-weight:900;line-height:14px;letter-spacing:0.12em;color:%3$s;">%4$d%% OFF</span></td>',
			esc_attr( PAPELITO_EMAIL_INK ),
			PAPELITO_EMAIL_FONT_STACK,
			esc_attr( PAPELITO_EMAIL_YELLOW ),
			$discount
		)
		: '';

	$inner = sprintf(
		'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;">
			<tr>
				<td valign="bottom" style="padding:0;">
					%1$s<br />
					<span class="papelito-price" style="font-family:%2$s;font-size:40px;font-weight:900;line-height:44px;letter-spacing:-0.03em;color:%3$s;">%4$s</span>
				</td>
				%5$s
			</tr>
		</table>',
		papelito_email_label( 'Agora', PAPELITO_EMAIL_INK ),
		PAPELITO_EMAIL_FONT_STACK,
		esc_attr( PAPELITO_EMAIL_INK ),
		esc_html( $sale_price ),
		$badge
	);

	return sprintf(
		'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;">
			<tr>
				<td width="72" class="papelito-offset-spacer" style="width:72px;font-size:0;line-height:0;">&nbsp;</td>
				<td class="papelito-offset" style="padding:0;">%s</td>
			</tr>
		</table>',
		papelito_email_plate(
			$inner,
			array(
				'background' => PAPELITO_EMAIL_YELLOW,
				'padding'    => PAPELITO_EMAIL_ACCENT_PADDING,
			)
		)
	);
}

/**
 * Manchete e abertura, no limite do que o evento realmente informa.
 *
 * Sem preco promocional — o caso do cupom — nao ha queda de preco a afirmar,
 * so a entrada em promocao.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return array{headline:string,lead:string}
 */
function papelito_favorite_promo_copy( array $view ): array {
	$has_sale_price = '' !== (string) ( $view['sale_price_label'] ?? '' );

	return $has_sale_price
		? array(
			'headline' => 'O preço do seu favorito caiu.',
			'lead'     => 'Você marcou este produto nos favoritos e ele acabou de ficar mais barato.',
		)
		: array(
			'headline' => 'Seu favorito entrou em promoção.',
			'lead'     => 'Você marcou este produto nos favoritos e ele está com condição especial agora.',
		);
}

/**
 * Corpo HTML do aviso de favorito em promocao.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_email_html( array $view ): string {
	$greeting = (string) ( $view['greeting'] ?? '' );
	$link     = (string) ( $view['product_url'] ?? '' );
	$copy     = papelito_favorite_promo_copy( $view );

	$lead = sprintf(
		'Olá, %s. %s',
		esc_html( $greeting ),
		esc_html( $copy['lead'] )
	);

	$body  = papelito_email_headline( $copy['headline'], $lead );
	$body .= '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>';
	$body .= papelito_favorite_promo_product_plate( $view );
	$body .= papelito_favorite_promo_price_plate( $view );
	$body .= papelito_favorite_promo_offer_note( $view );
	$body .= '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>';
	$body .= papelito_email_button( $link, 'Ver produto' );

	return papelito_email_shell(
		array(
			'kicker'       => 'Favoritos',
			'preheader'    => papelito_favorite_promo_preheader( $view ),
			'body_html'    => $body,
			'footer_lines' => array(
				sprintf(
					'Você recebe este aviso porque ativou o alerta de promoção dos favoritos. <a href="%s" style="color:%s;font-weight:900;text-decoration:underline;">Desativar em Configurações</a>.',
					esc_url( (string) ( $view['settings_url'] ?? '' ) ),
					esc_attr( PAPELITO_EMAIL_YELLOW )
				),
			),
		)
	);
}

/**
 * Linha de previa da caixa de entrada.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_preheader( array $view ): string {
	$name     = (string) ( $view['product_name'] ?? '' );
	$sale     = (string) ( $view['sale_price_label'] ?? '' );
	$regular  = (string) ( $view['regular_price_label'] ?? '' );
	$discount = absint( $view['discount_percent'] ?? 0 );

	if ( '' !== $sale && '' !== $regular ) {
		return $discount > 0
			? sprintf( '%s: de %s por %s, %d%% OFF.', $name, $regular, $sale, $discount )
			: sprintf( '%s: de %s por %s.', $name, $regular, $sale );
	}

	if ( '' !== $sale ) {
		return sprintf( '%s por %s.', $name, $sale );
	}

	return sprintf( '%s entrou em promoção.', $name );
}

/**
 * Corpo alternativo em texto simples do aviso de favorito em promocao.
 *
 * @param array<string,mixed> $view Visao do e-mail de promocao.
 * @return string
 */
function papelito_favorite_promo_email_text( array $view ): string {
	$name     = (string) ( $view['product_name'] ?? '' );
	$category = (string) ( $view['category'] ?? '' );
	$regular  = (string) ( $view['regular_price_label'] ?? '' );
	$sale     = (string) ( $view['sale_price_label'] ?? '' );
	$discount = absint( $view['discount_percent'] ?? 0 );

	$copy  = papelito_favorite_promo_copy( $view );
	$lines = array(
		sprintf( 'Olá, %s.', (string) ( $view['greeting'] ?? '' ) ),
		'',
		$copy['headline'],
		'',
		'' !== $category ? sprintf( '%s (%s)', $name, $category ) : $name,
	);

	if ( '' !== $regular && '' !== $sale ) {
		$lines[] = sprintf( 'De %s por %s.', $regular, $sale );
	} elseif ( '' !== $sale ) {
		$lines[] = sprintf( 'Agora por %s.', $sale );
	}

	if ( $discount > 0 ) {
		$lines[] = sprintf( 'Desconto de %d%%.', $discount );
	}

	$offer = papelito_favorite_promo_offer_text( $view );
	if ( '' !== $offer ) {
		$lines[] = $offer;
	}

	$lines[] = '';
	$lines[] = 'Ver produto: ' . (string) ( $view['product_url'] ?? '' );
	$lines[] = '';
	$lines[] = 'Você recebe este aviso porque ativou o alerta de promoção dos favoritos.';
	$lines[] = 'Para desativar: ' . (string) ( $view['settings_url'] ?? '' );
	$lines[] = '';
	$lines[] = 'Time Papelito';

	return implode( PHP_EOL, $lines );
}

/**
 * Corpo HTML do aviso de nova compra enviado ao vendor.
 *
 * @param array<string,mixed> $view Visao do e-mail de nova compra.
 * @return string
 */
function papelito_new_purchase_email_html( array $view ): string {
	$order_number = (string) ( $view['order_number'] ?? '' );
	$total        = (string) ( $view['total_label'] ?? '' );
	$created_at   = (string) ( $view['created_at'] ?? '' );
	$items_label  = (string) ( $view['items_label'] ?? '' );
	$customer     = (string) ( $view['customer_name'] ?? '' );

	$lead = sprintf(
		'Olá, %s. Um pedido acabou de ser pago na sua loja. Separe os itens e prepare o envio.',
		esc_html( (string) ( $view['greeting'] ?? '' ) )
	);

	$rows = array(
		array( 'Pedido', '#' . $order_number ),
		array( 'Data', $created_at ),
		array( 'Comprador', $customer ),
		array( 'Itens', $items_label ),
	);

	$detail_html = '';
	foreach ( $rows as $row ) {
		if ( '' === trim( (string) $row[1] ) ) {
			continue;
		}

		$detail_html .= sprintf(
			'<tr>
				<td style="padding:0 0 10px;">%1$s</td>
				<td align="right" style="padding:0 0 10px;font-family:%2$s;font-size:14px;font-weight:500;line-height:18px;color:%3$s;">%4$s</td>
			</tr>',
			papelito_email_label( (string) $row[0] ),
			PAPELITO_EMAIL_FONT_STACK,
			esc_attr( PAPELITO_EMAIL_INK ),
			esc_html( (string) $row[1] )
		);
	}

	$plate = papelito_email_plate(
		sprintf(
			'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;">%s</table>',
			$detail_html
		),
		array(
			'background' => PAPELITO_EMAIL_PAPER,
			'padding'    => PAPELITO_EMAIL_PLATE_PADDING,
		)
	);

	$total_plate = '' === $total ? '' : sprintf(
		'<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%%" style="width:100%%;">
			<tr>
				<td width="72" style="width:72px;font-size:0;line-height:0;">&nbsp;</td>
				<td class="papelito-offset" style="padding:0;">%s</td>
			</tr>
		</table>',
		papelito_email_plate(
			sprintf(
				'%1$s<br /><span class="papelito-price" style="font-family:%2$s;font-size:40px;font-weight:900;line-height:44px;letter-spacing:-0.03em;color:%3$s;">%4$s</span>',
				papelito_email_label( 'Total do pedido', PAPELITO_EMAIL_INK ),
				PAPELITO_EMAIL_FONT_STACK,
				esc_attr( PAPELITO_EMAIL_INK ),
				esc_html( $total )
			),
			array(
				'background' => PAPELITO_EMAIL_YELLOW,
				'padding'    => PAPELITO_EMAIL_ACCENT_PADDING,
			)
		)
	);

	$body  = papelito_email_headline( 'Você recebeu uma nova compra.', $lead );
	$body .= '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>';
	$body .= $plate;
	$body .= $total_plate;
	$body .= '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>';
	$body .= papelito_email_button( (string) ( $view['order_url'] ?? '' ), 'Ver pedido' );

	return papelito_email_shell(
		array(
			'kicker'       => 'Vendor',
			'preheader'    => '' !== $total
				? sprintf( 'Pedido #%s, %s.', $order_number, $total )
				: sprintf( 'Pedido #%s.', $order_number ),
			'body_html'    => $body,
			'footer_lines' => array(
				'Você recebe este aviso porque é o vendor responsável por este pedido.',
			),
		)
	);
}

/**
 * Corpo alternativo em texto simples do aviso de nova compra.
 *
 * @param array<string,mixed> $view Visao do e-mail de nova compra.
 * @return string
 */
function papelito_new_purchase_email_text( array $view ): string {
	$lines = array(
		sprintf( 'Olá, %s.', (string) ( $view['greeting'] ?? '' ) ),
		'',
		'Você recebeu uma nova compra na Papelito.',
		'',
		sprintf( 'Pedido: #%s', (string) ( $view['order_number'] ?? '' ) ),
	);

	foreach ( array(
		'Data'      => (string) ( $view['created_at'] ?? '' ),
		'Comprador' => (string) ( $view['customer_name'] ?? '' ),
		'Itens'     => (string) ( $view['items_label'] ?? '' ),
		'Total'     => (string) ( $view['total_label'] ?? '' ),
	) as $label => $value ) {
		if ( '' !== trim( $value ) ) {
			$lines[] = sprintf( '%s: %s', $label, $value );
		}
	}

	$lines[] = '';
	$lines[] = 'Separe o pedido e prepare o envio. Acesse o detalhe abaixo:';
	$lines[] = (string) ( $view['order_url'] ?? '' );
	$lines[] = '';
	$lines[] = defined( 'PAPELITO_NOTIFICATION_SIGNATURE' ) ? PAPELITO_NOTIFICATION_SIGNATURE : 'Time Papelito';

	return implode( PHP_EOL, $lines );
}
