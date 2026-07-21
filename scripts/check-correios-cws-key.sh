#!/usr/bin/env bash
# Verificacao somente leitura de uma chave CWS. Nao cria nem altera postagens.

set -euo pipefail
set +x

check_kind="${PAPELITO_CWS_CHECK:-cep}"
base_url="${PAPELITO_CWS_BASE_URL:-https://api.correios.com.br}"
access_key="${PAPELITO_CWS_ACCESS_KEY:-}"

if [[ -z "${access_key}" ]]; then
  echo "Defina PAPELITO_CWS_ACCESS_KEY por um canal local seguro." >&2
  exit 2
fi
if [[ ! "${access_key}" =~ ^cws-ch[0-9A-Za-z_?=:+/-]+$ ]]; then
  echo "A chave CWS possui formato inesperado; nenhuma chamada foi realizada." >&2
  exit 2
fi

case "${base_url}" in
  https://api.correios.com.br|https://apihom.correios.com.br) ;;
  *)
    echo "PAPELITO_CWS_BASE_URL deve apontar para api.correios.com.br ou apihom.correios.com.br." >&2
    exit 2
    ;;
esac

case "${check_kind}" in
  cep)
    target_url="${base_url}/cep/v3/enderecos/01001000"
    ;;
  prepost-service)
    cnpj="${PAPELITO_CORREIOS_CNPJ:-}"
    contract="${PAPELITO_CORREIOS_CONTRACT:-}"
    posting_card="${PAPELITO_CORREIOS_POSTING_CARD:-}"
    if [[ ! "${cnpj}" =~ ^[0-9]{14}$ || ! "${contract}" =~ ^[0-9]+$ || ! "${posting_card}" =~ ^[0-9]+$ ]]; then
      echo "Para prepost-service, informe CNPJ (14 digitos), contrato e cartao somente com numeros." >&2
      exit 2
    fi
    target_url="${base_url}/meucontrato/v1/empresas/${cnpj}/contratos/${contract}/cartoes/${posting_card}/servicos/86720"
    ;;
  *)
    echo "PAPELITO_CWS_CHECK deve ser cep ou prepost-service." >&2
    exit 2
    ;;
esac

http_code="$({ printf 'header = "Authorization: Bearer %s"\n' "${access_key}"; } | curl \
  --config - \
  --silent \
  --show-error \
  --proto '=https' \
  --connect-timeout 5 \
  --max-time 10 \
  --retry 0 \
  --header 'Accept: application/json' \
  --output /dev/null \
  --write-out '%{http_code}' \
  "${target_url}")" || http_code="000"

unset access_key

echo "Correios CWS check=${check_kind} HTTP=${http_code} calls=1 retry=0"

case "${http_code}" in
  200) exit 0 ;;
  401|403|404|429|500|502|503|504|000) exit 3 ;;
  *) exit 4 ;;
esac
