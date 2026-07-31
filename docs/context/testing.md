# Testes do backend

## Como os testes realmente são

**Não existe harness PHPUnit no `plugin_papelito`.** Não há `phpunit.xml`, nem `require-dev`, nem `bin/install-wp-tests.sh`, nem PSR-4 de teste. Quem procurar por isso não vai achar.

O que existe: **52 scripts PHP standalone** em `public_html/wp-content/plugins/plugin_papelito/tests/`, mais um na raiz do repositório (`tests/test-company-purchase-gate.php`). Cada script:

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
| Documentos e cripto | `test-cnpj-validation.php`, `test-customer-identity-crypto.php`, `test-cnpj-providers.php` |
| Arquivo privado | `test-private-files.php` (mecanismo genérico), `test-company-owner-document-validation.php` (spec da candidatura) |
| Empresa / B2B | `test-company-authz-matrix.php`, `test-company-active-context.php`, `test-company-invitations.php`, `test-company-idempotency.php`, `test-company-ownership-transfer.php`, `test-company-onboarding.php`, `tests/test-company-purchase-gate.php` (raiz) |
| Legados | `test-legacy-migration.php` |
| Pagamento | `test-pagarme-*.php`, incluindo `test-pagarme-simulator.php` |
| Correios | `test-correios-prepostage.php`, `test-correios-idempotency.php`, `test-correios-reconciliation.php`, `test-correios-tracking-map.php` |
| Pedido | `test-order-receipt-pdf.php`, `test-receipts-snapshot.php` |
| Administração | `test-admin-activate-email.php` |

As invariantes que essas suítes protegem estão catalogadas em [context/business-rules.md](business-rules.md).

### Testes que precisam de banco (WP-CLI)

Onde o SQL **é** a regra de negócio, o script standalone não serve. `test-receipts-sequence-db.php` roda por WP-CLI, contra o banco local, e se limpa sozinho (cria pedido descartável, apaga o recibo e o pedido no fim). Ele fica no mesmo diretório dos demais, mas **não roda com `php` direto** — o guard de `ABSPATH` avisa.

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
composer phpcs
./vendor/bin/phpcs --report=summary
```

O PHP CLI do host normalmente **não tem `SimpleXML` e `xmlwriter`**, e o PHPCS não roda sem eles. Rode via container (`php:8.2-cli` serve) ou dentro do serviço `web`.

### Baseline de PHPCS

O ruleset é `phpcs.xml.dist` (WordPress coding standards). Tudo que é auto-corrigível e todas as regras que o codebase mantém limpas — alinhamento, block comments, `$wpdb->prepare`, Yoda, base64 — devem continuar limpas.

**As únicas violações aceitas são:**

- `Squiz.Commenting.FunctionComment.*` (`@param` / typehint);
- `WordPress.Files.FileName.NotHyphenatedLowercase`.

Elas são aceitas porque são **as mesmas categorias já presentes nos arquivos em produção** (`vendor_stock.php`, `pagarme_payments.php`, `order_routing.php`): o plugin usa nomes de arquivo com underscore e docblocks enxutos por convenção. Consistência com o código vizinho vence a regra genérica. O `base64` do envelope de criptografia tem `phpcs:ignore` justificado.

Se o seu PR introduzir violação de outra categoria, corrija — não amplie o baseline.

## Verificação de uma mudança

```bash
php -l <arquivos alterados>
php public_html/wp-content/plugins/plugin_papelito/tests/test-<relevante>.php
composer phpcs
```

Quando a mudança cruza os dois repositórios, rode também `bun run lint`, `tsc --noEmit`, `bun run test` e `bun run build` no `papelito-web`.

## Pendências

- CI não executa nenhuma suíte — só lint. Enquanto isso não mudar, regressão de backend só é pega manualmente.
- Não há cobertura automatizada de SQL real (estoque sob concorrência, JOINs de cobertura).
- PHPCS não roda no ambiente local padrão por falta de extensões PHP.
