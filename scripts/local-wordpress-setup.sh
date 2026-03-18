#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -f "$PROJECT_ROOT/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$PROJECT_ROOT/.env"
  set +a
fi
if [[ -f "$PROJECT_ROOT/.env.local" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$PROJECT_ROOT/.env.local"
  set +a
fi
SQL_DUMP="${1:-$PROJECT_ROOT/db/u374715300_rhozU.sql}"
LOCAL_URL="${LOCAL_URL:-http://localhost:8080}"
LOCAL_HOST="${LOCAL_URL#*://}"
LOCAL_URL_ENCODED="${LOCAL_URL//:/%3A}"
LOCAL_URL_ENCODED="${LOCAL_URL_ENCODED//\//%2F}"
DB_CONTAINER="${DB_CONTAINER:-papelito-db}"
WEB_CONTAINER="${WEB_CONTAINER:-papelito-web}"
DB_NAME="${DB_NAME:-papelito_local}"
DB_USER="${DB_USER:-papelito}"
DB_PASSWORD="${DB_PASSWORD:-papelito_local_123}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-root_local_123}"
WP_CONTENT_DIR="$PROJECT_ROOT/public_html/wp-content"

wp_local() {
  docker exec \
    -e HTTP_HOST="$LOCAL_HOST" \
    -u www-data \
    "$WEB_CONTAINER" \
    wp "$@" --allow-root --url="$LOCAL_URL" --quiet --skip-plugins --skip-themes
}

replace_url() {
  local from="$1"
  local to="$2"

  wp_local search-replace "$from" "$to" --all-tables --precise --skip-columns=guid
}

chmod -R a+rwX "$WP_CONTENT_DIR/uploads" >/dev/null 2>&1 || true
chmod a+rw "$WP_CONTENT_DIR/debug.log" >/dev/null 2>&1 || true

if [[ ! -f "$SQL_DUMP" ]]; then
  echo "SQL dump not found: $SQL_DUMP" >&2
  exit 1
fi

echo "Waiting for database..."
until docker exec "$DB_CONTAINER" mariadb-admin ping -h 127.0.0.1 -uroot "-p$DB_ROOT_PASSWORD" --silent >/dev/null 2>&1; do
  sleep 2
done

TABLE_COUNT="$(docker exec "$DB_CONTAINER" mariadb -N -u"$DB_USER" "-p$DB_PASSWORD" "$DB_NAME" -e "SHOW TABLES;" 2>/dev/null | wc -l | tr -d ' ')"

if [[ "$TABLE_COUNT" == "0" ]]; then
  echo "Importing SQL dump into $DB_NAME..."
  docker exec -i "$DB_CONTAINER" mariadb -u"$DB_USER" "-p$DB_PASSWORD" "$DB_NAME" < "$SQL_DUMP"
else
  echo "Database already contains tables; skipping import."
fi

echo "Running search-replace for local domain..."
replace_url '//papelitobrasil.com.br' "//$LOCAL_HOST"
replace_url '//www.papelitobrasil.com.br' "//$LOCAL_HOST"
replace_url '//papelitobrasil.com' "//$LOCAL_HOST"
replace_url '//www.papelitobrasil.com' "//$LOCAL_HOST"
replace_url '\\/\\/papelitobrasil.com.br' "\\/\\/$LOCAL_HOST"
replace_url '\\/\\/www.papelitobrasil.com.br' "\\/\\/$LOCAL_HOST"
replace_url '\\/\\/papelitobrasil.com' "\\/\\/$LOCAL_HOST"
replace_url '\\/\\/www.papelitobrasil.com' "\\/\\/$LOCAL_HOST"
replace_url 'https%3A%2F%2Fpapelitobrasil.com.br' "$LOCAL_URL_ENCODED"
replace_url 'http%3A%2F%2Fpapelitobrasil.com.br' "$LOCAL_URL_ENCODED"
replace_url 'https%3A%2F%2Fwww.papelitobrasil.com.br' "$LOCAL_URL_ENCODED"
replace_url 'http%3A%2F%2Fwww.papelitobrasil.com.br' "$LOCAL_URL_ENCODED"
replace_url 'http://papelitobrasil.local:8080' "$LOCAL_URL"
replace_url 'http://localhost:8080' "$LOCAL_URL"
wp_local option update home "$LOCAL_URL"
wp_local option update siteurl "$LOCAL_URL"

echo "Disabling page-load popups for local browsing..."
wp_local eval 'foreach ( get_posts( [
	"post_type" => "elementor_library",
	"posts_per_page" => -1,
	"fields" => "ids",
	"meta_query" => [
		[
			"key" => "_elementor_popup_display_settings",
			"compare" => "EXISTS",
		],
	],
] ) as $popup_id ) {
	$settings = get_post_meta( $popup_id, "_elementor_popup_display_settings", true );
	if ( ! is_array( $settings ) || empty( $settings["triggers"]["page_load"] ) ) {
		continue;
	}
	unset( $settings["triggers"]["page_load"], $settings["triggers"]["page_load_delay"] );
	update_post_meta( $popup_id, "_elementor_popup_display_settings", $settings );
}'

echo "Hardening custom JupiterX header script..."
wp_local eval '$jupiterx = get_option( "jupiterx" );
if ( ! is_array( $jupiterx ) || empty( $jupiterx["tracking_codes_after_head"] ) ) {
	return;
}
$pattern = "/var mudarTexto = document\\.querySelector\\(\\\"\\\\.woocommerce-shipping-totals th\\\\\"\\);\\/\\/aqui a classe de onde tem o texto que está em inglês\\s+mudarTexto\\.innerText = \\\"Envio\\\"; \\/\\/aqui pegamos o texto da classe que selecionamos acima e convertemos para outro texto/s";
$replace = "var mudarTexto = document.querySelector(\\\".woocommerce-shipping-totals th\\\");//aqui a classe de onde tem o texto que está em inglês\n\nif (mudarTexto) {\n  mudarTexto.innerText = \\\"Envio\\\"; //aqui pegamos o texto da classe que selecionamos acima e convertemos para outro texto\n}";
$updated = preg_replace( $pattern, $replace, $jupiterx["tracking_codes_after_head"], 1 );
if ( ! is_string( $updated ) || $updated === $jupiterx["tracking_codes_after_head"] ) {
	return;
}
$jupiterx["tracking_codes_after_head"] = $updated;
update_option( "jupiterx", $jupiterx, false );'

echo "Flushing rewrites and cache..."
wp_local rewrite flush --hard || true
wp_local cache flush || true

echo "Done. Open $LOCAL_URL and /wp-admin."
