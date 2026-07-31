# Remoção da stack legada

Itens que deixaram de fazer parte do pipeline headless e estão preparados para remoção futura. Eles continuam no repositório e no servidor: **ainda não foram removidos.**

## A armadilha que justifica este documento

**O WordPress continua exigindo um tema ativo no admin.** Antes de excluir o tema legado, troque o site para um tema mínimo de fallback e valide `/wp-admin`. Apagar o tema ativo derruba o painel.

## Fora do pipeline

- Tema `jupiterx-child` (e o tema pai `jupiterx`)
- Plugin `jupiterx-core`
- Plugin `elementor`
- Plugin `elementor-pro`
- Plugin `dynamic-visibility-for-elementor`
- Plugins `jet-*`
- Plugin `child-theme-wizard`
- Plugins `sellkit` e `sellkit-pro`

Também fora do fluxo, por outro motivo: `pagarme-payments-for-woocommerce` — **deve ficar desativado**, porque a integração de pagamento é feita pelo `plugin_papelito` e dois gateways ativos conflitam. E `woocommerce-correios` (Claudio Sanches 4.2.5), substituído pela integração própria.

## Critério para exclusão

Todos precisam estar satisfeitos:

- frontend público 100% servido pelo `papelito-web`;
- admin e fluxos internos validados sem Elementor/Jupiter;
- **tema alternativo ativo** no WordPress;
- nenhuma página crítica dependendo de shortcodes ou widgets da stack antiga.

## O que o repositório versiona desse legado

`public_html/wp-content/themes/jupiterx-child/` está na allowlist do `.gitignore` e é sincronizado pelo deploy. O Git aninhado que existia dentro dele é preservado apenas como backup local em `.vendor-git-backups/` — **não** é incorporado ao repositório principal.

Se aparecer customização em plugin premium ou no tema pai, a migração para o child theme ou para o `plugin_papelito` precisa acontecer **antes** de atualizar o terceiro — do contrário a atualização apaga a customização.
