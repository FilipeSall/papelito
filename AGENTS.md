# Codex Context — papelito-wordpress

**As instruções deste repositório vivem em [CLAUDE.md](CLAUDE.md)** — stack, invariantes e convenções. Leia-o primeiro; este arquivo não duplica o conteúdo.

Documentação:

- [docs/README.md](docs/README.md) — índice do backend, incluindo os runbooks de operação.
- [`../docs/README.md`](../docs/README.md) — contexto compartilhado com o frontend: negócio, contratos REST/GraphQL e fluxos ponta a ponta.

O frontend fica no repositório irmão `../papelito-web`. Mudança que cruza os dois exige PR nos dois, na mesma branch nominal.

## Validação esperada

```bash
php -l <arquivos alterados>
composer phpcs
php public_html/wp-content/plugins/plugin_papelito/tests/test-<relevante>.php
```

O CI roda **apenas PHPCS** — nenhuma suíte de teste. Rodar as suítes standalone é responsabilidade de quem abre o PR. Ver [docs/context/testing.md](docs/context/testing.md), incluindo o baseline aceito de PHPCS.

Mudança no fluxo de disponibilidade regional: testar `/coverage/products` com 1, 10 e 40 produtos e conferir as invariantes em [docs/context/business-rules.md](docs/context/business-rules.md#cobertura-regional).
