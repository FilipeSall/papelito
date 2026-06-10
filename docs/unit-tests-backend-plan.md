# Plano de Testes Unitários do Backend

> **Status:** proposta / a implementar.
> **Projeto:** `papelito-wordpress` — backend headless do marketplace Papelito (WordPress + WooCommerce + WPGraphQL + JWT).
> **Alvo:** o plugin custom `plugin_papelito` (regras de negócio do marketplace). Não cobre core do WordPress nem plugins de terceiros.
> **Escopo deste documento:** plano técnico, prático e executável para introduzir testes unitários e de integração baseados nas **regras de negócio reais** do backend. É o guia que outro desenvolvedor seguirá; não é relatório de execução.
> **Documento irmão (front-end):** [../papelito-web/docs/unit-tests-front-plan.md](../../papelito-web/docs/unit-tests-front-plan.md).

---

## 1. Objetivo

Criar uma rede de segurança anti-regressão sobre as **regras de negócio reais** do backend do marketplace.

O `plugin_papelito` é a fonte de verdade do domínio: cadastro com campos BR, verificação de e-mail, OAuth Google, cobertura regional por CEP, estoque por vendor, vendor ativo, notificações, mensagens, cupons e roteamento de pedido. Hoje são **~15.000 linhas de código procedural sem nenhum teste** — qualquer alteração pode quebrar uma regra silenciosamente.

**O que estes testes garantem:**

- que mudanças futuras não quebrem regras de negócio (faixa de CEP, dupla aprovação de vendor, transação de estoque, bloqueio de login não verificado);
- que os **contratos REST/GraphQL** consumidos pelo front (`../papelito-web`) não regridam (status code, shape do JSON, códigos de erro `papelito_*`);
- documentação executável das invariantes do domínio.

**Anti-objetivos (o que estes testes NÃO devem ser):**

- ❌ testar core do WordPress, WooCommerce, WPGraphQL ou outros plugins de terceiros;
- ❌ depender de HTTP real (Google, BrasilAPI, ViaCEP, Nominatim) ou de dados de produção;
- ❌ cobrir só o caminho feliz — bordas e erros (`WP_Error`) são o ponto;
- ❌ testar detalhes internos de implementação em vez de comportamento/contrato.

---

## 2. Stack recomendada

Reaproveitando **exatamente** a stack que o repositório já baixa via o plugin de terceiros `pagarme-payments-for-woocommerce` ([composer.json](../public_html/wp-content/plugins/pagarme-payments-for-woocommerce/composer.json)):

| Camada | Ferramenta | Papel |
|---|---|---|
| Runner | **PHPUnit 10.5** | Test runner PHP, alinhado ao PHP 8.3 do Docker |
| Mock de WP (unit) | **Brain Monkey 2** | Stub de funções globais do WP (`get_user_meta`, `wp_insert_user`, `wp_remote_get`…) sem carregar o WP |
| Mock de objetos | **Mockery 1.6** | Test doubles para serviços/repositórios injetados |
| Integração | **WP_UnitTestCase** (`wordpress/wordpress` dev-master + `bin/install-wp-tests.sh`) | Banco real para validar SQL (`wpdb`), usermeta e queries |
| Lint | **PHPCS/WPCS** (já configurado) | [phpcs.xml.dist](../phpcs.xml.dist) — mantém-se verde |

Instalação (no `plugin_papelito`):

```bash
cd public_html/wp-content/plugins/plugin_papelito
composer require --dev phpunit/phpunit:10.5.* brain/monkey:2.* mockery/mockery:1.6.*
```

Scripts a adicionar no `composer.json` do plugin:

```jsonc
{
  "scripts": {
    "test": ["@test:unit", "@test:integration"],
    "test:unit": "phpunit --testsuite unit",
    "test:integration": "phpunit --testsuite integration",
    "test:coverage": "phpunit --testsuite unit --coverage-text"
  }
}
```

> ⚠️ A suíte de **integração** exige um banco MySQL/MariaDB de teste — disponível no Docker do projeto ([docker-compose.yml](../docker-compose.yml): MariaDB 10.5). A suíte **unit** roda sem banco e é a que deve sempre rodar no CI rápido.

---

## 3. Justificativa técnica

A abordagem é **híbrida** (Brain Monkey + WP_UnitTestCase). Justificativa:

