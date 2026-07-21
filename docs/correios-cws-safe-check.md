# Verificação segura de uma chave CWS

Este diagnóstico faz exatamente uma chamada `GET`, não possui retry e não cria,
cancela ou altera pré-postagens. Use somente depois de revogar qualquer chave que
tenha sido exposta e criar uma chave técnica nova, mínima e temporária.

Não coloque a chave no Git, em argumento de linha de comando, print, e-mail ou
chat. Digite-a silenciosamente no terminal e exporte-a apenas para o processo:

```bash
read -rs PAPELITO_CWS_ACCESS_KEY
export PAPELITO_CWS_ACCESS_KEY
PAPELITO_CWS_CHECK=cep ./scripts/check-correios-cws-key.sh
unset PAPELITO_CWS_ACCESS_KEY
```

O teste `cep` confirma somente chave e permissão para CEP v3. Ele não confirma a
capacidade de gerar etiquetas.

Para consultar de forma não destrutiva o serviço `86720 — API PRE POSTAGEM` no
cartão, a chave também precisa ter acesso a `Meu Contrato (566)`:

```bash
read -rs PAPELITO_CWS_ACCESS_KEY
export PAPELITO_CWS_ACCESS_KEY
PAPELITO_CWS_CHECK=prepost-service \
PAPELITO_CORREIOS_CNPJ=00000000000000 \
PAPELITO_CORREIOS_CONTRACT=0000000000 \
PAPELITO_CORREIOS_POSTING_CARD=0000000000 \
./scripts/check-correios-cws-key.sh
unset PAPELITO_CWS_ACCESS_KEY
```

Substitua somente os números fictícios localmente. O script imprime apenas o
status HTTP:

- `200`: chave aceita; no teste de serviço, o `86720` está presente no cartão;
- `401`: chave inválida, expirada ou revogada;
- `403`: a chave não tem o escopo necessário;
- `404`: no teste de serviço, o `86720` não foi encontrado naquele cartão;
- `429` ou `5xx`: inconclusivo; não repita automaticamente.

Fonte do endpoint e da interpretação do serviço: [Manual oficial da API Meu
Contrato](https://www.correios.com.br/atendimento/developers/manuais/manual-api-meu-contrato),
versão 1.0, 06/10/2025.
