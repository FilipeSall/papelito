#!/usr/bin/env bash

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/common.sh"
papelito_load_env
papelito_require_command zip

THEME_SLUG="${1:-${DEPLOY_THEME_SLUG:-jupiterx-child}}"
SOURCE_DIR="$PAPELITO_PROJECT_ROOT/public_html/wp-content/themes/$THEME_SLUG"
ARTIFACTS_DIR="$(papelito_artifacts_dir)"
ARTIFACT_PATH="$ARTIFACTS_DIR/${THEME_SLUG}-$(papelito_timestamp).zip"

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "Tema não encontrado: $SOURCE_DIR" >&2
  exit 1
fi

mkdir -p "$ARTIFACTS_DIR"

(
  cd "$PAPELITO_PROJECT_ROOT/public_html/wp-content/themes"
  zip -rq "$ARTIFACT_PATH" "$THEME_SLUG" \
    -x "$THEME_SLUG/.git/*" \
    -x "$THEME_SLUG/.DS_Store"
)

papelito_info "Pacote gerado em $ARTIFACT_PATH"
printf '%s\n' "$ARTIFACT_PATH"
