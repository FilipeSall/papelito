# Testes do backend

## Como os testes realmente são

**Não existe harness PHPUnit no `plugin_papelito`.** Não há `phpunit.xml`, nem `require-dev`, nem `bin/install-wp-tests.sh`, nem PSR-4 de teste. Quem procurar por isso não vai achar.

O que existe: **109 scripts PHP standalone** em `public_html/wp-content/plugins/plugin_papelito/tests/`, mais um na raiz do repositório (`tests/test-company-purchase-gate.php`). Cada script:

- declara `ABSPATH` por conta própria;
- stuba inline as funções do WordPress que o código sob teste chama (`add_filter`, `register_rest_route`, `get_user_meta`, ...);
- exercita a função real;
- conta asserções e falha com saída não zero.

Execução direta:

```bash
php public_html/wp-content/plugins/plugin_papelito/tests/test-cnpj-validation.php
php public_html/wp-content/plugins/plugin_papelito/tests/test-company-authz-matrix.php
```

Vantagem: roda sem subir WordPress, sem banco, sem dependência nova de produção. Limitação: cobre bem regra pura e razoavelmente bem regra de coordenação; **não** cobre SQL real nem interação com o WooCommerce.

> **O CI não roda testes.** `.github/workflows/lint.yml` executa apenas `composer phpcs`. Rodar as suítes é responsabilidade de quem abre o PR.

## Onde está a cobertura

| Área | Suítes |
|---|---|
| Documentos e cripto | `test-cnpj-validation.php`, `test-vendor-cnpj-validation.php` (**estrutural**: nenhum gate do fluxo de vendor pode validar CNPJ só pela máscara), `test-customer-identity-crypto.php`, `test-cnpj-providers.php` |
| Arquivo privado | `test-private-files.php` (mecanismo genérico), `test-company-owner-document-validation.php` (spec da candidatura) |
| Empresa / B2B | `test-company-authz-matrix.php`, `test-company-active-context.php`, `test-company-invitations.php`, `test-company-idempotency.php`, `test-company-ownership-transfer.php`, `test-company-onboarding.php`, `tests/test-company-purchase-gate.php` (raiz) |
| Legados | `test-legacy-migration.php` |
| Pagamento | `test-pagarme-*.php`, incluindo `test-pagarme-simulator.php` |
| Correios | `test-correios-prepostage.php`, `test-correios-idempotency.php`, `test-correios-reconciliation.php`, `test-correios-tracking-map.php` |
| Pedido | `test-order-receipt-pdf.php`, `test-receipts-snapshot.php`, `test-receipts-backfill.php`, `test-receipt-email-attachment.php` (**estrutural**: o envio do recibo roda em REST e não pode chamar função que só existe em `wp-admin/includes/file.php`) |
| Analytics | `test-analytics-ga4.php` (validação estrita dos ids do navegador, payload do Measurement Protocol, idempotência do webhook reemitido) |
| Documento fiscal | `test-fiscal-documents.php` (política do arquivo), `test-fiscal-documents-db.php` (substituição, remoção e varredura — `wp eval-file`) |
| Administração | `test-admin-activate-email.php` |
| E-mail de faturamento | `test-billing-email-rules.php` (tabela de decisão), `test-billing-email-sync.php` (cascata e backfill), `test-billing-email-token.php`, `test-pre-account-email-verification.php` (**estrutural**: nenhum `wp_insert_user()` pode ficar sem gravar o estado de verificação) |
| Links de e-mail | `test-frontend-base-url.php` (allowlist, nunca `localhost` em ambiente remoto, `Origin` não é fallback) |
| Upload direto | `test-direct-uploads.php` (tíquete single-use, claim atômico, contexto sem token cru), `test-direct-upload-image.php` (assinatura, divergência conteúdo × extensão, truncamento) |
| Rate limit | `test-rate-limit-identity.php` (endpoint atrás do proxy não pode compartilhar balde por IP) |
| Faixas da Home | `test-home-assets-rich-text.php` (whitelist de formatos e tokens, referência de produto sem snapshot, compat com texto puro), `test-home-assets-free-shipping-placeholder.php` (migração dos textos legados) |
| Frete grátis | `test-shipping-free-shipping-threshold.php` (default, persistência, validação, autorização) |
| Benefícios do produto | `test-product-benefits.php` (validação e precedência), `test-product-benefits-db.php` (**WP-CLI**: schema, índices e writers) |
| Campanha relâmpago | `test-flash-sale-home-response.php` (**estrutural**: estado vazio sai como `200` com `campaign: null` — um `404` não substitui a campanha cacheada no Data Cache do Next e a vitrine congela no preço promocional), `test-flash-sale-extreme-discount.php`, `test-flash-sale-products.php`, `test-flash-sale-promotion-context.php`, `test-catalog-search-campaign-price.php` |

