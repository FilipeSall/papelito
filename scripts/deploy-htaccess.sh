#!/usr/bin/env bash
set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command rsync
papelito_require_command ssh
papelito_require_env REMOTE_HOST REMOTE_PORT REMOTE_USER REMOTE_WP_PATH

if [[ "${RUN_REMOTE_BACKUP:-true}" == "true" ]]; then
  "$PAPELITO_PROJECT_ROOT/scripts/backup-before-deploy.sh" htaccess all
fi

papelito_info "Enviando .htaccess raiz"
rsync -az -e "ssh -p $REMOTE_PORT" \
  "$PAPELITO_PROJECT_ROOT/public_html/.htaccess" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/.htaccess"

papelito_info "Enviando .htaccess de uploads"
ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_WP_PATH/wp-content/uploads'"
rsync -az -e "ssh -p $REMOTE_PORT" \
  "$PAPELITO_PROJECT_ROOT/public_html/wp-content/uploads/.htaccess" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/wp-content/uploads/.htaccess"

papelito_info ".htaccess deploy finalizado"
