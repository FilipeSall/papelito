# Documentação — papelito-wordpress

Contexto **específico do backend**. Tudo que é compartilhado com o frontend (modelo de negócio, contratos REST/GraphQL, fluxos ponta a ponta) vive em [`../../docs/`](../../docs/README.md) e **não é duplicado aqui**.

## Ordem recomendada de leitura

1. **[`../../docs/system-overview.md`](../../docs/system-overview.md)** — entenda o marketplace antes de abrir o plugin.
2. **[context/architecture.md](context/architecture.md)** — mapa dos 52 módulos de `includes/` por domínio, mu-plugins, barramento de eventos e convenções PHP.
3. **[context/data-model.md](context/data-model.md)** — tabelas customizadas, usermeta, postmeta, criptografia de PII e estratégia de migração de schema.
4. **[context/business-rules.md](context/business-rules.md)** — invariantes do backend com nome de função, do jeito que estão implementadas.
5. **[context/local-environment.md](context/local-environment.md)** — Docker, Mailpit, phpMyAdmin, flags B2B e solução de problemas.
6. **[context/testing.md](context/testing.md)** — as suítes standalone e o baseline de PHPCS.

## Referência pontual

| Documento | Conteúdo |
|---|---|
| [context/correios-integration.md](context/correios-integration.md) | contrato do adapter, modos de pré-postagem, polling, mapa de eventos, credenciais |
| [context/legacy-stack-removal.md](context/legacy-stack-removal.md) | o que ficou fora do pipeline headless e o critério para excluir |

## Operações

| Runbook | Quando usar |
|---|---|
| [operations/deploy.md](operations/deploy.md) | subir código, fazer rollback, hotfix urgente, inventário de secrets do CI |
| [operations/sync-from-prod.md](operations/sync-from-prod.md) | alguém editou arquivo direto no servidor e é preciso reconciliar com o repositório |
| [operations/incident.md](operations/incident.md) | suspeita de comprometimento |
| [operations/pagarme-environment.md](operations/pagarme-environment.md) | configurar credenciais da Pagar.me por ambiente e simular webhook localmente |
| [operations/correios-diagnostics.md](operations/correios-diagnostics.md) | verificar uma chave CWS com segurança, e o estado da investigação de pré-postagem |

## Documentação colocada junto ao código

| Caminho | Conteúdo |
|---|---|
| [`../public_html/wp-content/mu-plugins/README.md`](../public_html/wp-content/mu-plugins/README.md) | inventário dos mu-plugins e quais são nossos |
| [`../scripts/catalog/README.md`](../scripts/catalog/README.md) | runbook da importação de catálogo por planilha |

> `mu-plugins/README.md` é sincronizado pelo `scripts/deploy-mu-plugins.sh` (`--include='README.md'`). **Não mova esse arquivo.**

## Onde procurar o que não está aqui

| Pergunta | Documento |
|---|---|
| Que rotas REST existem? | [`../../docs/integration-contracts.md`](../../docs/integration-contracts.md) |
| Quem pode comprar e por quê? | [`../../docs/business-rules.md`](../../docs/business-rules.md) |
| Como o fluxo X funciona ponta a ponta? | [`../../docs/flows/`](../../docs/README.md#fluxos) |
| Como subo os dois lados e abro um PR? | [`../../docs/development.md`](../../docs/development.md) |
| Por que a autorização não é uma pilha de camadas? | [`../../docs/architecture.md`](../../docs/architecture.md#autoridade-e-confiança) |
