# Runbook — backfill de recibos

Emite recibo para pedidos **pagos antes de `receipts.php` existir**, que por isso nunca passaram pelo evento `papelito_order_payment_confirmed`.

## Antes de começar

- **Não existe rollback.** Apagar linhas por `origin = 'backfill'` **não** devolve os números consumidos e pode quebrar referência já entregue ao comprador. A única recuperação aceitável é **restore de backup aprovado**. Confirme que existe backup recente **antes** do primeiro lote aplicado.
- O comando **não escreve nada no pedido** — status, total e postmeta ficam intocados. A única gravação é o recibo e o checkpoint em `wp_options`.
- Numeração é **anual e segue a data de pagamento**. Backfillar pedido de 2024 consome sequência de **2024**, não do ano corrente — é por isso que a fila é processada do pagamento mais antigo para o mais novo.
- **Não há WordPress de homologação.** Ensaia-se localmente, com dump autorizado.

## Comandos

```bash
wp papelito receipts backfill_status                 # checkpoint + quantos faltam
wp papelito receipts backfill --dry-run              # simula, não grava nada
wp papelito receipts backfill --dry-run --batch=200
wp papelito receipts backfill --batch=25             # aplica um lote e agenda continuação
wp papelito receipts backfill_reset                  # limpa checkpoint e continuação agendada
```

> Os subcomandos usam **underscore** (`backfill_status`), não hífen — é como o WP-CLI registra métodos de classe neste plugin.

`--batch` vai de 1 a 500; ausente ou inválido cai em 50. Sem `--dry-run` o comando **aplica** — não existe `--apply`.

## Procedimento

1. `backfill_status` para ver o tamanho **total** da fila (`pending`), os IDs bloqueados e o `storage` detectado (`posts` ou `hpos`).
2. `backfill --dry-run` e confira `would_issue` contra o `pending`.
3. Aplique **um lote pequeno** (`--batch=25`) e verifique no banco:
   ```bash
   wp eval 'global $wpdb; $t = papelito_receipts_table_names();
     echo $wpdb->get_var("SELECT COUNT(*) FROM {$t["receipts"]} WHERE origin = \"backfill\"");'
   ```
4. Cada lote aplicado agenda automaticamente um único lote de continuação, preservando o mesmo `--batch`. O próximo lote roda após cerca de um minuto, quando o WP-Cron for acionado. Para operar sem depender de tráfego, rode `wp cron event run papelito_receipts_backfill_continue`.
5. Acompanhe com `backfill_status` até `pending` chegar a zero. Reexecutar manualmente é seguro: pedido que já tem recibo sai da fila pela própria consulta.
6. `backfill_status` no fim, para registrar `total_issued` no ticket.

## Se um pedido falhar

Pedido cuja emissão retorna erro entra em `blocked_order_ids` no checkpoint e **fica fora dos lotes seguintes**, para não travar a fila atrás dele. Os IDs não são descartados pelo tamanho da lista. O comando lista os IDs em um `warning`.

Investigue o pedido (estado Pagar.me, itens, metas de centavos) e, resolvido, rode `backfill_reset` para reincluí-lo. `backfill_reset` cancela a continuação pendente e **não apaga recibo nenhum** — mexe só no checkpoint e no agendamento.

## O que o snapshot de um pedido histórico significa

O recibo de backfill congela **o estado disponível no momento do backfill**, não o estado do dia do pagamento. Se o pedido foi reembolsado ou editado depois de pago, o snapshot reflete o que estava no `WC_Order` quando o lote rodou.

Isso é rastreável na própria linha: `origin = 'backfill'` distingue esses recibos dos emitidos na confirmação do pagamento (`origin = 'payment'`), e `snapshot_version` identifica o formato do `snapshot_json`.

> **Atenção ao interpretar `origin`.** Desde a etapa 2, o próprio download do PDF emite o recibo faltante — com `origin = 'payment'`. Pedido histórico cujo comprador baixou o recibo antes do backfill aparece como `payment`. `origin` **não é um censo do que o backfill fez**; para isso use `total_issued` do checkpoint.

## Ensaio local com dump de produção

```bash
bash scripts/pull-from-prod.sh
docker compose exec web wp --allow-root papelito receipts backfill_status
docker compose exec web wp --allow-root papelito receipts backfill --dry-run
docker compose exec web wp --allow-root eval-file \
  wp-content/plugins/plugin_papelito/tests/test-receipts-backfill-db.php
```

O teste de integração cria pedidos descartáveis, valida ordenação por pagamento, exclusão de não pago e de quem já tem recibo, e apaga tudo no fim.
