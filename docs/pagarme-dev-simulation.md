# Simulacao local de pagamentos Pagar.me

Este fluxo existe apenas para desenvolvimento/teste. Nunca habilite em producao.

## Variaveis

```env
WP_ENVIRONMENT_TYPE=local
PAPELITO_PAGARME_SIMULATION_ENABLED=true
PAPELITO_PAGARME_SIMULATION_TOKEN=<token-local>
PAGARME_SECRET_KEY=sk_test_xxxxx
PAGARME_BASE_URL=https://api.pagar.me/core/v5
```

Se a conta de teste exigir o ambiente `sdx-api`, ajuste `PAGARME_BASE_URL` para `https://sdx-api.pagar.me/core/v5`.

## Cartao

- Aprovado: use o cartao de teste aprovado da Pagar.me no checkout. O front tokeniza com `NEXT_PUBLIC_PAGARME_PUBLIC_KEY=pk_test_xxxxx` e envia apenas `card_token_id` ao WordPress.
- Recusado: use o cartao de teste recusado. O carrinho deve permanecer preenchido e o pedido/cobranca recusados nao podem baixar estoque duas vezes.

## Boleto e webhook

Crie o pedido por boleto no checkout. Ao receber sucesso, o carrinho deve ser limpo e a pagina `/checkout/pagamento/{orderId}` deve exibir o boleto pendente.

Simule mudancas de estado:

```bash
curl -X POST 'http://localhost:8080/wp-json/papelito/v1/dev/pagarme/simulate-webhook' \
  -H 'Content-Type: application/json' \
  -H 'X-Papelito-Dev-Token: <token-local>' \
  -d '{"order_id":123,"scenario":"paid"}'
```

Cenarios aceitos:

- `pending`: mantem pagamento pendente.
- `paid`: marca como pago/processando.
- `failed`: marca como falho e libera reserva.
- `expired`: marca como vencido/falho e libera reserva.
- `duplicate`: reprocessa um evento pago.

Para duplicidade explicita:

```bash
curl -X POST 'http://localhost:8080/wp-json/papelito/v1/dev/pagarme/simulate-webhook' \
  -H 'Content-Type: application/json' \
  -H 'X-Papelito-Dev-Token: <token-local>' \
  -d '{"order_id":123,"scenario":"expired","repeat_count":2}'
```
