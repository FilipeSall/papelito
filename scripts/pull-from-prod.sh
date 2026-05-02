#!/usr/bin/env bash
# Sincroniza arquivos versionáveis do servidor de produção para o repo local.
# NÃO faz commit. Após rodar, revise `git status` e commite manualmente.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$SCRIPT_DIR/lib/common.sh"
papelito_load_env
papelito_require_command rsync
papelito_require_command ssh

papelito_require_env REMOTE_HOST REMOTE_PORT REMOTE_USER REMOTE_WP_PATH

PULLED_DIR="$PROJECT_ROOT/_pulled"
mkdir -p "$PULLED_DIR"

papelito_info "Pull: tema jupiterx-child"
rsync -avz --delete \
  --exclude='.git/' --exclude='node_modules/' --exclude='*.log' \
  -e "ssh -p $REMOTE_PORT" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/wp-content/themes/jupiterx-child/" \
  "$PROJECT_ROOT/public_html/wp-content/themes/jupiterx-child/"

papelito_info "Pull: plugin_papelito"
rsync -avz --delete \
  --exclude='.git/' --exclude='*.log' --exclude='my_plugin_log.txt' \
  -e "ssh -p $REMOTE_PORT" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/wp-content/plugins/plugin_papelito/" \
  "$PROJECT_ROOT/public_html/wp-content/plugins/plugin_papelito/"

papelito_info "Pull: mu-plugins (vai virar versionado)"
mkdir -p "$PROJECT_ROOT/public_html/wp-content/mu-plugins"
rsync -avz \
  -e "ssh -p $REMOTE_PORT" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/wp-content/mu-plugins/" \
  "$PROJECT_ROOT/public_html/wp-content/mu-plugins/"

papelito_info "Pull: .htaccess raiz e uploads (vai virar versionado)"
rsync -avz \
  -e "ssh -p $REMOTE_PORT" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/.htaccess" \
  "$PROJECT_ROOT/public_html/.htaccess" || papelito_warn ".htaccess raiz não encontrado no servidor"

mkdir -p "$PROJECT_ROOT/public_html/wp-content/uploads"
rsync -avz \
  -e "ssh -p $REMOTE_PORT" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/wp-content/uploads/.htaccess" \
  "$PROJECT_ROOT/public_html/wp-content/uploads/.htaccess" || papelito_warn ".htaccess de uploads não encontrado"

papelito_info "Pull: wp-config.php (auditoria; NÃO commitar)"
rsync -avz \
  -e "ssh -p $REMOTE_PORT" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/wp-config.php" \
  "$PULLED_DIR/wp-config.php"

papelito_info "Pull: .user.ini (se existir)"
rsync -avz \
  -e "ssh -p $REMOTE_PORT" \
  "$REMOTE_USER@$REMOTE_HOST:$REMOTE_WP_PATH/.user.ini" \
  "$PULLED_DIR/.user.ini" 2>/dev/null || true

papelito_info "Diff resumo (não inclui binários/uploads)"
cd "$PROJECT_ROOT"
git status --short

papelito_info "Pull concluído. Próximos passos:"
echo "  1) Revise 'git status' e 'git diff'"
echo "  2) Compare _pulled/wp-config.php com public_html/wp-config.example.php"
echo "  3) Crie branch 'chore/sync-prod-after-hardening' e commite"
echo "  4) Confirme que wp-config.php REAL não está em git status (deve estar ignorado)"
