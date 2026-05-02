#!/usr/bin/env bash

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command ssh

DEPLOY_KIND="${1:-}"
DEPLOY_SLUG="${2:-}"

if [[ -z "$DEPLOY_KIND" || -z "$DEPLOY_SLUG" ]]; then
  echo "Uso: $0 <theme|plugin|mu-plugins|htaccess> <slug>" >&2
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
  mu-plugins)
    papelito_require_env REMOTE_MU_PLUGINS_DIR
    REMOTE_SOURCE_DIR="$REMOTE_MU_PLUGINS_DIR"
    ;;
  htaccess)
    papelito_require_env REMOTE_WP_PATH
    REMOTE_SOURCE_DIR="$REMOTE_WP_PATH"
    ;;
  *)
    echo "Tipo de deploy inválido: $DEPLOY_KIND" >&2
    exit 1
    ;;
esac

TIMESTAMP="$(papelito_timestamp)"
REMOTE_ARCHIVE="$REMOTE_BACKUP_DIR/${DEPLOY_SLUG}-${TIMESTAMP}.tar.gz"

if [[ "$DEPLOY_KIND" == "htaccess" ]]; then
  papelito_info "Criando backup remoto dos arquivos .htaccess"
  ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "\
    set -euo pipefail; \
    mkdir -p '$REMOTE_BACKUP_DIR' '$REMOTE_BACKUP_DIR/.tmp-htaccess-$TIMESTAMP/wp-content/uploads'; \
    if [ -f '$REMOTE_WP_PATH/.htaccess' ]; then cp '$REMOTE_WP_PATH/.htaccess' '$REMOTE_BACKUP_DIR/.tmp-htaccess-$TIMESTAMP/.htaccess'; fi; \
    if [ -f '$REMOTE_WP_PATH/wp-content/uploads/.htaccess' ]; then cp '$REMOTE_WP_PATH/wp-content/uploads/.htaccess' '$REMOTE_BACKUP_DIR/.tmp-htaccess-$TIMESTAMP/wp-content/uploads/.htaccess'; fi; \
    if [ -f '$REMOTE_BACKUP_DIR/.tmp-htaccess-$TIMESTAMP/.htaccess' ] || [ -f '$REMOTE_BACKUP_DIR/.tmp-htaccess-$TIMESTAMP/wp-content/uploads/.htaccess' ]; then \
      tar -czf '$REMOTE_ARCHIVE' -C '$REMOTE_BACKUP_DIR/.tmp-htaccess-$TIMESTAMP' .; \
      echo 'Backup de arquivos criado em $REMOTE_ARCHIVE'; \
    else \
      echo 'Arquivos .htaccess não encontrados; backup ignorado.'; \
    fi; \
    rm -rf '$REMOTE_BACKUP_DIR/.tmp-htaccess-$TIMESTAMP'"
else
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
fi

if [[ "${BACKUP_DATABASE:-true}" == "true" && -n "${REMOTE_WP_PATH:-}" ]]; then
  papelito_info "Tentando exportar banco via WP-CLI remoto"
  ssh -p "$REMOTE_PORT" "$REMOTE_USER@$REMOTE_HOST" "\
    set -euo pipefail; \
    if ! command -v wp >/dev/null 2>&1; then \
      echo 'WP-CLI não encontrado no remoto; backup de banco ignorado.'; \
      exit 0; \
    fi; \
    mkdir -p '$REMOTE_BACKUP_DIR'; \
    cd '$REMOTE_WP_PATH'; \
    if wp db export '$REMOTE_BACKUP_DIR/db-${TIMESTAMP}.sql' --allow-root >/dev/null 2>&1; then \
      echo 'Backup do banco criado em $REMOTE_BACKUP_DIR/db-${TIMESTAMP}.sql'; \
      exit 0; \
    fi; \
    if command -v mariadb-dump >/dev/null 2>&1; then \
      DB_NAME=\"\$(wp config get DB_NAME --allow-root)\"; \
      DB_USER=\"\$(wp config get DB_USER --allow-root)\"; \
      DB_PASSWORD=\"\$(wp config get DB_PASSWORD --allow-root)\"; \
      DB_HOST=\"\$(wp config get DB_HOST --allow-root)\"; \
      mariadb-dump --single-transaction --default-character-set=utf8mb4 -h \"\$DB_HOST\" -u \"\$DB_USER\" \"-p\$DB_PASSWORD\" \"\$DB_NAME\" > '$REMOTE_BACKUP_DIR/db-${TIMESTAMP}.sql'; \
      echo 'Backup do banco criado com mariadb-dump em $REMOTE_BACKUP_DIR/db-${TIMESTAMP}.sql'; \
    else \
      echo 'WP-CLI export falhou e mariadb-dump não está disponível; backup de banco ignorado.'; \
    fi"
fi
