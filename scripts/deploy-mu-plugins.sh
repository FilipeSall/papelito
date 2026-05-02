#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command rsync
papelito_require_command ssh
papelito_require_env REMOTE_HOST REMOTE_PORT REMOTE_USER REMOTE_MU_PLUGINS_DIR

SOURCE_DIR="$PAPELITO_PROJECT_ROOT/public_html/wp-content/mu-plugins/"

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "mu-plugins não encontrado: $SOURCE_DIR" >&2
  exit 1
fi

if [[ "${RUN_REMOTE_BACKUP:-true}" == "true" ]]; then
  "$PAPELITO_PROJECT_ROOT/scripts/backup-before-deploy.sh" mu-plugins all
fi

papelito_info "Sincronizando mu-plugins"
ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_MU_PLUGINS_DIR'"
rsync -az \
  --include='papelito-*.php' \
  --include='README.md' \
  --exclude='*' \
  -e "ssh -p $REMOTE_PORT" \
  "$SOURCE_DIR" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_MU_PLUGINS_DIR/"

papelito_info "Deploy de mu-plugins finalizado"