As invariantes que essas suítes protegem estão catalogadas em [context/business-rules.md](business-rules.md).

### Testes que precisam de banco (WP-CLI)

 Onde o SQL **é** a regra de negócio, o script standalone não serve. `test-receipts-sequence-db.php`, `test-receipts-backfill-db.php` e `test-product-benefits-db.php` rodam por WP-CLI, contra o banco local, e se limpam sozinhos (criam pedidos descartáveis, apagam recibos e pedidos no fim). Eles ficam no mesmo diretório dos demais, mas **não rodam com `php` direto** — o guard de `ABSPATH` avisa.

```bash
docker compose exec web wp --allow-root eval-file \
  wp-content/plugins/plugin_papelito/tests/test-receipts-backfill-db.php
```

`test-receipts-backfill-db.php` cobre o que só o SQL garante: ordenação por data de pagamento (cria os pedidos fora de ordem de propósito), exclusão de pedido não pago e de quem já tem recibo, dois runs sem duplicar nem consumir número, e que o backfill não encosta em status nem total do pedido.

`test-fiscal-documents-db.php` cobre a semântica de NULL nos índices únicos: uma corrente por `(pedido, vendor)` com N históricos, um arquivo ativo por papel, e chave de acesso duplicada aceita pelo banco.

`test-product-taxonomy-integrity-db.php` cobre o recorte de `papelito_category_integrity_report()`: produto publicado sem categoria é sinalizado, rascunho e produto classificado não são, e **o produto comercial de um Kit não entra em `publishedWithoutCategory`** — ele é `catalog_visibility=hidden` e nunca recebe categoria, então contá-lo virava alerta que ninguém conseguia resolver pela aba Produtos, que exclui kits.

> **Armadilha do `wp eval-file`**: o arquivo roda **dentro de uma função**, então variável de topo **não é global**. Um `$failures = 0;` no topo com `global $failures;` dentro do assert cria duas variáveis diferentes — o teste imprime `FAIL` e mesmo assim sai com código 0. Os três testes de banco declaram `global $wpdb, $failures;` no topo por isso. Ao criar um teste novo nesse formato, verifique o exit code injetando uma falha proposital.

### Nota fiscal: o teste de banco também toca o disco

`test-fiscal-documents-db.php` grava e apaga arquivos de verdade no diretório privado, porque é
justamente a ordem entre a transação e o `unlink` que o teste protege — substituir tem de apagar o
arquivo anterior **depois** do commit, e nunca antes. Ele também exercita a varredura de órfãos
mexendo no `mtime` dos arquivos para atravessar a janela de `PAPELITO_FISCAL_SWEEP_MIN_AGE`:

```bash
docker compose exec web wp --allow-root eval-file \
  wp-content/plugins/plugin_papelito/tests/test-fiscal-documents-db.php
``` Ele filtra os candidatos antes de emitir, portanto não altera pedidos que não sejam fixtures do teste.

```bash
docker compose exec web wp --allow-root eval-file \
  wp-content/plugins/plugin_papelito/tests/test-receipts-sequence-db.php idempotency
```

Concorrência de verdade exige processos paralelos. O modo `claim` aloca um sequencial por invocação, no ano reservado `2999`; `report` confere quantos foram consumidos e `reset` limpa:

```bash
docker compose exec web sh -c '
cd /var/www/html
T=wp-content/plugins/plugin_papelito/tests/test-receipts-sequence-db.php
wp --allow-root eval-file $T reset
for i in $(seq 1 50); do ( wp --allow-root eval-file $T claim >> /tmp/claims.txt ) & done; wait
sort -u /tmp/claims.txt | wc -l          # tem de ser 50
wp --allow-root eval-file $T report 50
'
```

> O ano `2999` é reservado para teste justamente para nunca colidir com a numeração real. **Não aponte esses modos para o ano corrente.**

## Ao escrever um teste novo

1. Siga o padrão do arquivo vizinho — stub explícito, sem framework.
2. **Mocke somente a fronteira**: HTTP externo, relógio, JWT e, no unitário, o WordPress. **A regra de negócio nunca é mockada.**
3. **Não mocke `$wpdb` onde o SQL é a regra de negócio** (UPSERT de estoque, `FOR UPDATE`, JOIN de `users`⋈`usermeta`, `min_cep[]` serializado). Nesses casos, teste contra um banco descartável ou aceite que a garantia real é a reserva transacional.
4. Na direção oposta, não suba o WordPress inteiro para testar `papelito_validate_cpf()`.
5. Resete transients no teardown: rate limits e caches são baseados em transient e vazam entre casos.
6. `min_cep[]` / `max_cep[]` são **serializados** em usermeta — a fixture precisa passar por `update_user_meta`.
7. Se a mudança altera contrato consumido pelo frontend, o teste correspondente do lado do Next também é parte da entrega. O catálogo de erros dos Correios, por exemplo, tem **teste de contrato que compara PHP e TypeScript**.

