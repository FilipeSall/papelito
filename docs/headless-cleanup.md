# Headless Cleanup

Itens que deixaram de fazer parte do pipeline headless e estão preparados para remoção futura.

## Observação

O WordPress continua exigindo um tema ativo no admin. Antes de excluir o tema legado, troque o site para um tema mínimo de fallback e valide `/wp-admin`.

## Fora do pipeline

- Tema `jupiterx-child`
- Plugin `jupiterx-core`
- Plugin `elementor`
- Plugin `elementor-pro`
- Plugin `dynamic-visibility-for-elementor`
- Plugins `jet-*`
- Plugin `child-theme-wizard`
- Plugins `sellkit` e `sellkit-pro`

## Critério para exclusão

- Frontend público 100% servido pelo `papelito-web`
- Admin e fluxos internos validados sem Elementor/Jupiter
- Tema alternativo ativo no WordPress
- Nenhuma página crítica dependendo de shortcodes/widgets da stack antiga
