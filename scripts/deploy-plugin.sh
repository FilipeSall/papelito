#!/usr/bin/env bash

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command rsync
papelito_require_command ssh

PLUGIN_SLUG="${1:-${DEPLOY_PLUGIN_SLUG:-plugin_papelito}}"
SOURCE_DIR="$PAPELITO_PROJECT_ROOT/public_html/wp-content/plugins/$PLUGIN_SLUG/"

papelito_require_env REMOTE_HOST REMOTE_PORT REMOTE_USER REMOTE_PLUGINS_DIR

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Plugin não encontrado: $SOURCE_DIR" >&2
  exit 1
fi

if [[ "${RUN_REMOTE_BACKUP:-true}" == "true" ]]; then
  "$PAPELITO_PROJECT_ROOT/scripts/backup-before-deploy.sh" plugin "$PLUGIN_SLUG"
fi

papelito_info "Sincronizando plugin $PLUGIN_SLUG"
ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_PLUGINS_DIR/$PLUGIN_SLUG'"
rsync -az --delete \
  --exclude='my_plugin_log.txt' \
  --exclude='.DS_Store' \
  -e "ssh -p $REMOTE_PORT" \
  "$SOURCE_DIR" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PLUGINS_DIR/$PLUGIN_SLUG/"

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

papelito_info "Deploy do plugin finalizado"
