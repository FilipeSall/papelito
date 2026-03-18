#!/usr/bin/env bash

set -euo pipefail

PAPELITO_PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

papelito_load_env() {
  if [[ -f "$PAPELITO_PROJECT_ROOT/.env" ]]; then
    set -a
    # shellcheck disable=SC1091
    source "$PAPELITO_PROJECT_ROOT/.env"
    set +a
  fi

  if [[ -f "$PAPELITO_PROJECT_ROOT/.env.local" ]]; then
    set -a
    # shellcheck disable=SC1091
    source "$PAPELITO_PROJECT_ROOT/.env.local"
    set +a
  fi
}

papelito_timestamp() {
  date +%Y%m%d-%H%M%S
}

papelito_require_command() {
  local command_name="$1"

  if ! command -v "$command_name" >/dev/null 2>&1; then
    echo "Comando obrigatório não encontrado: $command_name" >&2
    exit 1
  fi
}

papelito_require_env() {
  local variable_name

  for variable_name in "$@"; do
    if [[ -z "${!variable_name:-}" ]]; then
      echo "Variável obrigatória não definida: $variable_name" >&2
      exit 1
    fi
  done
}

papelito_artifacts_dir() {
  echo "${ARTIFACTS_DIR:-$PAPELITO_PROJECT_ROOT/artifacts}"
}

papelito_info() {
  printf '[papelito] %s\n' "$*"
}
