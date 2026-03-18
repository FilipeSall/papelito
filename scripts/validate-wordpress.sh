#!/usr/bin/env bash

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command php

TARGETS=(
  "$PAPELITO_PROJECT_ROOT/public_html/wp-content/themes/jupiterx-child"
  "$PAPELITO_PROJECT_ROOT/public_html/wp-content/plugins/plugin_papelito"
  "$PAPELITO_PROJECT_ROOT/public_html/wp-config.php"
  "$PAPELITO_PROJECT_ROOT/public_html/wp-config.example.php"
)

papelito_info "Validando sintaxe PHP"
while IFS= read -r -d '' file_path; do
  php -l "$file_path" >/dev/null
  printf 'OK %s\n' "$file_path"
done < <(find "${TARGETS[@]}" -type f -name '*.php' -print0)

if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "${WEB_CONTAINER:-papelito-web}"; then
  papelito_info "Executando checksums via WP-CLI no container"
  docker exec -u www-data "${WEB_CONTAINER:-papelito-web}" wp core verify-checksums --allow-root
  docker exec -u www-data "${WEB_CONTAINER:-papelito-web}" wp plugin verify-checksums --all --allow-root || true
else
  papelito_info "Container web não está rodando; checksums remotos foram ignorados"
fi
