# Runbook — Mercado Pago: configuração, ativação por coorte e migração assistida

Como ligar o Checkout Pro do Mercado Pago (marketplace 1:1, OAuth por vendor) e migrar um vendor. Decisões e regras ficam no [plano canônico](../../../docs/mercadopago-migration.md); contratos REST em [`integration-contracts.md`](../../../docs/integration-contracts.md#gateways-de-pagamento--mercado-pago-e-pagarme); evidências da API em [MP-001](../../../docs/mercadopago/mp-001-matriz.md).

> Nenhum valor real de credencial vive neste documento. Segredos ficam no `.env` local (gitignorado), no cofre de integrações ou no `wp-config.php` do servidor, que é editado à mão (`putenv` **e** `define`, como as demais variáveis).

## Variáveis

| Variável | Onde | Observação |
|---|---|---|
| `MERCADOPAGO_CLIENT_ID` | WP | Número da aplicação no painel do Mercado Pago |
| `MERCADOPAGO_CLIENT_SECRET` | WP (ou cofre `mercadopago_client_secret`) | Nunca no Vercel |
| `MERCADOPAGO_WEBHOOK_SECRET` | WP (ou cofre `mercadopago_webhook_secret`) | "Assinatura secreta" de Suas integrações → Webhooks |
| `MERCADOPAGO_REDIRECT_URI` | WP, opcional | Reserva para conexão sem domínio de frontend. Normalmente o callback é `{domínio que iniciou}/api/vendor/mercadopago/callback`; cadastre na aplicação o de produção **e** o de teste |
| `MERCADOPAGO_ENVIRONMENT` | WP | `live` obrigatório em produção; fora dela `test` ou `live`. Usuário de teste do Mercado Pago usa credencial de produção e seus pagamentos chegam com `live_mode: true`, então teste com usuário de teste usa `live` |
| `MERCADOPAGO_NOTIFICATION_BASE_URL` | WP, opcional | URL HTTPS pública do webhook (`…/wp-json/papelito/v1/mercadopago/webhook`). Em dev, a do túnel. Vazia = `rest_url()`; sem HTTPS a preferência sai sem notificação e a reconciliação de 5 em 5 min cobre |
| `MERCADOPAGO_AUTH_URL` | WP, opcional | Padrão `https://auth.mercadopago.com.br/authorization` |
| `PAPELITO_PAYMENT_GATEWAYS_ENABLED` | WP | `false` por padrão. Liga o fluxo novo **só** para a allowlist |
| `PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS` | WP | IDs de vendor separados por vírgula |
| `PAPELITO_TEST_ORIGINS` | WP | Domínios de teste (ex.: `https://papelito-web.vercel.app`), também presentes em `PAPELITO_ALLOWED_ORIGINS` |
| `PAPELITO_TEST_VENDOR_IDS` | WP | Vendors de teste: só aparecem e vendem pelos domínios de `PAPELITO_TEST_ORIGINS` |

Nenhum token OAuth de vendor vai para o Next/Vercel; o front só conhece rotas `/api/...`.

## Configurar a aplicação no Mercado Pago

1. Suas integrações → aplicação → **Redirect URLs**: `https://marketplace.papelito.com/api/vendor/mercadopago/callback` e `https://papelito-web.vercel.app/api/vendor/mercadopago/callback`.
2. Habilitar **PKCE** (o plugin sempre envia `code_challenge` S256).
3. Escopos com `offline_access` (sem ele não há renovação de token).
4. Webhooks → URL de produção = `…/wp-json/papelito/v1/mercadopago/webhook`, tópico **Pagamentos**; copiar a assinatura secreta para `MERCADOPAGO_WEBHOOK_SECRET`.

## Testar localmente (sandbox)

1. Preencher no `.env` do `papelito-wordpress` as variáveis acima com as credenciais de **teste** da aplicação e `MERCADOPAGO_ENVIRONMENT=test`; `docker compose up -d`.
2. Para webhook, expor o WP por túnel HTTPS e pôr a URL em `MERCADOPAGO_NOTIFICATION_BASE_URL`. Pagamentos feitos com credencial de teste **não** notificam a URL da preferência (doc oficial); a reconciliação programada e a tela de retorno resolvem.
3. Ligar a coorte: `PAPELITO_PAYMENT_GATEWAYS_ENABLED=true` e `PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS=<id do vendor de teste>`.
4. No painel do vendor, **Conectar Mercado Pago** com o usuário de teste vendedor; no admin, registrar a conferência de titularidade (abaixo). Comprar com o usuário de teste comprador em aba anônima.

## Ativar um vendor (piloto)

1. Implantar o código com a flag **desligada**; publicar antes o frontend compatível.
2. No servidor (sessão SSH restrita), preview sem efeito:
   ```bash
   wp papelito payment-gateways migrate --vendor-id=<id> --dry-run
   ```
   Mostra CNAE e política Pagar.me, ação de importação do perfil, conta MP, tentativas abertas, operações previstas e `version`. Sem CNPJ, token ou KYC na saída.
3. Se o CNAE estiver `pending`, reconsultar explicitamente (chamada à BrasilAPI):
   ```bash
   wp papelito payment-gateways refresh-cnae --vendor-id=<id>
   ```
4. Aplicar com a versão do preview (recusa se algo mudou ou se houver tentativa `unknown`/`creating`):
   ```bash
   wp papelito payment-gateways migrate --vendor-id=<id> --apply --expected-version=<version>
   ```
   Importa o draft legado para o perfil comum, grava o marcador `papelito_vendor_payment_policy_enforced` e reavalia a saúde. Não cria recipient, não apaga KYC, não sobrescreve preferência explícita.
5. Incluir o ID em `PAPELITO_PAYMENT_GATEWAYS_VENDOR_IDS` e ligar `PAPELITO_PAYMENT_GATEWAYS_ENABLED` no `wp-config.php`.
6. O **próprio vendor** conecta a conta Mercado Pago no painel (o operador nunca autoriza em nome dele).
7. Admin confere a titularidade (conta do CNPJ da loja) e registra em *Admin → Vendors → detalhe → Gateways* (`POST /admin/vendors/{id}/mercadopago/identity` com referência da evidência, ex. nº do chamado). Só então o Mercado Pago fica `ready`.
8. Conferir painel, elegibilidade (`/vendor/me/eligibility` com `payment_gateway`), o redirect e a reconciliação. Cobrança real só com autorização específica, valor combinado e estorno previsto.

## Observar

- `GET /wp-json/papelito/v1/admin/payment-gateways/metrics` (admin): contadores diários (`creation_unknown`, `fallback`, `connection_failed`, `webhook_rejected`, `reconcile_deferred`, `refund_failed`, `paid`, `expired`) e incidentes (incidente marcado, `unknown` há mais de 30 min, 3+ falhas de reconciliação).
- Log: linhas `[papelito_payment] attempt=<id> order=<id> vendor=<id> provider=… env=… cfg=… from->to reason=…` e `[papelito_mercadopago] <método> <path> -> <categoria>`. Sem attemptRef, documento, conta ou token.
- Tentativa presa em `unknown`: a reconciliação busca pagamentos por `external_reference` com a conta histórica; sem pagamento em 10 min ela expira e libera a reserva (o link nunca chegou ao comprador).

## Rollback

- Desligar `PAPELITO_PAYMENT_GATEWAYS_ENABLED` ou tirar o vendor da allowlist **suspende novas compras** desse vendor; ele **não** volta ao recebedor Pagar.me legado (o marcador é permanente). Para tabacaria (CNAE bloqueado) isso é intencional.
- Webhook, reconciliação e estornos de cobranças Mercado Pago já emitidas continuam rodando com a flag desligada.
- Nenhuma tabela é removida. Não voltar a um build que não conhece Mercado Pago enquanto houver cobrança MP ativa.
