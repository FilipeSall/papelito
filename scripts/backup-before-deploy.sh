#!/usr/bin/env bash

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command ssh

DEPLOY_KIND="${1:-}"
DEPLOY_SLUG="${2:-}"

if [[ -z "$DEPLOY_KIND" || -z "$DEPLOY_SLUG" ]]; then
  echo "Uso: $0 <theme|plugin> <slug>" >&2
  exit 1
fi

papelito_require_env REMOTE_HOST REMOTE_PORT REMOTE_USER REMOTE_BACKUP_DIR

case "$DEPLOY_KIND" in
  theme)
    papelito_require_env REMOTE_THEMES_DIR
    REMOTE_SOURCE_DIR="$REMOTE_THEMES_DIR/$DEPLOY_SLUG"
    ;;
  plugin)
    papelito_require_env REMOTE_PLUGINS_DIR
    REMOTE_SOURCE_DIR="$REMOTE_PLUGINS_DIR/$DEPLOY_SLUG"
    ;;
  *)
    echo "Tipo de deploy inválido: $DEPLOY_KIND" >&2
    exit 1
    ;;
esac

TIMESTAMP="$(papelito_timestamp)"
REMOTE_ARCHIVE="$REMOTE_BACKUP_DIR/${DEPLOY_SLUG}-${TIMESTAMP}.tar.gz"

papelito_info "Criando backup remoto de $REMOTE_SOURCE_DIR"
ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "\
  set -euo pipefail; \
  mkdir -p '$REMOTE_BACKUP_DIR'; \
  if [ -d '$REMOTE_SOURCE_DIR' ]; then \
    tar -czf '$REMOTE_ARCHIVE' -C '$(dirname "$REMOTE_SOURCE_DIR")' '$(basename "$REMOTE_SOURCE_DIR")'; \
    echo 'Backup de arquivos criado em $REMOTE_ARCHIVE'; \
  else \
    echo 'Diretório remoto não encontrado, backup de arquivos ignorado.'; \
  fi"

if [[ "${BACKUP_DATABASE:-true}" == "true" && -n "${REMOTE_WP_PATH:-}" ]]; then
  papelito_info "Tentando exportar banco via WP-CLI remoto"
  ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "\
    set -euo pipefail; \
    if command -v wp >/dev/null 2>&1; then \
      mkdir -p '$REMOTE_BACKUP_DIR'; \
      cd '$REMOTE_WP_PATH'; \
      wp db export '$REMOTE_BACKUP_DIR/db-${TIMESTAMP}.sql' --allow-root; \
      echo 'Backup do banco criado em $REMOTE_BACKUP_DIR/db-${TIMESTAMP}.sql'; \
    else \
      echo 'WP-CLI não encontrado no remoto; backup de banco ignorado.'; \
    fi"
fi
