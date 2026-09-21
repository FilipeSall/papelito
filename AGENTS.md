# Codex Context — papelito-wordpress

**As instruções deste repositório vivem em [CLAUDE.md](CLAUDE.md)** — stack, invariantes e convenções. Leia-o primeiro; este arquivo não duplica o conteúdo.

Documentação:

- [docs/README.md](docs/README.md) — índice do backend, incluindo os runbooks de operação.
- [`../docs/README.md`](../docs/README.md) — contexto compartilhado com o frontend: negócio, contratos REST/GraphQL e fluxos ponta a ponta.

O frontend fica no repositório irmão `../papelito-web`. Mudança que cruza os dois exige PR nos dois, na mesma branch nominal.

## Validação esperada

```bash
php -l <arquivos alterados>
docker compose --profile quality run --rm phpcs
php public_html/wp-content/plugins/plugin_papelito/tests/test-<relevante>.php
```

Lint de editor conta como validação: SonarLint e intelephense analisam os testes standalone junto com o plugin, e stub sem tipo, de corpo vazio ou com literal de fixture repetido vira warning. A forma canônica do bloco de stubs está em [docs/context/testing.md](docs/context/testing.md#stub-que-nasce-limpo-no-editor) — a saída nunca é escrever comentário.

**Teste novo começa pela forma canônica, não pelo arquivo vizinho**: a maioria dos 185 scripts de `tests/` é anterior à regra, e copiar um deles reintroduz `php:S1186`, `php:S2003` e `P1132` em bloco. Campo novo em array alinhado exige realinhar o array inteiro, senão o PHPCS do arquivo de produção sobe sozinho.

**Arquivo e função exportada nascem com docblock PHPDoc** — o topo do arquivo diz o que o módulo governa e o que ele deliberadamente não faz; a função diz o que devolve e a armadilha de quem chamar. Forma canônica em [CLAUDE.md § Documentação no código](CLAUDE.md#documentação-no-código-phpdoc); [`account_status.php`](public_html/wp-content/plugins/plugin_papelito/includes/account_status.php) é o exemplo. Comentário solto dentro da função continua fora.

O CI roda **apenas PHPCS** — nenhuma suíte de teste. Rodar as suítes standalone é responsabilidade de quem abre o PR. Ver [docs/context/testing.md](docs/context/testing.md), incluindo o baseline aceito de PHPCS.

Mudança no fluxo de disponibilidade regional: testar `/coverage/products` com 1, 10 e 40 produtos e conferir as invariantes em [docs/context/business-rules.md](docs/context/business-rules.md#cobertura-regional).
