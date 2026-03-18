#!/usr/bin/env bash

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command zip

PLUGIN_SLUG="${1:-${DEPLOY_PLUGIN_SLUG:-plugin_papelito}}"
SOURCE_DIR="$PAPELITO_PROJECT_ROOT/public_html/wp-content/plugins/$PLUGIN_SLUG"
ARTIFACTS_DIR="$(papelito_artifacts_dir)"
ARTIFACT_PATH="$ARTIFACTS_DIR/${PLUGIN_SLUG}-$(papelito_timestamp).zip"

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Plugin não encontrado: $SOURCE_DIR" >&2
  exit 1
fi

mkdir -p "$ARTIFACTS_DIR"

(
  cd "$PAPELITO_PROJECT_ROOT/public_html/wp-content/plugins"
  zip -rq "$ARTIFACT_PATH" "$PLUGIN_SLUG" \
    -x "$PLUGIN_SLUG/my_plugin_log.txt" \
    -x "$PLUGIN_SLUG/.DS_Store"
)

papelito_info "Pacote gerado em $ARTIFACT_PATH"
printf '%s\n' "$ARTIFACT_PATH"
