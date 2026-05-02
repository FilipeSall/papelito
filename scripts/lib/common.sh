#!/usr/bin/env bash

set -euo pipefail

PAPELITO_PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

papelito_source_env_file() {
  local env_file="$1"
  local line key trimmed_key
  local -A preserved_values=()
  local -A preserved_flags=()

  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ "$line" =~ ^[[:space:]]*$ ]] && continue
    [[ "$line" != *=* ]] && continue

    key="${line%%=*}"
    trimmed_key="${key#"${key%%[![:space:]]*}"}"
    trimmed_key="${trimmed_key%"${trimmed_key##*[![:space:]]}"}"

    [[ -z "$trimmed_key" ]] && continue

    if [[ -n "${!trimmed_key+x}" ]]; then
      preserved_flags["$trimmed_key"]=1
      preserved_values["$trimmed_key"]="${!trimmed_key}"
    fi
  done < "$env_file"

  set -a
  # shellcheck disable=SC1090
  source "$env_file"
  set +a

  for trimmed_key in "${!preserved_flags[@]}"; do
    printf -v "$trimmed_key" '%s' "${preserved_values[$trimmed_key]}"
    export "$trimmed_key"
  done
}

papelito_load_env() {
  if [[ -f "$PAPELITO_PROJECT_ROOT/.env" ]]; then
    papelito_source_env_file "$PAPELITO_PROJECT_ROOT/.env"
  fi

  if [[ -f "$PAPELITO_PROJECT_ROOT/.env.local" ]]; then
    papelito_source_env_file "$PAPELITO_PROJECT_ROOT/.env.local"
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

papelito_warn() {
  printf '[papelito][warn] %s\n' "$*" >&2
}
