#!/usr/bin/env bash

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command rsync
papelito_require_command ssh

THEME_SLUG="${1:-${DEPLOY_THEME_SLUG:-jupiterx-child}}"
SOURCE_DIR="$PAPELITO_PROJECT_ROOT/public_html/wp-content/themes/$THEME_SLUG/"

papelito_require_env REMOTE_HOST REMOTE_PORT REMOTE_USER REMOTE_THEMES_DIR

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Tema não encontrado: $SOURCE_DIR" >&2
  exit 1
fi

if [[ "${RUN_REMOTE_BACKUP:-true}" == "true" ]]; then
  "$PAPELITO_PROJECT_ROOT/scripts/backup-before-deploy.sh" theme "$THEME_SLUG"
fi

papelito_info "Sincronizando tema $THEME_SLUG"
ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_THEMES_DIR/$THEME_SLUG'"
rsync -az --delete \
  --exclude='.git/' \
  --exclude='.DS_Store' \
  -e "ssh -p $REMOTE_PORT" \
  "$SOURCE_DIR" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_THEMES_DIR/$THEME_SLUG/"

if [[ "${REMOTE_FLUSH_CACHE:-true}" == "true" && -n "${REMOTE_WP_PATH:-}" ]]; then
  papelito_info "Executando flush remoto via WP-CLI, se disponível"
  ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "\
    set -euo pipefail; \
    if command -v wp >/dev/null 2>&1; then \
      cd '$REMOTE_WP_PATH'; \
      wp cache flush --allow-root || true; \
      wp rewrite flush --hard --allow-root || true; \
    fi"
fi

papelito_info "Deploy do tema finalizado"