### Por que não apenas Brain Monkey (unit puro)
Brain Monkey mocka funções globais do WP, então `$wpdb` vira um dublê. Isso é ótimo para funções puras e lógica de regra, mas **não valida SQL real**. O plugin tem operações onde o SQL *é* a regra de negócio:
- UPSERT `INSERT ... ON DUPLICATE KEY UPDATE` no estoque ([vendor_stock.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_stock.php));
- transação `SELECT ... FOR UPDATE` em ajustes concorrentes de estoque;
- JOINs de `wp_users` + `wp_usermeta` para cobertura e relatórios;
- usermeta como **array serializado** (`min_cep[]`/`max_cep[]`).
Mockar `wpdb` nesses casos testaria o mock, não o comportamento.

### Por que não apenas WP_UnitTestCase (integração)
Subir o WordPress completo para testar `papelito_validate_cpf()` ou `papelito_haversine_km()` é lento e desnecessário — essas são funções determinísticas puras. Rodar tudo em integração tornaria o CI pesado e desencorajaria escrever testes.

### Por que a combinação (escolhida)
- **Brain Monkey/Mockery** para a base larga e rápida: validações BR, normalizadores, parsers, fallback de geocodificação (mock HTTP) → rodam no CI sem banco.
- **WP_UnitTestCase** só onde o banco importa: estoque, cobertura, notificações, mensagens, criação de usuário/usermeta → validação real.
- **Precedente interno**: o próprio repositório já usa PHPUnit 10.5 + Brain Monkey 2 + Mockery 1.6 no plugin Pagar.me, com `tests/bootstrap.php` pronto para servir de modelo. Não inventamos stack nova.

---

## 4. Principais fluxos analisados

Todos os arquivos abaixo estão em `public_html/wp-content/plugins/plugin_papelito/includes/`.

| # | Fluxo | Arquivos / funções reais | Camada |
|---|---|---|---|
| 1 | **Cadastro BR** | [auth_endpoints.php](../public_html/wp-content/plugins/plugin_papelito/includes/auth_endpoints.php) — `papelito_auth_validate_register_payload`, `_seller_register_payload`, `papelito_auth_create_registered_user`/`_seller` | WP |
| 2 | **Verificação de e-mail** | [auth_endpoints.php](../public_html/wp-content/plugins/plugin_papelito/includes/auth_endpoints.php) — token sha256, expiry 24h, rate limit, bloqueio de login (`wp_authenticate_user`) | WP |
| 3 | **OAuth Google** | [auth_endpoints.php](../public_html/wp-content/plugins/plugin_papelito/includes/auth_endpoints.php) — `papelito_auth_verify_google_id_token`, `papelito_auth_find_or_create_google_user` | Mock HTTP + WP |
| 4 | **Validações BR puras** | `papelito_auth_is_valid_cep`, `papelito_auth_normalize_phone`, `papelito_auth_format_phone`; `papelito_validate_cpf`/`papelito_calculate_cpf_digit` ([revendedor_application.php](../public_html/wp-content/plugins/plugin_papelito/includes/revendedor_application.php)) | Puro |
| 5 | **Cobertura por CEP** | [rest_api.php](../public_html/wp-content/plugins/plugin_papelito/includes/rest_api.php) — `papelito_sellers_by_cep`, `papelito_coverage_vendors`, `papelito_coverage_products`; [products_filter.php](../public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php) — `papelito_matching_vendor_ids` | Puro (regra de faixa) + WP |
| 6 | **Estoque (vendor stock)** | [vendor_stock.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_stock.php) — `papelito_get/set/adjust_vendor_stock`, `papelito_vendors_with_stock`, `papelito_vendor_stock_query` | WP (SQL real) |
| 7 | **Vendor ativo** | [active_vendor.php](../public_html/wp-content/plugins/plugin_papelito/includes/active_vendor.php) — `papelito_validate_active_vendor`, `papelito_set_active_vendor`, `papelito_resolve_default_vendor_id` | WP |
| 8 | **Geocodificação** | [vendor_geo.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_geo.php) — `papelito_geocode_cep` (BrasilAPI→ViaCEP→Nominatim), `papelito_haversine_km`, `papelito_normalize_cep` | Puro (haversine/normalize) + Mock HTTP |
| 9 | **Notificações & mensagens** | [notifications.php](../public_html/wp-content/plugins/plugin_papelito/includes/notifications.php), [vendor_messaging.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_messaging.php) | WP (SQL real) |
| 10 | **Cupons / flash sale / order routing** | [coupons.php](../public_html/wp-content/plugins/plugin_papelito/includes/coupons.php), [flash_sale.php](../public_html/wp-content/plugins/plugin_papelito/includes/flash_sale.php), [order_routing.php](../public_html/wp-content/plugins/plugin_papelito/includes/order_routing.php), `pagarme_*` | Puro (normalizadores) + WP |