## PHPCS

```bash
docker compose --profile quality run --rm phpcs
docker compose --profile quality run --rm phpcs --report=summary
```

O serviço `phpcs` usa PHP 8.3 com `SimpleXML` e `xmlwriter`, monta a raiz do repositório e instala as dependências Composer apenas se `vendor/bin/phpcs` ainda não existir. Ele não inicia WordPress, banco ou portas e não depende das extensões do PHP CLI do host.

Em Linux, se seu usuário não tiver UID/GID `1000`, preserve a propriedade dos arquivos criados pelo Composer com:

```bash
LOCAL_UID=$(id -u) LOCAL_GID=$(id -g) docker compose --profile quality run --rm phpcs
```

### Baseline de PHPCS

O ruleset é `phpcs.xml.dist` (WordPress coding standards). Tudo que é auto-corrigível e todas as regras que o codebase mantém limpas — alinhamento, block comments, `$wpdb->prepare`, Yoda, base64 — devem continuar limpas.

**As únicas violações aceitas são:**

- `Squiz.Commenting.FunctionComment.*` (`@param` / typehint);
- `WordPress.Files.FileName.NotHyphenatedLowercase`;
- `WordPress.Files.FileName.InvalidClassFileName` + `Universal.Files.SeparateFunctionsFromOO.Mixed`, **apenas** em arquivo que junta funções e uma classe `*_CLI` de WP-CLI no mesmo módulo — o padrão já em produção em `receipts_backfill.php` e seguido por `billing_email_sync.php`. O nome da classe é o binding de `WP_CLI::add_command()`: renomear quebra o comando.

Elas são aceitas porque são **as mesmas categorias já presentes nos arquivos em produção** (`vendor_stock.php`, `pagarme_payments.php`, `order_routing.php`): o plugin usa nomes de arquivo com underscore e docblocks enxutos por convenção. Consistência com o código vizinho vence a regra genérica. O `base64` do envelope de criptografia tem `phpcs:ignore` justificado.

Se o seu PR introduzir violação de outra categoria, corrija — não amplie o baseline.

### SonarLint no editor

O SonarLint usa regras genéricas de PHP que colidem de frente com o WordPress coding standard exigido pelo `phpcs.xml.dist`. Essas regras ficam desligadas na chave `sonarlint.rules`, que tem scope *application*: o VS Code a ignora em settings de workspace, então o bloco vive em `~/.config/Code/User/settings.json` — os `.vscode/settings.json` do repo e do workspace pai só registram esse fato. As desligadas são:

- `php:S105` (tabs) — o WP padroniza indentação com tab, e o PHPCS reprova espaços;
- `php:S100` (nome de função em camelCase) — o plugin usa `snake_case` com prefixo `papelito_`, e os nomes são contrato de hooks/testes;
- `php:S101` (nome de classe em PascalCase) — os testes standalone stubam classes do core (`WP_Error`, `WP_REST_Response`, `WP_REST_Request`, `WP_REST_Server`) e o nome é contrato de `new`/`instanceof` do código sob teste: renomear quebra o teste;
- `php:S1172` (parâmetro não usado) — callback de filtro recebe argumentos por posição (`rest_pre_dispatch`, `wp_check_filetype_and_ext`), então parâmetros no meio da assinatura não podem ser removidos;
- `php:S1142` (mais de 3 `return`) — desligada no editor, mas a preferência do código segue sendo consolidar o retorno ou extrair helper.

As demais regras ficam ligadas e devem ser corrigidas no código — inclusive `php:S1192` (literal repetido → constante `PAPELITO_*`), `php:S1784` (visibilidade explícita em método) e `php:S2003` (`require` → `require_once`). Em stub de teste, tipar os parâmetros (`mixed` onde a função do core aceita qualquer coisa) também mata os hints `P1132` do intelephense.

## Verificação de uma mudança

```bash
php -l <arquivos alterados>
php public_html/wp-content/plugins/plugin_papelito/tests/test-<relevante>.php
docker compose --profile quality run --rm phpcs
```

Quando a mudança cruza os dois repositórios, rode também `bun run lint`, `tsc --noEmit`, `bun run test` e `bun run build` no `papelito-web`.

## Pendências

- CI não executa nenhuma suíte — só lint. Enquanto isso não mudar, regressão de backend só é pega manualmente.
- Não há cobertura automatizada de SQL real (estoque sob concorrência, JOINs de cobertura).
- PHPCS não roda no ambiente local padrão por falta de extensões PHP.