---

## 5. Regras de negócio relevantes

Invariantes extraídas do código. Cada uma deve ter ≥1 teste cobrindo caminho feliz **e** borda/erro.

### Validações brasileiras
1. `papelito_auth_is_valid_cep` aceita `\d{5}-?\d{3}` **e** `\d{2}\.\d{3}-\d{3}`; rejeita o resto.
2. `papelito_auth_normalize_phone`: remove não-dígitos; descarta prefixo país `55` quando o total é 12 ou 13 dígitos.
3. `papelito_auth_format_phone`: 11 dígitos → `(XX) XXXXX-XXXX`; 10 → `(XX) XXXX-XXXX`; senão devolve sanitizado.
4. `papelito_validate_cpf`: valida pelos dígitos verificadores (peso 10 e 11); rejeita comprimento ≠ 11 e sequências repetidas.
5. `papelito_normalize_cep`: extrai 8 dígitos ou retorna `''`.

### Cadastro & verificação de e-mail
6. `papelito_auth_validate_register_payload` exige e-mail válido, senha ≥8, nome/sobrenome, telefone (10–11 dígitos), CEP, estado; CNPJ opcional. Seller exige `store_name`, CNPJ, city/state, instagram, `min_cep`/`max_cep`, `has_sold`.
7. E-mail nasce `pending`; token é **sha256 do plaintext**, **one-time**, expira em **24h**.
8. Registro tem **rate limit de 10/min por IP**; reenvio tem cooldown de 1min.
9. Login por senha é **bloqueado** até `papelito_email_verification_status = 'verified'` (hook `wp_authenticate_user`).

### OAuth Google
10. `papelito_auth_verify_google_id_token` exige HTTP 200, `email_verified = true` e `aud == PAPELITO_GOOGLE_CLIENT_ID`; senão `WP_Error` (`papelito_invalid_token`/`papelito_unverified_email`); falha de rede → `papelito_google_unreachable`.
11. `find_or_create_google_user` faz account linking por `google_sub`/e-mail; usuário novo nasce com `papelito_profile_complete = 0`.

### Cobertura regional por CEP
12. Vendor cobre um CEP se existe índice `i` tal que `min_cep[i] <= user_cep <= max_cep[i]` (faixas são arrays em usermeta).
13. **Dupla aprovação** para aparecer na cobertura: role `seller` **e** `application_status = 'approved'`.
14. `coverage/products` retorna, por produto, `has_coverage`, `best_vendor`, `alternatives`.
15. Resposta é cacheada (transient versionado por `papelito_coverage_cache_version`, ~5min) e invalidada por `papelito_vendor_stock_changed` ou mudança de metas de cobertura (`min_cep`, `max_cep`, `cep`, `cep_lat`, `cep_lng`, `store_name`, `city`, `state`, `application_status`, `shipping_lead_time_days`).
16. Com `vendor_id` informado, checa só aquele vendor por faixa + estoque, **sem geocodificar** (invariante de performance).

### Estoque (vendor stock)
17. `set_vendor_stock` faz UPSERT; `adjust_vendor_stock` incrementa/decrementa e grava log com reason prefixada (`vendor_update:` / `admin_adjustment:`).
18. Transição de `qty > 0` para `0` dispara a action `papelito_stock_zeroed`.
19. Ajuste concorrente usa `SELECT ... FOR UPDATE` (transação).
20. Alterações de estoque disparam `papelito_vendor_stock_changed` (invalida cobertura).

### Vendor ativo
21. Default = vendor mais próximo **com estoque** (resolução lazy).
22. `validate_active_vendor` exige cobertura do CEP + `approved` + role seller.
23. `set_active_vendor` dispara `papelito_active_vendor_changed(user_id, prev_id, new_id)`.

### Pagamento (Pagar.me — em implementação)
24. Pagamento **direto ao vendor, sem split de receita**; tecnicamente usa `split` PSP dentro de `payments[]` com recebedor único a 100%, vendor arca taxas.
25. Vender exige **dupla aprovação**: regional (CEP) **e** recebedor Pagar.me `active` (KYC).
26. `POST /checkout/place-order` ([order_routing.php](../public_html/wp-content/plugins/plugin_papelito/includes/order_routing.php)) cria pedido Woo, reserva estoque e chama Pagar.me; testar sucesso, erro de pagamento e rollback de reserva.
27. Webhook aceita eventos `order.*` e `charge.*`; `charge.*` precisa localizar pedido por `charge_id` quando o payload não trouxer `order_id` direto.
28. Reconciliacao WP-Cron libera reservas de estoque para pagamentos não pagos em estado terminal ou expirados, sem restocar pedidos pagos/processados.

### Erros padronizados
29. Erros retornam `WP_Error` com códigos `papelito_*` e `status` HTTP (`papelito_invalid_cep`, `papelito_unverified_email`, `papelito_invalid_token`, `papelito_email_exists`, `papelito_rate_limit`, `papelito_invalid_vendor`, `papelito_stock_error`, `papelito_google_unreachable`…). Testes asseram `get_error_code()` e o `status`.

---

## 6. Estratégia geral de testes

Pirâmide, da base (mais barata/estável) ao topo:

```
        ╱ smoke de endpoints REST (rest_do_request / WP_REST_Request) — contrato
       ╱  integração wpdb/usermeta (WP_UnitTestCase + factories) — SQL real
      ╱   lógica com WP/HTTP mockados (Brain Monkey + Mockery)
     ╱___ funções puras (PHPUnit puro + @dataProvider) — base larga
```

**Princípios:**

- **Testar comportamento e contrato**, não internals: status code, shape do JSON, `WP_Error->get_error_code()` e `->get_error_data()['status']`, efeitos observáveis (action disparada, usermeta gravada).
- **AAA** e um conceito por teste; usar `@dataProvider` para tabelas-verdade.
- **Banco real só onde o SQL importa** (estoque, coverage, CRUD); o resto roda mockado.
- **Mockar só a fronteira**: HTTP externo, relógio (expiry/rate limit), JWT (`WPGraphQL\JWT_Authentication\Auth`). A regra de negócio nunca é mockada.

### Refatoração guiada por testes (incremental, por bounded context)

O código é procedural; algumas regras só ficam testáveis de forma limpa se a lógica for extraída. A abordagem é **incremental e segura**, não um rewrite:

- Para **cada bounded context** que um step toca (auth, estoque, coverage, vendor ativo…), extrair a **lógica de regra** das funções `papelito_*` para **classes/serviços** com dependências injetáveis — por exemplo:
  - `interface Clock` (em vez de `time()`) → testar expiry/rate limit/one-time de forma determinística;
  - `interface GoogleTokenVerifier` → testar `find_or_create` sem HTTP;
  - `interface VendorStockRepository` (sobre `$wpdb`) → testar regra de estoque com fake em unit, e o repositório real em integração;
  - `CoverageRangeMatcher` (faixa `min_cep`/`max_cep`) → função/serviço puro.
- As funções `papelito_*` viram **cascas finas (adapters)** que instanciam o serviço e delegam, **preservando 100% as assinaturas públicas e os contratos REST/GraphQL**.
- **Regra de ouro da refatoração:** cobrir com teste **antes** de mover a lógica; refatorar **somente** o que está sendo testado naquele step; nunca alterar endpoint/shape/erro observável pelo front. Os testes travam o comportamento durante a extração.

---

## 7. Estrutura de pastas sugerida

```
public_html/wp-content/plugins/plugin_papelito/
├── composer.json                # + autoload-dev PSR-4 "Papelito\Tests\", require-dev
├── phpunit.xml.dist             # 2 testsuites: unit e integration
├── bin/
│   └── install-wp-tests.sh      # script padrão WP p/ subir a suíte de integração
├── tests/
│   ├── bootstrap.php            # suíte unit: Brain Monkey + require_once dos includes
│   ├── bootstrap-wp.php         # suíte integration: carrega o test framework do WP
│   ├── Unit/
│   │   ├── Auth/                # validações BR, payloads, token (Clock injetado)
│   │   ├── Geo/                 # haversine, normalize_cep, fallback chain (HTTP mock)
│   │   ├── Coverage/            # CoverageRangeMatcher (faixa de CEP)
│   │   └── ...
│   ├── Integration/
│   │   ├── Stock/               # UPSERT, adjust, log, zeroed (banco real)
│   │   ├── Notifications/
│   │   ├── Auth/                # criação de user + usermeta + verify-email
│   │   ├── Coverage/            # query multi-seller + cache
│   │   └── Rest/                # smoke dos endpoints via rest_do_request
│   ├── Factories/               # builders de seller/customer usermeta, payloads
│   └── Doubles/                 # FakeClock, FakeHttp, FakeGoogleVerifier
└── src/                         # (refatoração incremental) serviços extraídos
```

Esqueleto de `phpunit.xml.dist`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="integration">
      <directory>tests/Integration</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

> A suíte `integration` usa um bootstrap próprio (`bootstrap-wp.php`) que carrega o framework de testes do WP; configure via variável de ambiente ou um `phpunit-integration.xml.dist` separado para não misturar com o Brain Monkey da suíte `unit`.

Esqueleto de `tests/bootstrap.php` (suíte unit):

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Constantes mínimas que os includes esperam.
if (!defined('ABSPATH'))        define('ABSPATH', __DIR__ . '/');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS'))  define('DAY_IN_SECONDS', 86400);

// Brain Monkey é inicializado por teste (setUp/tearDown), não aqui.
// Carregar apenas os includes com funções puras/lógica sob teste:
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/vendor_geo.php';
// ... (carregar por demanda conforme a suíte cresce)
```

---

## 8. Plano em 10 steps

Cada step traz: **Objetivo · Arquivos/áreas · Tipos de teste · Regras de negócio · Riscos · Resultado esperado.**

### Step 1 — Diagnóstico e inventário
- **Objetivo:** classificar cada função `papelito_*` e produzir um inventário priorizado.
- **Arquivos/áreas:** todos os `includes/*.php`, [composer.json](../public_html/wp-content/plugins/plugin_papelito/composer.json), referência [pagarme .../tests/bootstrap.php](../public_html/wp-content/plugins/pagarme-payments-for-woocommerce/tests/bootstrap.php).
- **Tipos de teste:** nenhum — levantamento. Marcar cada função como **PURO** | **PRECISA WP** | **PRECISA MOCK HTTP** e candidata-a-extração.
- **Regras de negócio:** mapear §5 → funções.
- **Riscos:** confundir helper de hook (efeito colateral em WC) com lógica de regra testável.
- **Resultado esperado:** checklist priorizado (puras → estoque/coverage/auth → resto) que guia os steps 3–9.

### Step 2 — Configuração do ambiente de testes
- **Objetivo:** `composer test:unit` e `composer test:integration` funcionando, sem testes reais ainda.
- **Arquivos/áreas:** `composer require --dev` (PHPUnit/Brain Monkey/Mockery); `autoload-dev` PSR-4 `Papelito\Tests\`; `phpunit.xml.dist` (2 suites); `tests/bootstrap.php` (unit) e `bin/install-wp-tests.sh` + `tests/bootstrap-wp.php` (integração, usando MariaDB do Docker); scripts composer; estender CI ([.github/workflows/lint.yml](../.github/workflows/lint.yml)) com job de teste (unit sempre; integração com serviço de DB).
- **Tipos de teste:** um smoke trivial por suíte (`assertTrue(true)` no unit; `assertInstanceOf(\WP_User::class, ...)` simples no integration).
- **Regras de negócio:** nenhuma.
- **Riscos:** subir banco de teste isolado no container; alinhar versão de PHP (8.3); não poluir o banco de dev.
- **Resultado esperado:** infra verde nas duas suítes; PHPCS continua passando.

### Step 3 — Testes de funções puras e regras de negócio
- **Objetivo:** cobrir a camada determinística — maior ROI, sem WP.
- **Arquivos/áreas:** validações BR ([auth_endpoints.php](../public_html/wp-content/plugins/plugin_papelito/includes/auth_endpoints.php), [revendedor_application.php](../public_html/wp-content/plugins/plugin_papelito/includes/revendedor_application.php)), `papelito_haversine_km`/`papelito_normalize_cep` ([vendor_geo.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_geo.php)), parsers ([rest_api.php](../public_html/wp-content/plugins/plugin_papelito/includes/rest_api.php), [shipping.php](../public_html/wp-content/plugins/plugin_papelito/includes/shipping.php)), normalizadores ([flash_sale.php](../public_html/wp-content/plugins/plugin_papelito/includes/flash_sale.php), [home_assets.php](../public_html/wp-content/plugins/plugin_papelito/includes/home_assets.php), [coupons.php](../public_html/wp-content/plugins/plugin_papelito/includes/coupons.php), [admin_reports.php](../public_html/wp-content/plugins/plugin_papelito/includes/admin_reports.php)), mappers.
- **Tipos de teste:** PHPUnit puro + `@dataProvider`.
- **Regras de negócio:** §5 #1–5 + normalizadores/parsers.
- **Riscos:** esquecer fronteiras — telefone 10/11/12/13 dígitos com prefixo `55`; CPF inválido vs sequência repetida; CEP nos dois formatos; clamp de desconto; datetime de flash sale.
- **Resultado esperado:** base verde e rápida, cobertura alta nesses arquivos.

### Step 4 — Testes de cadastro & verificação de e-mail
- **Objetivo:** cobrir registro, criação de conta e o ciclo de verificação de e-mail.
- **Arquivos/áreas:** [auth_endpoints.php](../public_html/wp-content/plugins/plugin_papelito/includes/auth_endpoints.php) — `validate_register_payload`/`_seller_`, `create_registered_user`/`_seller`, token/expiry/rate limit, bloqueio de login.
- **Tipos de teste:** unit com Brain Monkey (mock de `wp_insert_user`, `is_email`, `update_user_meta`, **Clock injetado**) + integração WP para criação real de user/usermeta e o hook `wp_authenticate_user`.
- **Regras de negócio:** §5 #6–9.
- **Riscos:** rate limit usa transient (resetar entre testes); expiry/one-time precisam de relógio determinístico → extrair `Clock`.
- **Resultado esperado:** registro, status `pending`→`verified`, expiração de token e bloqueio de login cobertos.

### Step 5 — Testes do fluxo OAuth Google
- **Objetivo:** validar verificação do `id_token` e account linking.
- **Arquivos/áreas:** [auth_endpoints.php](../public_html/wp-content/plugins/plugin_papelito/includes/auth_endpoints.php) — `verify_google_id_token`, `find_or_create_google_user`.
- **Tipos de teste:** mock HTTP (filtro `pre_http_request` na integração ou stub de `wp_remote_get` no unit) com cenários 200 ok / `email_verified=false` / `aud` divergente / 4xx / timeout; integração para create com `profile_complete=0` e linking por `google_sub`.
- **Regras de negócio:** §5 #10–11, #27.
- **Riscos:** token forjado (validar `aud`); colisão e-mail vs `google_sub` no linking.
- **Resultado esperado:** verificação de token e criação/linking de usuário Google cobertas, incluindo erros `papelito_*`.

### Step 6 — Testes de cobertura por CEP
- **Objetivo:** validar a regra de faixa, a dupla aprovação e o cache.
- **Arquivos/áreas:** [rest_api.php](../public_html/wp-content/plugins/plugin_papelito/includes/rest_api.php) — `sellers_by_cep`, `coverage_vendors`, `coverage_products`; [products_filter.php](../public_html/wp-content/plugins/plugin_papelito/includes/products_filter.php) — `matching_vendor_ids`.
- **Tipos de teste:** unit para a regra de faixa (extrair `CoverageRangeMatcher` puro) + integração para query com vários sellers (usermeta `min_cep[]/max_cep[]`) e validação do cache versionado + invalidação.
- **Regras de negócio:** §5 #12–16.
- **Riscos:** invariante de performance (não voltar a loop produto-a-produto que geocodifica); transient de cache nos testes; serialização dos arrays de CEP.
- **Resultado esperado:** dentro/fora de cobertura, dupla aprovação, shape `has_coverage/best_vendor/alternatives` e invalidação de cache cobertos.

### Step 7 — Testes de estoque (vendor stock)
- **Objetivo:** validar UPSERT, log, transição zeroed e transação.
- **Arquivos/áreas:** [vendor_stock.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_stock.php) — `get`/`set`/`adjust_vendor_stock`, `vendors_with_stock`, `vendor_stock_query`.
- **Tipos de teste:** **integração** WP_UnitTestCase (banco real — o SQL é a regra); criar as tabelas via `papelito_vendor_stock_install_tables()` no `setUp`.
- **Regras de negócio:** §5 #17–20.
- **Riscos:** transação `FOR UPDATE` e concorrência; assert do disparo de `papelito_stock_zeroed` e `papelito_vendor_stock_changed` (registrar listener no teste); log com reason correta.
- **Resultado esperado:** estado de estoque, auditoria em log e actions de invalidação validados em banco real.

### Step 8 — Testes de vendor ativo & revendedor
- **Objetivo:** validar seleção/validação de vendor ativo e a aplicação de revendedor.
- **Arquivos/áreas:** [active_vendor.php](../public_html/wp-content/plugins/plugin_papelito/includes/active_vendor.php) — `validate_active_vendor`, `set_active_vendor`, `resolve_default_vendor_id`; [revendedor_application.php](../public_html/wp-content/plugins/plugin_papelito/includes/revendedor_application.php) — `validate_cpf`, `validate_vendor_pagarme_step3`, `validate_vendor_address_step2`, draft Pagar.me.
- **Tipos de teste:** unit (CPF e validações de step puras) + integração (usermeta, seleção de default lazy, action `papelito_active_vendor_changed`).
- **Regras de negócio:** §5 #21–23, #24–25 (dupla aprovação), #4.
- **Riscos:** `resolve_default_vendor_id` ordena por distância → depende de geo (mockar `Clock`/geo); persistência do draft Pagar.me em usermeta.
- **Resultado esperado:** validação/seleção de vendor ativo e validações da aplicação de revendedor cobertas.

### Step 9 — Testes de notificações, mensagens, geocodificação & REST
- **Objetivo:** cobrir CRUD de notificações/mensagens, o fallback de geo e o contrato dos endpoints.
- **Arquivos/áreas:** [notifications.php](../public_html/wp-content/plugins/plugin_papelito/includes/notifications.php), [vendor_messaging.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_messaging.php), [vendor_geo.php](../public_html/wp-content/plugins/plugin_papelito/includes/vendor_geo.php), endpoints registrados em [rest_api.php](../public_html/wp-content/plugins/plugin_papelito/includes/rest_api.php) e [auth_endpoints.php](../public_html/wp-content/plugins/plugin_papelito/includes/auth_endpoints.php).
- **Tipos de teste:** integração para CRUD (dispatch/unread count/read; thread/message/read); mock HTTP para geo fallback chain (BrasilAPI→ViaCEP→Nominatim) + cache transient; **smoke de endpoints** via `WP_REST_Request`/`rest_do_request` (status, `permission_callback`, shape JSON, `sanitize_callback`/`validate_callback` dos args).
- **Regras de negócio:** notificações (decrementa só se não lida), geo (fallback + cache + falha silenciosa), §5 #27 nos endpoints.
- **Riscos:** transient de cache nos testes; `permission_callback` por capability/role; rate limit em endpoints públicos.
- **Resultado esperado:** CRUD, geo e contratos REST cobertos sem HTTP real.

### Step 10 — Padronização, cobertura, CI e documentação
- **Objetivo:** consolidar a suíte como barreira anti-regressão estável.
- **Arquivos/áreas:** [composer.json](../public_html/wp-content/plugins/plugin_papelito/composer.json), `phpunit.xml.dist`, CI ([.github/workflows/lint.yml](../.github/workflows/lint.yml)), doc curta em `docs/`.
- **Tipos de teste:** revisão da suíte (nomes, AAA, sem flaky); metas de cobertura por camada (puras alta; integração foco em regra, não em %).
- **Regras de negócio:** garantir que todas da §5 têm teste.
- **Riscos:** integração pesa no CI → manter `test:unit` no fluxo rápido (sempre) e `test:integration` em job separado com serviço de DB.
- **Resultado esperado:** `test:unit` obrigatório no PR, `test:integration` no CI com banco, doc "como testar" em `docs/`, PHPCS verde.

---

## 9. Estratégia de mocks

### HTTP externo
- **Unit:** Brain Monkey stub de `wp_remote_get`/`wp_safe_remote_get` (Google tokeninfo; BrasilAPI/ViaCEP/Nominatim).
- **Integração:** filtro `pre_http_request` retornando respostas canned.
- **Cenários:** 200 ok, 4xx, timeout/`WP_Error`, JSON malformado, e — na geo — sucesso só no 2º/3º provider da chain.

### WP core (suíte unit)
- Brain Monkey `Functions\when()/expect()` para `get_user_meta`, `update_user_meta`, `wp_insert_user`, `is_email`, `get_transient`/`set_transient`, `sanitize_*`, `__()`, `wp_json_encode`.

### Banco (suíte integração)
- WP_UnitTestCase + factories: `$this->factory->user->create(...)` + `update_user_meta()` para montar seller (faixas `min_cep[]`/`max_cep[]`, `application_status`) e customer (`cep`, `papelito_email_verification_status`).
- Criar tabelas custom via as `*_install_tables()` do plugin no `setUp`; truncar/rollback no `tearDown`.

### Relógio / aleatório / JWT
- Extrair `interface Clock` (substitui `time()`/`current_time`) para testar expiry (24h), rate limit (10/min) e cooldown de forma determinística.
- Token de verificação: injetar gerador para asserir o hash sha256 e o one-time use.
- JWT: stubar `WPGraphQL\JWT_Authentication\Auth::get_token`/`get_refresh_token` (não testamos o plugin de terceiros).

### Regra de ouro
Mockar **somente a fronteira** (HTTP externo, relógio, JWT, e — no unit — o WP). A regra de negócio nunca é mockada: é o que está sob teste.

---

## 10. Critérios de aceite

- ✅ `composer test:unit` e `composer test:integration` passam localmente (Docker) e no CI (PHP 8.3).
- ✅ Toda regra de negócio da §5 tem ≥1 teste cobrindo caminho feliz **e** borda/erro, incluindo os códigos `WP_Error` `papelito_*` (asserindo `get_error_code()` e `status`).
- ✅ Funções puras com cobertura alta; estoque/coverage/auth cobertos por **integração real**; cada endpoint REST com smoke (status + `permission_callback` + shape).
- ✅ Nenhum teste bate em HTTP externo real; banco de integração isolado (não toca o banco de dev).
- ✅ **PHPCS continua verde**; refatorações preservam os contratos REST/GraphQL (endpoints, shapes e códigos de erro inalterados).
- ✅ Documento curto de "como rodar e escrever testes" disponível em `docs/`.
- ✅ `test:unit` é barreira obrigatória de merge no CI.

---

## 11. Riscos e pontos de atenção

| Risco | Mitigação |
|---|---|
| **Refatoração procedural → classes** pode quebrar contratos | Incremental, por bounded context; cobrir com teste **antes** de mover lógica; manter `papelito_*` como adapter; nunca mudar endpoint/shape/erro. |
| **Suíte de integração** exige `install-wp-tests` + MariaDB (CI pesado) | Separar de `test:unit`; rodar integração em job próprio com serviço de DB; unit é o gate rápido. |
| **Cache transient e rate limit** geram flakiness/estado | Resetar transients no `tearDown`; injetar `Clock` para tempo determinístico. |
| **`min_cep[]`/`max_cep[]` serializados** em usermeta | Montar via `update_user_meta` nas factories; testar a desserialização. |
| **Actions de efeito colateral** (`papelito_stock_zeroed`, `papelito_vendor_stock_changed`, `papelito_active_vendor_changed`) | Registrar listener no teste e asserir disparo + invalidação de cobertura. |
| **`place-order` / webhook / cron (Pagar.me) tocam Woo + estoque + API externa** | Mockar cliente Pagar.me e estoque; cobrir sucesso, falha antes/depois de reserva, rollback parcial, webhook por `charge_id` e reconciliação de reservas. |
| **Não editar core/terceiros** | Toda lógica e teste vivem em `plugin_papelito`; JWT/WPGraphQL são stubados, não testados. |
| **Funções "puras" que chamam WP** (ex.: `validate_register_payload` usa `is_email`) | Tratá-las como camada mockada (Brain Monkey), não como pura. |

---

## 12. Próximos passos

1. **Ordem de implementação:** Step 2 → Step 3 (infra + funções puras = ROI rápido), depois Steps 4–9 por bounded context, fechando com Step 10.
2. **Refatorar para serviços** apenas conforme cada contexto é coberto por testes — nunca antes.
3. **Integração GraphQL** (futuro): testar `login`/`refreshJwtAuthToken` e queries WooGraphQL relevantes ponta-a-ponta.
4. **Fluxo Pagar.me**: cobrir dupla aprovação (regional + recebedor `active`), split PSP de recebedor único dentro de `payments[]`, reserva/liberação de estoque, webhooks `order.*`/`charge.*` e cron de reconciliação.
5. **PHPStan** (opcional): análise estática como reforço, alinhada ao PHP 8.3.
6. Manter este documento e o irmão do front ([../papelito-web/docs/unit-tests-front-plan.md](../../papelito-web/docs/unit-tests-front-plan.md)) em sincronia quando regras que cruzam os dois repos mudarem.
